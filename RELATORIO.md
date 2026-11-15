# Relatório de Auditoria Técnica

## 1. Objetivo e escopo

Esta auditoria revisou o LeadCRM, API Laravel 9.52.22 usada pelo time comercial para consultar e atualizar leads importados de um CRM central. O código recebido no commit `165c2ed` foi comparado com as correções da branch `melhorias-auditoria`. O framework permanece na versão exigida pelo desafio. Não houve migração para Laravel 10, 11 ou 12.

O escopo foi a integração com o CRM, a integridade do que é gravado, a autenticação das rotas de leads, o limite de requisições, a validação da API, a qualidade dos testes e a forma de subir o ambiente. Defeito confirmado no código foi separado de decisão que ainda depende de regra de negócio ou de ambiente.

## 2. Resumo executivo

A sincronização passou a percorrer todas as páginas do CRM, validar o lote inteiro e gravar dentro de uma transação. Status e notas de um lead que já existe continuam locais. As cinco rotas de leads exigem `X-Api-Key`. Leitura e escrita usam contadores separados. A listagem e o detalhe deixam de expor `raw_payload` e `synced_at`. Os números de testes, cobertura, PHPStan e Docker estão na seção 5. O workflow e a ressalva sobre o GitHub Actions estão na seção 6.

A decisão de permanecer no Laravel 9.52.22 vem do enunciado do desafio. Corrigir a integridade da sincronização e o acesso às rotas não exigia trocar o framework.

## 3. Achados da auditoria

### 3.1 API Key aceitava configuração vazia

**Problema:** a comparação da API Key usava `!=`. Com `APP_API_KEY` ausente ou vazia, o PHP trata `null` e `''` como iguais, e uma requisição sem header passava.

**Local:** `app/Http/Middleware/CheckApiKey.php`, método `handle`.

**Impacto:** `POST`, `PUT` e `DELETE /api/leads` deixavam de exigir segredo quando a configuração não tinha valor.

**Risco:** qualquer cliente que alcançasse o host podia criar, alterar ou apagar leads se o ambiente subisse com a chave vazia do `.env.example`.

**Recomendação:** exigir string no header e na configuração, rejeitar valor vazio e comparar com `hash_equals`.

**Justificativa:** a falha estava na comparação, não na ausência do middleware. `hash_equals` é a comparação adequada para segredo e continua compatível com Laravel 9.

**Alteração implementada:** `handle` responde 401 em JSON quando a chave configurada ou o header não é uma string não vazia, ou quando os valores diferem. O PHPDoc passou a incluir `JsonResponse`. Commit `09c36ae`.

**Testes:** escrita sem chave, com chave incorreta, com chave correta, com configuração vazia e com configuração ausente.

### 3.2 Consulta de leads sem API Key

**Problema:** listagem e detalhe não passavam pelo middleware `api.key`, embora a escrita já passasse.

**Local:** `routes/api.php`.

**Impacto:** quem alcançava o host lia nome, e-mail, telefone, empresa, origem, status e observações.

**Risco:** os dados comerciais ficavam disponíveis sem o segredo exigido na escrita.

**Recomendação:** colocar `GET /api/leads` e `GET /api/leads/{id}` no mesmo grupo `api.key`.

**Justificativa:** o contrato da API é o mesmo para consulta e alteração. A rota `GET /api/user` permanece em `auth:sanctum` e não foi movida.

**Alteração implementada:** as cinco rotas de leads ficaram no grupo `api.key`. O `README.md` passou a exigir `X-Api-Key` na listagem e no detalhe. Commit `f459347`.

**Testes:** `test_list_leads_without_api_key_is_rejected`, `test_list_leads_with_wrong_api_key_is_rejected`, `test_list_leads_with_correct_api_key_is_allowed` e os três equivalentes de `show`.

### 3.3 Busca com `OR` sem agrupamento

**Problema:** os `orWhere` de nome, e-mail e empresa não estavam dentro de um grupo. Com `status` ou `source` na mesma query, um `OR` podia ignorar o filtro anterior.

