# Relatório de Auditoria Técnica

## 1. Objetivo

Esta auditoria revisou o LeadCRM, API Laravel 9 usada pelo time comercial para consultar e atualizar leads importados de um CRM central. O código recebido no commit `165c2ed` foi comparado com as correções funcionais até `873c529`. O commit `0caeee8` apenas formata arquivos já alterados nessa auditoria. O trabalho seguinte na branch `melhorias-auditoria`, até `c44c193`, permanece no Laravel 9.52.22 exigido pelo desafio.

O foco foi segurança da escrita, integridade dos dados gravados, validação de entrada, qualidade estática e manutenção. A leitura das rotas, o contrato descrito no `README.md` e o comportamento da sincronização foram usados para separar defeito confirmado no código de decisão que depende de regra de negócio ou de ambiente.

## 2. Problemas identificados

### API Key permissiva

Em `app/Http/Middleware/CheckApiKey.php`, a comparação original era `$request->header('X-Api-Key') != config('app.api_key')`. Com `APP_API_KEY` ausente ou vazia, o PHP trata `null` e `''` como iguais nessa comparação. Uma requisição sem header, ou com header vazio, passava pelo middleware e alcançava `POST`, `PUT` e `DELETE /api/leads`.

O efeito estava no código: a escrita deixava de exigir segredo quando a configuração não tinha valor. O `.env.example` deixa `APP_API_KEY` vazio. Se o ambiente real estiver preenchido, esse desvio não ocorre. Foi corrigido em `09c36ae`.

### Busca com `OR` sem agrupamento

Em `LeadController::index`, os `orWhere` de nome, e-mail e empresa não estavam dentro de um grupo. Com `status` ou `source` na mesma query, o SQL permitia que um `OR` ignorasse o filtro anterior. Uma busca curta podia devolver leads de outros status ou de outra origem.

Foi corrigido em `72b0b9f`. O resumo do funil continua contando a tabela inteira, sem aplicar os filtros da listagem.

### Atribuição em massa na criação e na atualização

`Lead` usa `$guarded = []`. `store` fazia `Lead::create($request->all())` e `update` fazia `$lead->update($request->all())`. O corpo podia incluir `external_id`, `raw_payload` e `synced_at`, colunas de sincronização que não fazem parte do contrato de escrita da API.

Foi corrigido em `873c529` na borda do controller. O model continua sem lista de atributos protegidos; outro ponto que chame `create` ou `update` com array não filtrado ainda pode gravar essas colunas.

### E-mail único na atualização

A regra era `unique:leads,email`, sem ignorar o id do registro. Reenviar o e-mail já gravado naquele lead produzia erro de validação, mesmo sem troca de e-mail.

Foi corrigido em `873c529`. A migration `2023_03_14_181502_create_leads_table` só indexa `email`; a unicidade continua apenas na validação da aplicação.

### Edição de lead finalizado

A condição original comparava `$lead->status != $request->status` mesmo quando `status` não vinha no corpo. Para um lead `WON` ou `LOST`, o status ausente era tratado como mudança e a atualização de outros campos respondia 400.

Foi corrigido em `873c529`. A troca de status de um lead fechado continua rejeitada quando o campo é enviado com valor diferente.

### Status na criação

`StoreLeadRequest` aceitava `status` com `nullable|string`. Qualquer texto passava, inclusive valor fora de `Lead::STATUSES`. `null` explícito também passava.

Foi corrigido em `873c529` para `sometimes` e `Rule::in(Lead::STATUSES)`. Status omitido continua aceito. `INVALIDO` e `null` explícito passam a responder 422. `WON` e os demais valores da constante continuam aceitos.

### `raw_payload` na listagem

`index` serializava a coleção Eloquent. `Lead` não define `$hidden`. A resposta incluía os atributos do model, entre eles `raw_payload` e `synced_at`, quando existiam. `show` já usava `LeadResource` e não devolvia `raw_payload`.

