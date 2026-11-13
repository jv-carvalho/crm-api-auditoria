# Relatório de Auditoria Técnica

## 1. Objetivo

Esta auditoria revisou o LeadCRM, API Laravel 9 usada pelo time comercial para consultar e atualizar leads importados de um CRM central. O código recebido no commit `165c2ed` foi comparado com as correções funcionais até `873c529`. O commit `0caeee8` apenas formata arquivos já alterados nessa auditoria.

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

`index` serializa a coleção Eloquent. `Lead` não define `$hidden`. A resposta inclui os atributos do model, entre eles `raw_payload` e `synced_at`, quando existem. `show` usa `LeadResource` e não devolve `raw_payload`.

O conteúdo de `raw_payload` é o JSON recebido no sync (`SyncLeads`). A exposição do campo na listagem é comportamento do código. O quanto isso revela dados do CRM depende do que a API externa tiver enviado. Não foi alterado.

### Listagem sem paginação

`index` usa `get()` e devolve todos os registros que a query encontrar, além de `total` e `summary`. Não há `paginate`. O efeito em memória e tempo cresce com o volume da tabela. O volume real não está no repositório.

### Leitura e escrita com proteção diferente

`routes/api.php` deixa `GET /api/leads` e `GET /api/leads/{id}` sem `api.key`. `POST`, `PUT` e `DELETE` exigem o middleware. O `README.md` documenta essa diferença. Quem alcança o host lê nome, e-mail, telefone, empresa, origem, status e observações. Se a API fica só na rede interna, o alcance é outro. Isso não foi tratado como falha a corrigir sem alinhamento.

### CORS com origem `*`

`config/cors.php` permite qualquer origem, qualquer método e qualquer header em `api/*` e `sanctum/csrf-cookie`, com `supports_credentials` falso. Um site em outra origem consegue ler a resposta no navegador se o host da API for acessível a partir desse navegador. A configuração está confirmada; a exposição efetiva depende da rede.

### `external_id` sem unicidade

A migration `2023_06_02_093011_add_sync_columns_to_leads_table` cria `external_id` anulável, sem índice único. `SyncLeads` usa `firstOrNew(['external_id' => $item['id']])`. Se já existirem duas linhas com o mesmo valor, a busca pega a primeira. O schema permite a duplicidade. Não há dado de produção neste repositório que mostre duplicata ocorrendo.

### Dependências

`composer audit`, executado sobre o `composer.lock` atual, reportou 24 advisories em 8 pacotes: `laravel/framework` (6), `symfony/http-foundation` (5), `symfony/http-kernel` (1), `symfony/mailer` (1), `symfony/mime` (2), `symfony/process` (1), `symfony/routing` (3) e `symfony/yaml` (5). O framework instalado é o 9.52.22, dentro de várias faixas afetadas.

Dois pacotes estão abandonados: `fruitcake/laravel-cors`, sem substituto indicado pelo Composer, e `nunomaduro/larastan`, com sugestão `larastan/larastan`.

Esses avisos são do grafo de dependências. Não significam, por si, que cada falha seja alcançável nesta API. O CVE-2026-114509, por exemplo, está no audit porque o `TokenGuard` do framework entra na faixa afetada. `config/auth.php` só registra o guard `web` com driver `session`. O guard `sanctum` é registrado pelo pacote Sanctum com driver próprio. Nenhum guard usa `driver` `token`, então esse caminho não é usado pelas rotas atuais.

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

### Anotações do PHPStan

`9842700` adicionou `@mixin \App\Models\Lead` em `LeadResource`, para o analisador reconhecer os atributos lidos em `toArray`, e removeu o PHPDoc órfão acima da propriedade `$namespace` comentada em `RouteServiceProvider`. O `boot()` e o namespace comentado não mudaram. O PHPStan deixa de apontar propriedades indefinidas e `@var` aplicado a método.

## 4. Testes e qualidade

`php artisan test`, executado neste estado do repositório, aprovou 32 testes: 6 em `tests/Unit/LeadTest.php` e 26 em `tests/Feature/LeadApiTest.php`. Tempo aproximado: 1,20 s. A suíte usa SQLite em memória, conforme `phpunit.xml`.

`composer analyse` (`phpstan analyse` com `phpstan.neon`) analisou 25 arquivos em `app/` e terminou sem erros. O aviso de que a série 1.12 do PHPStan está desatualizada é informativo. Não entra na contagem de erros do código analisado.

`composer lint` (`pint --test`) verificou 64 arquivos. Na primeira execução havia 10 com problema de estilo. O Pint foi aplicado em `app/Http/Controllers/LeadController.php`, `app/Http/Requests/StoreLeadRequest.php` e `tests/Feature/LeadApiTest.php`, os três arquivos de código modificados nesta auditoria, e esses três deixaram de aparecer no resultado. Permanecem 7 problemas, todos em arquivos que esta etapa não alterou: `app/Console/Commands/SyncLeads.php`, `app/Console/Kernel.php`, `app/Http/Middleware/RedirectIfAuthenticated.php`, `app/Models/User.php`, `app/Providers/AuthServiceProvider.php`, `app/Services/CrmApiClient.php` e `database/seeders/DatabaseSeeder.php`. São regras de estilo, não falha dos 32 testes.