**Local:** `LeadController::index`.

**Impacto:** uma busca curta podia devolver leads de outro status ou de outra origem.

**Risco:** o time comercial veria registros fora do recorte pedido.

**Recomendação:** agrupar os três `LIKE` em `where(function ...)`.

**Justificativa:** a correção mantém os filtros existentes e só altera a precedência do SQL.

**Alteração implementada:** os três `LIKE` ficaram no grupo. Commit `72b0b9f`. O resumo do funil continua contando a tabela inteira, sem os filtros da listagem. Isso permanece em aberto.

**Testes:** `test_search_respects_status_filter`.

### 3.4 Atribuição em massa na criação e na atualização

**Problema:** `store` usava `Lead::create($request->all())` e `update` usava `$lead->update($request->all())`. O model declara `$guarded = []`.

**Local:** `app/Models/Lead.php` e `LeadController::store` e `LeadController::update`.

**Impacto:** o corpo podia gravar `external_id`, `raw_payload` e `synced_at`, colunas de sincronização que não fazem parte do contrato de escrita.

**Risco:** um cliente autenticado podia substituir o vínculo com o CRM ou o payload original.

**Recomendação:** gravar só os campos validados na criação e uma lista explícita na atualização.

**Justificativa:** a borda da API é o ponto que recebe o corpo. Fechar `$guarded` no model mudaria também o `fill` do `leads:sync`, que precisa gravar as colunas internas.

**Alteração implementada:** `store` grava `$request->validated()`. `update` grava `name`, `email`, `phone`, `company`, `source`, `status` e `notes`. Commit `873c529`. O model continua com `$guarded = []`.

**Testes:** `test_create_lead_does_not_allow_internal_fields` e `test_update_lead_does_not_allow_internal_fields`.

### 3.5 E-mail único na atualização

**Problema:** a regra era `unique:leads,email`, sem ignorar o id do registro. Reenviar o e-mail já gravado naquele lead produzia erro de validação.

**Local:** `LeadController::update`. A migration `2023_03_14_181502_create_leads_table` só indexa `email`.

**Impacto:** uma atualização sem troca de e-mail era recusada.

**Risco:** o time não conseguia editar nome, telefone ou notas sem alterar o e-mail. A unicidade continua só na aplicação, porque o banco não tem índice único.

**Recomendação:** ignorar o próprio id na regra e manter a colisão com outro lead.

**Justificativa:** a regra de negócio é um e-mail por lead, não um e-mail imutável. Índice único no banco fica para uma decisão de schema.

**Alteração implementada:** a regra passou a `unique:leads,email,{id}`. Commit `873c529`.

**Testes:** `test_update_rejects_email_already_used_by_another_lead` cobre o e-mail de outro lead: responde 422 e não grava nome nem e-mail. Commit do teste: `c44c193`. Não há teste que reenvie o e-mail do próprio registro.

### 3.6 Edição de lead finalizado

**Problema:** a condição comparava o status atual com `$request->status` mesmo quando `status` não vinha no corpo. Para um lead `WON` ou `LOST`, a ausência do campo era tratada como mudança.

**Local:** `LeadController::update`.

**Impacto:** atualizar outros campos de um lead fechado respondia 400.

**Risco:** o comercial deixava de registrar notas ou correções em leads já ganhos ou perdidos.

**Recomendação:** rejeitar só quando `status` veio na requisição, é diferente do atual e `isClosed()` é verdadeiro.

**Justificativa:** a trava é sobre mudança de funil, não sobre qualquer edição.

**Alteração implementada:** a condição passou a exigir os três critérios e responde 400 com a mensagem já usada pela API. Commit `873c529`.

**Testes:** `test_closed_lead_can_be_updated_without_changing_status` e `test_closed_lead_rejects_status_change`. O segundo cobre `WON` e `LOST`, responde 400 e não grava status nem nome novos. Commit do segundo: `c44c193`.