O conteúdo de `raw_payload` é o JSON recebido no sync (`SyncLeads`). A listagem passou a usar `LeadResource` em `17e6319`. Criação e atualização ainda devolvem o model completo.

### Listagem sem paginação

`index` usa `get()` e devolve todos os registros que a query encontrar, além de `total` e `summary`. Não há `paginate`. O efeito em memória e tempo cresce com o volume da tabela. O volume real não está no repositório.

### Leitura e escrita com proteção diferente

Até `f459347`, `routes/api.php` deixava `GET /api/leads` e `GET /api/leads/{id}` sem `api.key`. `POST`, `PUT` e `DELETE` já exigiam o middleware. Quem alcançava o host lia nome, e-mail, telefone, empresa, origem, status e observações. A leitura passou a usar o mesmo middleware.

### CORS com origem `*`

`config/cors.php` permite qualquer origem, qualquer método e qualquer header em `api/*` e `sanctum/csrf-cookie`, com `supports_credentials` falso. Um site em outra origem consegue ler a resposta no navegador se o host da API for acessível a partir desse navegador. A configuração está confirmada; a exposição efetiva depende da rede.

### `external_id` sem unicidade

A migration `2023_06_02_093011_add_sync_columns_to_leads_table` cria `external_id` anulável, sem índice único. `SyncLeads` usa `firstOrNew(['external_id' => $item['id']])`. Se já existirem duas linhas com o mesmo valor, a busca pega a primeira. O schema permite a duplicidade. Não há dado de produção neste repositório que mostre duplicata ocorrendo.

### Dependências

`composer audit --locked`, executado com o Composer 2.10.3 sobre o `composer.lock` atual, reportou 25 advisories em 8 pacotes: `laravel/framework` (7), `symfony/http-foundation` (5), `symfony/http-kernel` (1), `symfony/mailer` (1), `symfony/mime` (2), `symfony/process` (1), `symfony/routing` (3) e `symfony/yaml` (5). O comando terminou com código 1. O framework instalado é o 9.52.22. Os sete avisos do framework não têm correção nessa linha. Os 18 do Symfony estão nas versões 6.0.19 e 6.0.20 travadas pelo lock. A decisão, por causa do desafio, é permanecer no Laravel 9.52.22 e não migrar para 10, 11 ou 12. A subida do Symfony para 6.4, compatível com `^6.0`, foi avaliada e ainda não foi aplicada.

Dois pacotes estão abandonados: `fruitcake/laravel-cors`, sem substituto indicado pelo Composer, e `nunomaduro/larastan`, com sugestão `larastan/larastan`.

O CVE-2026-114509, crítico, está no audit porque o `TokenGuard` do framework entra na faixa `<10.50.4`. `config/auth.php` só registra o guard `web` com driver `session`. O guard `sanctum` é registrado pelo pacote Sanctum com driver próprio. Nenhum guard usa `driver` `token`. A presença do advisory no Composer não significa, por si só, que a vulnerabilidade seja explorável pelas rotas atuais. O comando local não usou `--ignore`.

## 3. Correções implementadas

### Validação da API Key

`CheckApiKey` exige string no header e na configuração, rejeita configuração vazia e compara com `hash_equals`. Fora isso, responde 401. Com a chave configurada e o header igual, a requisição segue.

A comparação anterior liberava a escrita quando não havia segredo e não era adequada para segredo. A anotação de retorno passou a incluir `Illuminate\Http\JsonResponse`, porque é o tipo devolvido no 401. Essa alteração de PHPDoc entrou no mesmo commit da correção, `09c36ae`.

### Busca agrupada

Os três `LIKE` ficaram dentro de `where(function ...)`. Filtros de `status` e `source` continuam valendo junto com `search`. O teste `test_search_respects_status_filter` cobre a combinação de status e busca.

### Criação com dados validados

`store` grava `$request->validated()`. Campos fora das regras de `StoreLeadRequest` deixam de entrar no `create`. O teste `test_create_lead_does_not_allow_internal_fields` envia `external_id`, `raw_payload` e `synced_at` e espera esses valores nulos no banco.