`git diff --cached --check` foi executado antes do commit `873c529` e não apontou problemas de whitespace nas alterações preparadas. Avisos de que o Git substituirá LF por CRLF no Windows referem-se a final de linha e não são falha de teste.

## 5. Pontos de atenção e riscos remanescentes

Estes itens pedem decisão do time antes de mudança de contrato ou de schema.

Confirmado no código, com impacto dependente de ambiente ou de volume:

- A listagem devolve o model inteiro, inclusive `raw_payload`, que guarda o payload do CRM quando o sync preenche a coluna. O detalhe em `LeadResource` não inclui esse campo.
- `GET /api/leads` e `GET /api/leads/{id}` não exigem `X-Api-Key`. Isso está no `README.md`. O risco de leitura indevida existe se o host for acessível fora do público previsto.
- A listagem não pagina. O custo depende da quantidade de leads.
- O CORS autoriza qualquer origem. O efeito prático depende de o navegador conseguir alcançar a API.
- `external_id` não é único no banco. Duplicidade é possível pelo schema; não há evidência aqui de que já tenha ocorrido.
- `SyncLeads` grava `$item['status']` na primeira importação sem conferir `Lead::STATUSES`, sem tratar ausência da chave e sem converter vazio. O README e os testes mostram `NEW` e `QUALIFIED`. `GET /statuses` está documentado, mas `CrmApiClient` não o consulta. Nas sincronizações seguintes, status e notas locais são preservados.

Dependências:

- O audit aponta 24 advisories e 2 pacotes abandonados nas versões instaladas. Atualizar exige checagem de compatibilidade com Laravel 9 e com o código desta API. Não cabe subir pacotes em bloco só para limpar o relatório.

## 6. Recomendações para evolução

- Paginar `index` e definir se `summary` deve respeitar os mesmos filtros da lista.
- Usar `LeadResource` também na listagem, na criação e na atualização, para a resposta não variar conforme o endpoint e não incluir colunas internas por omissão.
- Definir com o produto se a leitura precisa da mesma API Key da escrita, ou de outro controle, e aplicar isso de forma única nas rotas.
- Restringir `allowed_origins` aos domínios que realmente chamam a API.
- Se a regra de negócio for um `external_id` por lead, e e-mail único de fato, refletir isso em índice único, além da validação.
- Validar o payload do sync: status conhecido, campos obrigatórios presentes e resposta vazia ou malformada sem interromper o lote inteiro de forma opaca.
- Planejar a saída do Laravel 9 e a troca de `fruitcake/laravel-cors` e `nunomaduro/larastan`, com testes de regressão antes do salto de versão.
- Manter testes de feature para autorização, massa de campos e sync, e acrescentar casos de payload incompleto do CRM quando o contrato externo estiver fechado.

## 7. O que faria com mais tempo

Priorizaria um teste de contrato contra a API real do CRM, ou uma gravação sanitizada de `GET /leads` e `GET /statuses`, para fixar status, nulidade e paginação externa. `CrmApiClient` hoje pede uma única página com `per_page` 100 e não percorre `meta.last_page`.

Em seguida, revisaria o que fica de Sanctum: a rota `GET /api/user`, o guard de sessão e a ausência de emissão de token. O objetivo seria retirar superfície que a API de leads não usa, ou documentar por que permanece.

Também mediria a listagem com um volume combinado com o time, olharia consultas do `summary` (uma contagem por status) e definiria o mínimo de log do `leads:sync`: quantidade recebida, falha de item e horário, sem gravar o payload completo em log.

## 8. Dúvidas para alinhamento com a equipe

- Os `GET` de leads devem exigir `X-Api-Key`, como a escrita, ou a leitura aberta do README permanece?
- `raw_payload` deve aparecer para os consumidores da listagem?
- Qual é o volume esperado de leads e qual paginação a API deve oferecer?
- `external_id` deve ser único?
- Quais status o CRM envia de fato, inclusive `WON`, `LOST`, nulo ou valor fora de `Lead::STATUSES`?
- Quais origens o CORS deve autorizar?
- Existe janela para atualizar o Laravel 9 e as dependências apontadas pelo `composer audit`?

## 9. Histórico de commits

1. `165c2ed` — chore: commit inicial com o projeto original do desafio, sem as correções desta auditoria.
2. `09c36ae` — fix: validação estrita da API Key, `hash_equals`, rejeição de configuração vazia ou ausente, PHPDoc com `JsonResponse` e testes de escrita sem chave, com chave incorreta, com chave correta, com configuração vazia e com configuração ausente.
3. `9842700` — chore: `@mixin` do `Lead` em `LeadResource` e remoção do PHPDoc órfão em `RouteServiceProvider`, para o PHPStan.
4. `72b0b9f` — fix: agrupamento da busca por nome, e-mail e empresa, com teste de que o filtro de status continua valendo.
5. `873c529` — fix: criação só com dados validados, atualização por lista de campos, e-mail único ignorando o próprio lead, edição de lead fechado sem exigir status no corpo, e status da criação restrito a `Lead::STATUSES`, com os testes correspondentes.
6. `0caeee8` — style: aplicar padrão Pint nos arquivos alterados. Formatação de `LeadController.php`, `StoreLeadRequest.php` e `LeadApiTest.php`, sem alteração de lógica.