### 3.7 Status livre na criação

**Problema:** `StoreLeadRequest` aceitava `status` com `nullable|string`. Texto fora de `Lead::STATUSES` e `null` explícito passavam.

**Local:** `app/Http/Requests/StoreLeadRequest.php`.

**Impacto:** a API gravava status que o funil não reconhece.

**Risco:** listagem, resumo e sincronização passavam a conviver com valores que o restante do código não trata.

**Recomendação:** aceitar status omitido e recusar valor fora de `Lead::STATUSES`, inclusive `null`.

**Justificativa:** o funil já está definido na constante do model. A criação não precisa inventar outro conjunto.

**Alteração implementada:** a regra passou a `sometimes` com `Rule::in(Lead::STATUSES)`. Commit `873c529`.

**Testes:** `test_create_lead_rejects_invalid_status` (`INVALIDO`, 422), `test_create_lead_accepts_valid_status` (`WON`, 201) e `test_create_lead_rejects_null_status` (422).

### 3.8 Exposição de campos internos

**Problema:** `index` serializava a coleção Eloquent. `Lead` não define `$hidden`, então a resposta incluía `raw_payload` e `synced_at`. `show` já usava `LeadResource`.

**Local:** `LeadController::index` e `app/Http/Resources/LeadResource.php`.

**Impacto:** a listagem devolvia o JSON recebido do CRM e o horário da sincronização.

**Risco:** o payload externo, que pode ter mais dados do que o contrato local, saía para qualquer cliente com API Key.

**Recomendação:** usar `LeadResource` também na listagem.

**Justificativa:** o resource já era o contrato do detalhe. Reutilizá-lo evita uma segunda lista de campos.

**Alteração implementada:** `index` monta a resposta com `LeadResource`. `raw_payload` e `synced_at` não saem na listagem nem no detalhe. `external_id` continua no resource. Commit `17e6319`. `store` e `update` ainda respondem com `response()->json($lead)`, então o model completo pode aparecer na criação e na atualização. Isso não foi alterado.

**Testes:** `test_lead_listing_does_not_expose_internal_sync_fields`.

### 3.9 Uma página só na consulta ao CRM

**Problema:** a coleta de leads não percorria `meta.last_page`.

**Local:** `app/Services/CrmApiClient.php`, método `getLeads`.

**Impacto:** `php artisan leads:sync` importava só a primeira página. O CRM documenta `page` e `per_page`, com máximo de 100 por página.

**Risco:** leads das páginas seguintes nunca entravam na base local.

**Recomendação:** pedir as páginas em sequência, com `per_page` 100, e interromper a coleta quando a paginação vier inconsistente.

**Justificativa:** o contrato externo já informa `last_page`. Limitar a 1000 páginas evita um laço sem fim se a resposta vier incorreta.

**Alteração implementada:** `getLeads` percorre da página 1 até `meta.last_page`. Rejeita `last_page` acima de 1000, `last_page` que muda nas páginas seguintes e `current_page` diferente da página pedida. Falha de HTTP, conexão ou payload inválido lança exceção e interrompe a coleta. Commit `e5e47f2`.

**Testes:** `test_sync_imports_leads_from_two_pages`, `test_sync_imports_leads_from_three_pages`, `test_sync_sends_page_and_per_page_on_each_request`, `test_get_leads_aggregates_every_page`, `test_get_leads_rejects_more_than_one_thousand_pages`, `test_get_leads_rejects_inconsistent_last_page` e `test_get_leads_rejects_current_page_that_does_not_match_the_request`.

### 3.10 Dados do CRM gravados sem validação

**Problema:** o lote recebido podia seguir para o banco sem conferir formato, identificador, e-mail e status.

**Local:** `app/Console/Commands/SyncLeads.php`, método `handle`.

**Impacto:** um item malformado era persistido ou interrompia o comando no meio da gravação.

**Risco:** a base local ficava com status fora do funil, e-mail inválido ou registro sem `id`.