### Atualização com lista explícita

`update` grava apenas `name`, `email`, `phone`, `company`, `source`, `status` e `notes`. O teste `test_update_lead_does_not_allow_internal_fields` verifica que as colunas de sincronização não são substituídas pelo corpo.

### E-mail único ignorando o próprio registro

A regra passou a `unique:leads,email,{id}`. Atualizar o lead sem trocar o e-mail deixa de colidir com ele mesmo. Outro lead com o mesmo e-mail continua inválido.

### Lead finalizado

O 400 só ocorre se `status` veio na requisição, é diferente do atual e `isClosed()` é verdadeiro. `test_closed_lead_can_be_updated_without_changing_status` cobre a edição dos demais campos.

### Status permitidos na criação

A regra `sometimes` com `Rule::in(Lead::STATUSES)` recusa texto fora do funil e `null` explícito. Os testes `test_create_lead_rejects_invalid_status`, `test_create_lead_accepts_valid_status` e `test_create_lead_rejects_null_status` cobrem `INVALIDO` (422), `WON` (201) e `null` (422).

### Sincronização com paginação, validação e transação

`CrmApiClient::getLeads` percorre `page` até `meta.last_page`, com `per_page` 100. Rejeita `last_page` acima de 1000, `last_page` que muda nas páginas seguintes e `current_page` diferente da página pedida. Falha de HTTP, conexão ou payload inválido interrompe a coleta e lança exceção. `SyncLeads` só grava depois de validar o lote inteiro: registro que não é array, `id`, `name`, `email`, `source`, `status` em `Lead::STATUSES` e `created_at`. A gravação fica em `DB::transaction`. Se a coleta ou a validação falha, o comando imprime o erro e devolve `Command::FAILURE`, sem persistir parte do lote. Status e notas de um lead já existente continuam locais.

### Leitura com a mesma API Key

`GET /api/leads` e `GET /api/leads/{id}` entraram no grupo `api.key` em `f459347`. Sem chave ou com chave incorreta a resposta é 401. Com a chave de teste, listagem e detalhe seguem 200. O `README.md` passou a exigir `X-Api-Key` nesses dois métodos.

### Limites de leitura e de escrita

`RouteServiceProvider` registra um único limitador `api`, já usado pelo grupo `api` em `throttle:api`. `GET` conta em `read:{identidade}`, 60 por minuto. `POST`, `PUT`, `PATCH` e `DELETE` contam em `write:{identidade}`, 30 por minuto. A identidade é o id do usuário quando já existe, senão o IP. Esgotar a escrita não consome a leitura. O limite roda antes de `api.key`: a 31ª escrita sem chave responde 429.

### Listagem sem colunas internas

`index` monta a resposta com `LeadResource`. `raw_payload` e `synced_at` não saem na listagem nem no detalhe. `external_id` continua no resource. `store` e `update` ainda respondem com o model.

### Cobertura dos endpoints de escrita

`c44c193` acrescenta três testes em `LeadApiTest`:

- `test_closed_lead_rejects_status_change`: `PUT` que troca o status de um lead `WON` ou `LOST` responde 400 e não grava status nem nome novos.
- `test_delete_missing_lead_returns_not_found`: `DELETE` de id inexistente responde 404 e o outro lead permanece.
- `test_update_rejects_email_already_used_by_another_lead`: e-mail de outro lead responde 422 e não grava nome nem e-mail.

### Anotações do PHPStan

`9842700` adicionou `@mixin \App\Models\Lead` em `LeadResource`, para o analisador reconhecer os atributos lidos em `toArray`, e removeu o PHPDoc órfão acima da propriedade `$namespace` comentada em `RouteServiceProvider`. O `boot()` e o namespace comentado não mudaram. O PHPStan deixa de apontar propriedades indefinidas e `@var` aplicado a método.

## 4. Testes e qualidade