**Recomendação:** validar todos os itens e só então gravar.

**Justificativa:** rejeitar o lote inteiro é mais seguro do que gravar uma parte e deixar o restante para uma execução seguinte descobrir.

**Alteração implementada:** cada item precisa ser array e passar em `id`, `name`, `email`, `source`, `status` dentro de `Lead::STATUSES` e `created_at`. Falha de validação imprime o erro e devolve `Command::FAILURE` antes da transação. Commit `036df36`.

**Testes:** ausência de `id`, de `name`, e-mail inválido, status inválido, item que não é array e lote em que um registro inválido impede a gravação dos demais.

### 3.11 Persistência parcial na sincronização

**Problema:** uma falha no meio da importação podia deixar só parte dos leads gravada.

**Local:** `SyncLeads::handle` e `CrmApiClient::getLeads`.

**Impacto:** a base local não correspondia nem ao estado anterior nem ao lote completo do CRM.

**Risco:** uma segunda execução poderia atualizar uns registros e não outros, sem um ponto claro de falha.

**Recomendação:** tratar falha de coleta como falha do comando e gravar o lote válido numa transação.

**Justificativa:** SQLite participa da transação do Laravel. Se a gravação falha, o rollback desfaz o lote.

**Alteração implementada:** exceção de coleta é capturada, impressa e convertida em `Command::FAILURE`. A gravação fica em `DB::transaction`. Commit `036df36`, junto com a validação prévia.

**Testes:** `test_sync_does_not_persist_partial_pages_when_collection_fails`, `test_sync_fails_when_second_page_returns_http_error`, `test_sync_command_fails_when_crm_is_unavailable`, `test_sync_fails_when_crm_connection_fails` e `test_sync_rolls_back_all_leads_when_persistence_fails`.

### 3.12 Falha e resposta inválida do CRM na API

**Problema:** criar um lead consulta as origens no CRM. Falha de rede, HTTP ou payload inválido não tinha uma resposta estável para o cliente da API.

**Local:** `CrmApiClient::getSources`, `app/Exceptions/CrmApiException.php` e `app/Exceptions/Handler.php`, método `register`.

**Impacto:** a criação depende de `GET /sources`. Quando o CRM não responde de forma utilizável, a API responde 503 com a mensagem `Serviço de CRM temporariamente indisponível.`

**Risco:** sem esse tratamento, o cliente receberia erro interno ou gravaria uma origem que o CRM não reconhece.

**Recomendação:** converter a falha do cliente HTTP em `CrmApiException` e renderizá-la como 503 nas requisições JSON.

**Justificativa:** 503 indica dependência externa indisponível e não confunde com erro de validação do lead.

**Alteração implementada:** o `Handler` registra um `renderable` para `CrmApiException`. Commit `17e6319`. O comando `leads:sync` trata a falha de `getLeads` à parte, com `Command::FAILURE`, porque não é uma requisição HTTP da API local.

**Testes:** `test_create_lead_returns_503_when_crm_sources_are_unavailable`, `test_create_lead_returns_503_when_crm_connection_fails`, `test_create_lead_returns_503_when_crm_sources_response_is_invalid` e `test_create_lead_returns_503_when_crm_source_item_is_invalid`.

### 3.13 Listagem local sem paginação

**Problema:** `index` usa `get()` e devolve todos os registros da query, além de `total` e `summary`.

**Local:** `LeadController::index`.

**Impacto:** memória e tempo crescem com a tabela. O volume real não está no repositório.

**Risco:** com o sync passando a trazer todas as páginas do CRM, a listagem local acompanha esse volume numa única resposta.

**Recomendação:** paginar `index` quando o time definir o tamanho da página e se o `summary` deve respeitar os mesmos filtros.

**Justificativa:** paginar agora escolheria um contrato de resposta que o dashboard ainda não confirmou. A paginação corrigida foi a do CRM, onde o contrato já existia.

**Alteração implementada:** nenhuma na listagem local.