No estado de `873c529`, `php artisan test` aprovou 32 testes em cerca de 1,20 s. Na medição de 15/11/2026, com Xdebug em modo `coverage` e `vendor/bin/phpunit --coverage-clover coverage.xml`, a suíte aprovou 76 testes e 497 asserções: 6 em `tests/Unit/LeadTest.php`, 5 em `tests/Unit/CrmApiClientTest.php`, 56 em `tests/Feature/LeadApiTest.php` e 9 em `tests/Feature/ApiRateLimitTest.php`. A cobertura de linhas de `app/` foi 95,27% (262/275). `SyncLeads`, `CrmApiClient`, `CheckApiKey`, `LeadController`, `LeadResource`, `StoreLeadRequest` e `RouteServiceProvider` ficaram em 100% das linhas. A suíte usa SQLite em memória, conforme `phpunit.xml`.

`composer analyse` (`phpstan analyse` com `phpstan.neon`) terminou sem erros na última execução, em 26 arquivos de `app/`. O aviso de que a série 1.12 do PHPStan está desatualizada é informativo. Não entra na contagem de erros do código analisado.

Na etapa de `0caeee8`, o Pint foi aplicado em `LeadController.php`, `StoreLeadRequest.php` e `LeadApiTest.php`. Permaneciam 7 problemas de estilo em arquivos que aquela etapa não alterou.

`.github/workflows/ci.yml` dispara em push para `melhorias-auditoria` e em pull request para `master`. O único job é `ci`: instala as dependências do lock, roda PHPUnit com cobertura Clover, publica `coverage.xml`, executa PHPStan e o Pint dos arquivos alterados. Não há job `dependencies` e o workflow não executa `composer audit`.

`git diff --cached --check` foi executado antes do commit `873c529` e não apontou problemas de whitespace nas alterações preparadas. Avisos de que o Git substituirá LF por CRLF no Windows referem-se a final de linha e não são falha de teste.

## 5. Pontos de atenção e riscos remanescentes

Estes itens pedem decisão do time antes de mudança de contrato ou de schema.

Confirmado no código, com impacto dependente de ambiente ou de volume:

- A listagem e o detalhe usam `LeadResource` e não devolvem `raw_payload` nem `synced_at`. `store` e `update` ainda respondem com o model, então esses campos podem aparecer na criação e na atualização.
- A listagem da API local não pagina. O custo depende da quantidade de leads. A paginação tratada foi a do CRM central.
- O resumo do funil continua contando a tabela inteira, sem os filtros da listagem.
- O CORS autoriza qualquer origem. O `README` cita dashboard e automações, sem URL de frontend. Nenhum domínio foi inventado em `config/cors.php`.
- `external_id` não é único no banco. Duplicidade é possível pelo schema; não há evidência aqui de que já tenha ocorrido.
- `GET /statuses` está documentado na API externa, mas `CrmApiClient` não o consulta. O status recebido é validado contra `Lead::STATUSES` antes de gravar.

## 6. Recomendações para evolução

- Paginar `index` e definir se `summary` deve respeitar os mesmos filtros da lista.
- Usar `LeadResource` também na criação e na atualização. A listagem e o detalhe já usam o resource.
- Quando existir a origem real do dashboard, restringir `allowed_origins` por variável de ambiente, sem curinga e sem domínio inventado.
- Se a regra de negócio for um `external_id` por lead, e e-mail único de fato, refletir isso em índice único, além da validação.
- Autorizar, se for o caso, a atualização conjunta dos componentes Symfony 6.0 para a 6.4 mais recente permitida por `^6.0`, sem mover `laravel/framework`. Isso não apaga os sete avisos do Laravel 9.
- Trocar `nunomaduro/larastan` por `larastan/larastan` ^2.11 só como renomeação do pacote de desenvolvimento. Manter `fruitcake/laravel-cors`.

## 7. O que faria com mais tempo

Priorizaria um teste de contrato contra a API real do CRM, ou uma gravação sanitizada de `GET /leads` e `GET /statuses`, para fixar status e nulidade além dos casos já cobertos com `Http::fake`. `CrmApiClient::getLeads` percorre as páginas até `meta.last_page`.