**Testes:** a suíte cobre filtros e busca sobre o conjunto devolvido por `get()`. Não há teste de página.

### 3.14 CORS com origem `*`

**Problema:** `config/cors.php` permite qualquer origem, método e header em `api/*` e `sanctum/csrf-cookie`, com `supports_credentials` falso.

**Local:** `config/cors.php`.

**Impacto:** um site em outra origem consegue ler a resposta no navegador se o host da API for acessível a partir desse navegador. A exposição efetiva depende da rede.

**Risco:** restringir agora exigiria inventar o domínio do dashboard. O `README` cita dashboard e automações, sem URL.

**Recomendação:** quando a origem real existir, ler `allowed_origins` de variável de ambiente, sem curinga.

**Justificativa:** a configuração atual está confirmada. Trocar o curinga sem domínio conhecido quebraria um cliente que ainda não foi nomeado.

**Alteração implementada:** nenhuma.

**Testes:** não há teste de CORS. A configuração foi conferida no arquivo.

### 3.15 `external_id` sem unicidade

**Problema:** a coluna é anulável e não tem índice único. A sincronização busca o primeiro registro com aquele valor.

**Local:** migration `2023_06_02_093011_add_sync_columns_to_leads_table` e `SyncLeads::handle`.

**Impacto:** se já existirem duas linhas com o mesmo `external_id`, `firstOrNew` atualiza a primeira.

**Risco:** a duplicidade é possível pelo schema. Não há dado neste repositório que mostre duplicata ocorrendo.

**Recomendação:** se a regra for um `external_id` por lead, refletir isso em índice único, além da validação.

**Justificativa:** índice único em base que já tenha duplicata falha na migration. A decisão depende do dado real.

**Alteração implementada:** nenhuma no schema.

**Testes:** a sincronização cobre inserção e atualização pelo `external_id` nos casos em que há uma linha por id. Não há teste de duplicata.

### 3.16 Dependências com avisos conhecidos

**Problema:** `composer audit --locked`, com o Composer 2.10.3, reportou 25 advisories em 8 pacotes: `laravel/framework` (7), `symfony/http-foundation` (5), `symfony/http-kernel` (1), `symfony/mailer` (1), `symfony/mime` (2), `symfony/process` (1), `symfony/routing` (3) e `symfony/yaml` (5). O comando terminou com código 1. O lock permanece em Symfony 6.0.19 e 6.0.20.

**Local:** `composer.lock`. O framework instalado é o 9.52.22.

**Impacto:** os sete avisos do framework não têm correção nessa linha. Dois pacotes estão abandonados: `fruitcake/laravel-cors`, sem substituto indicado, e `nunomaduro/larastan`, com sugestão `larastan/larastan`.

**Risco:** o CVE-2026-114509 entra no audit porque o `TokenGuard` do framework está na faixa `<10.50.4`. `config/auth.php` só registra o guard `web` com driver `session`. O guard `sanctum` usa o driver do próprio Sanctum. Nenhum guard usa `driver` `token`. A presença do advisory não significa, por si só, que a falha seja explorável pelas rotas atuais. O comando local não usou `--ignore`.

**Recomendação:** permanecer no Laravel 9.52.22. Uma subida do Symfony para 6.4, ainda dentro de `^6.0`, foi avaliada e não foi aplicada.

**Justificativa:** o desafio especifica esta linha do framework. Migrar para obter patch dos sete avisos sairia do escopo. O workflow não executa `composer audit`.

**Alteração implementada:** nenhuma em `composer.json` ou `composer.lock`.

**Testes:** o audit foi executado localmente sobre o lock. Não há teste automatizado de dependência.

## 4. Melhorias adicionais implementadas

A sincronização preserva o andamento comercial. Em `SyncLeads::handle`, `firstOrNew(['external_id' => $item['id']])` atualiza nome, e-mail, telefone, empresa, origem, `raw_payload` e `synced_at`. Status e notas só são gravados quando o lead ainda não existe. Isso é regra de negócio implementada e coberta por `test_sync_keeps_status_and_notes_maintained_locally`, não um defeito encontrado no código original.

Leitura e escrita passaram a ter contadores separados. `RouteServiceProvider::configureRateLimiting` mantém o limitador `api` já usado pelo grupo `api`. `GET` conta em `read:{identidade}`, 60 por minuto. `POST`, `PUT`, `PATCH` e `DELETE` contam em `write:{identidade}`, 30 por minuto. A identidade é o id do usuário quando já existe e, nas rotas atuais, o IP. Esgotar a escrita não consome a leitura. O limite roda antes de `api.key`, então a 31ª escrita sem chave responde 429. Commit `880d0d7`. Os nove testes de `tests/Feature/ApiRateLimitTest.php` cobrem leitura, escrita em `POST`, `PUT` e `DELETE`, a independência dos contadores, o isolamento por IP e a escrita sem chave.

`LeadController::destroy` já usava `findOrFail`. Não houve mudança de regra. `test_delete_missing_lead_returns_not_found`, no commit `c44c193`, comprova que um id inexistente responde 404 e não altera os outros leads. `test_show_returns_404_when_lead_does_not_exist` cobre o detalhe. `test_put_requests_use_the_write_limit_and_do_not_block_reads` exige 404 em `PUT /api/leads/999999`.

`9842700` adicionou `@mixin \App\Models\Lead` em `LeadResource` e removeu o PHPDoc órfão acima da propriedade comentada `$namespace` em `RouteServiceProvider`. O `boot()` não mudou de comportamento. O PHPStan deixa de apontar propriedades indefinidas nesse resource.

O ambiente de desenvolvimento passou a subir com Docker Compose: PHP 8.3, Composer 2.10.3, um serviço `app` e o SQLite em `database/database.sqlite`. O código do host é montado em `/var/www/html`. Composer e Laravel rodam como o usuário `app`. O `composer install` só roda quando `vendor/autoload.php` não existe. As migrations pendentes rodam na subida e não recriam tabela já aplicada.

## 5. Testes e evidências de validação

No estado de `873c529`, `php artisan test` aprovou 32 testes em cerca de 1,20 s. Na medição de 15/11/2026, com Xdebug e `vendor/bin/phpunit --coverage-clover coverage.xml`, a suíte aprovou 76 testes e 497 asserções: 6 em `tests/Unit/LeadTest.php`, 5 em `tests/Unit/CrmApiClientTest.php`, 56 em `tests/Feature/LeadApiTest.php` e 9 em `tests/Feature/ApiRateLimitTest.php`. O Clover dessa execução registra 262 de 275 linhas em `app/`, 95,27%. As 497 asserções são o total impresso pelo PHPUnit nessa execução local. O `coverage.xml` não informa a quantidade de asserções. Ficaram em 100% das linhas `SyncLeads` (51/51), `CrmApiClient` (90/90), `CheckApiKey` (6/6), `LeadController` (59/59), `LeadResource` (13/13), `StoreLeadRequest` (11/11) e `RouteServiceProvider` (16/16). O Clover não mede ramos. A suíte usa SQLite em memória, conforme `phpunit.xml`, e não altera `database/database.sqlite`.

As 13 linhas sem cobertura são do esqueleto padrão: uma em `app/Console/Kernel.php` (`schedule`), duas em `Authenticate`, cinco em `RedirectIfAuthenticated`, três em `TrustHosts` e duas em `BroadcastServiceProvider`. Isso não é cobertura total do projeto.

Na mesma ocasião, `composer analyse` foi executado localmente e terminou sem erros em 26 arquivos de `app/`. Não há log dessa execução salvo no repositório. O aviso de que a série 1.12 do PHPStan está desatualizada é informativo e não entra na contagem de erros.

O Pint não foi reexecutado nesta revisão e seu estado atual não foi medido.

`git diff --cached --check`, antes do commit `873c529`, não apontou problema de whitespace nas alterações preparadas. Aviso de LF para CRLF no Windows refere-se a final de linha.