Em seguida, revisaria o que fica de Sanctum: a rota `GET /api/user`, o guard de sessão e a ausência de emissão de token. O objetivo seria retirar superfície que a API de leads não usa, ou documentar por que permanece.

Também mediria a listagem com um volume combinado com o time, olharia consultas do `summary` (uma contagem por status) e definiria o mínimo de log do `leads:sync`: quantidade recebida, falha de item e horário, sem gravar o payload completo em log.

## 8. Dúvidas para alinhamento com a equipe

- A leitura de leads passou a exigir `X-Api-Key`. Criação e atualização ainda devolvem o model; `raw_payload` deve sair também dessas respostas?
- Qual é o volume esperado de leads e a listagem local deve paginar?
- `external_id` deve ser único?
- Quais status o CRM envia de fato, inclusive valor fora de `Lead::STATUSES`? O sync agora rejeita o lote inteiro nesse caso.
- Qual é a origem do dashboard, para restringir o CORS sem usar `*`?
- O Symfony pode subir para 6.4 sem sair do Laravel 9.52.22? A migração de framework fica fora do desafio.

## 9. Histórico de commits

1. `165c2ed` — chore: commit inicial com o projeto original do desafio, sem as correções desta auditoria.
2. `09c36ae` — fix: validação estrita da API Key, `hash_equals`, rejeição de configuração vazia ou ausente, PHPDoc com `JsonResponse` e testes de escrita sem chave, com chave incorreta, com chave correta, com configuração vazia e com configuração ausente.
3. `9842700` — chore: `@mixin` do `Lead` em `LeadResource` e remoção do PHPDoc órfão em `RouteServiceProvider`, para o PHPStan.
4. `72b0b9f` — fix: agrupamento da busca por nome, e-mail e empresa, com teste de que o filtro de status continua valendo.
5. `873c529` — fix: criação só com dados validados, atualização por lista de campos, e-mail único ignorando o próprio lead, edição de lead fechado sem exigir status no corpo, e status da criação restrito a `Lead::STATUSES`, com os testes correspondentes.
6. `0caeee8` — style: aplicar padrão Pint nos arquivos alterados. Formatação de `LeadController.php`, `StoreLeadRequest.php` e `LeadApiTest.php`, sem alteração de lógica.
7. `65e23c4` — docs: adicionar relatório de auditoria técnica.
8. `ebe3c4b` — docs: completar o histórico de commits neste relatório.
9. `113e674` — docs: documentar a validação da integração com o CRM real.
10. `e5e47f2` — fix: sincronizar todas as páginas do CRM.
11. `036df36` — fix: validar os leads antes da sincronização.
12. `17e6319` — fix: proteger a listagem e a consulta de origens do CRM.
13. `5bfb0d4` — test: ampliar a cobertura do cliente do CRM.
14. `3027461` — ci: adicionar a pipeline de testes, cobertura Clover, PHPStan e Pint.
15. `880d0d7` — feat: separar os limites de leitura e escrita da API.
16. `f459347` — fix: exigir API Key na consulta de leads.
17. `c44c193` — test: ampliar a cobertura dos endpoints de leads, incluindo status de lead finalizado, exclusão de id inexistente e e-mail já utilizado.

## Validação adicional da integração com o CRM central

Após receber as credenciais e a URL oficial da API, foi realizado um teste de integração com o ambiente disponibilizado pela empresa.

### Resultados

- Autenticação com a API externa realizada com sucesso.
- 100 leads recebidos na primeira execução de `php artisan leads:sync`.
- 100 registros persistidos no banco SQLite.
- Os 100 registros apresentaram `external_id` preenchido.
- Uma segunda execução da sincronização manteve o total de registros em 100, sem aumento da quantidade.

### Observações

O teste demonstra que a integração funcionou com as credenciais e o ambiente disponibilizados, e que a segunda execução não gerou novos registros nesse cenário.

Essa validação não comprova, isoladamente, o comportamento da sincronização diante de alterações nos dados externos, falhas durante o processamento ou execuções concorrentes.