O Docker usado na validação é o Docker 29.9.3. `docker compose config` publicou a porta só em `127.0.0.1:8000`. Dentro do container `crm-api-app-1`, `docker compose exec app php artisan test` aprovou 76 testes em 15,35 s. Essa execução não gerou o Clover.

### Integração com o CRM central

Com as credenciais e a URL oficiais, a autenticação na API externa funcionou. A primeira execução de `php artisan leads:sync` recebeu 100 leads e persistiu 100 registros no SQLite, todos com `external_id`. A segunda execução manteve o total em 100.

Esse teste mostra que a integração funcionou naquele ambiente e que a segunda execução não criou registros novos. Não comprova, sozinho, alteração de dados externos, falha no meio do processamento ou execuções concorrentes. Esses casos estão cobertos com `Http::fake` na suíte.

## 6. Configuração de CI e Docker

`.github/workflows/ci.yml` dispara em push para `melhorias-auditoria` e em pull request para `master`. O único job, `ci`, instala as dependências do lock, roda PHPUnit com cobertura Clover, publica `coverage.xml`, executa PHPStan e o Pint dos arquivos PHP alterados em relação a `master`. Não há job de `composer audit`.

O resultado desses checks no GitHub não foi lido nesta revisão. O `gh` não está disponível no ambiente em que o relatório foi reorganizado. O que está confirmado é o conteúdo do workflow e as execuções locais descritas na seção 5.

O `docker compose config` montou o repositório em `/var/www/html` e passou UID e GID 1000. O `README.md` descreve os comandos de subir, ver logs, migrar, testar e parar, inclusive `UID` e `GID` no Linux. No Windows com Docker Desktop, o padrão 1000 foi o valor efetivo do `config`.

## 7. Riscos e pontos que permanecem em aberto

- `store` e `update` ainda devolvem o model. `raw_payload` e `synced_at` podem aparecer nessas duas respostas.
- A listagem local não pagina, e o resumo do funil não usa os filtros da lista.
- O CORS continua com origem `*`.
- `external_id` não é único no banco.
- `GET /statuses` está documentado na API externa, mas `CrmApiClient` não o consulta. O status recebido é validado contra `Lead::STATUSES` antes de gravar.
- Os 25 advisories do lock continuam. Sete não têm correção no Laravel 9.52.22. A subida do Symfony para 6.4 não foi feita.

## 8. O que seria feito com mais tempo

Priorizaria um teste de contrato contra a API real do CRM, ou uma gravação sanitizada de `GET /leads` e `GET /statuses`, para fixar status e nulidade além dos casos já cobertos com `Http::fake`.

Em seguida, revisaria o que fica de Sanctum: a rota `GET /api/user`, o guard de sessão e a ausência de emissão de token. O objetivo seria retirar superfície que a API de leads não usa, ou documentar por que permanece.

Também mediria a listagem com um volume combinado com o time, olharia as contagens do `summary` e definiria o mínimo de log do `leads:sync`: quantidade recebida, falha de item e horário, sem gravar o payload completo.

## 9. Perguntas para a equipe

- Criação e atualização ainda devolvem o model. `raw_payload` deve sair também dessas respostas?
- Qual é o volume esperado de leads, e a listagem local deve paginar? O resumo deve respeitar os filtros?
- `external_id` deve ser único?
- Quais status o CRM envia de fato, inclusive valor fora de `Lead::STATUSES`? O sync rejeita o lote inteiro nesse caso.
- Qual é a origem do dashboard, para restringir o CORS sem usar `*`?
- O Symfony pode subir para 6.4 sem sair do Laravel 9.52.22? A migração de framework fica fora do desafio.

## 10. Histórico de commits

A lista abaixo é a da branch `melhorias-auditoria`. Não é o histórico de `master`.

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
18. `2682960` — docs: atualizar relatorio de auditoria.
19. `5764a237` — build: adicionar ambiente Docker para desenvolvimento.
