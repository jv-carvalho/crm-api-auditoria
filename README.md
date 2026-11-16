# LeadCRM: API de leads

## Contexto

O LeadCRM é a API interna que o time comercial usa para acompanhar leads, ou seja, pessoas e empresas que demonstraram interesse em nossos produtos e passam por um funil de vendas até serem ganhas ou perdidas.

Os leads chegam por vários canais (site, Instagram, Facebook, WhatsApp, indicação) e são registrados primeiro no CRM central da empresa, mantido por outro time. Esta aplicação:

- importa periodicamente os leads do CRM central (`php artisan leads:sync`);
- mantém uma base local (SQLite) onde o time comercial atualiza status e observações;
- expõe uma API REST usada por ferramentas internas, como o dashboard e algumas automações.

Fluxo dos dados:

```text
CRM central (API externa, somente leitura)
        ↓  HTTP + Bearer token
app/Services/CrmApiClient.php
        ↓
php artisan leads:sync
        ↓
SQLite local
        ↓
API local de leads (/api/leads)
```

Funil de status:

```text
NEW → CONTACTED → QUALIFIED → PROPOSAL → WON
                                        ↘ LOST
```

Origens: `WEBSITE`, `INSTAGRAM`, `FACEBOOK`, `WHATSAPP`, `REFERRAL`, `OTHER`.

## Tarefa

Você recebeu uma aplicação existente desenvolvida em Laravel.

O sistema é utilizado para gerenciamento de leads comerciais.

Seu objetivo é analisar a aplicação, identificar problemas e oportunidades de melhoria e apresentar suas conclusões.

Você pode implementar as melhorias que considerar relevantes.

Queremos entender seu raciocínio técnico, suas decisões e sua capacidade de trabalhar sobre uma base existente.

## O que entregar

Entregue um arquivo `RELATORIO.md` na raiz do projeto. Para cada ponto levantado, você pode seguir esta estrutura:

```text
Problema
Local
Impacto
Risco
Recomendação
Justificativa
Alteração implementada (se houver)
Testes
Observações
```

No fim, conte o que faria com mais tempo e as dúvidas que levaria ao time.

Não precisa corrigir tudo o que encontrar. "Eu manteria esse código como está" também é uma resposta válida, desde que venha com a justificativa.

### Como entregar

1. Crie um repositório no seu GitHub e faça o primeiro commit com o código exatamente como você o recebeu. Assim fica fácil ver tudo o que mudou depois.
2. Faça suas alterações em commits a partir daí. Commits pequenos e descritivos ajudam bastante.
3. Coloque o `RELATORIO.md` no repositório.
4. Responda o e-mail com o link. Se o repositório for privado, peça no e-mail o usuário que deve receber acesso.

## Ambiente

É uma aplicação Laravel 9 com banco SQLite, e subir o ambiente faz parte do trabalho.

As configurações específicas do projeto ficam no `.env`:

```env
CRM_API_URL=https://crm.exemplo.com.br/api/challenge/v1   # enviada por e-mail
CRM_API_TOKEN=seu-token-aqui                              # enviado por e-mail
APP_API_KEY=uma-chave-local                               # exigida no header X-Api-Key de todas as rotas de leads
```

Comandos do dia a dia:

```bash
php artisan migrate
php artisan db:seed      # leads fictícios para desenvolvimento (opcional)
php artisan leads:sync   # importa os leads do CRM central; em produção roda de hora em hora pelo scheduler
php artisan test
```

### Docker

O `docker compose` sobe a API com PHP 8.3, Composer 2.10.3 e o SQLite de `database/database.sqlite`. Não instala Redis nem MySQL. O código do repositório é montado no container, então o PHP instalado na máquina continua independente. A porta 8000 fica publicada só em `127.0.0.1`.

Composer e o Laravel rodam com o usuário `app`. O UID e o GID padrão são 1000. No Linux, passe os ids do seu usuário para o `vendor` e o SQLite ficarem graváveis por você:

```bash
UID=$(id -u) GID=$(id -g) docker compose up --build
```

No Windows com Docker Desktop, `docker compose up --build` usa 1000 e o volume continua gravável.

Na primeira subida, o container copia `.env.example` para `.env` se esse arquivo ainda não existir, gera `APP_KEY` só quando ela está vazia, instala as dependências do `composer.lock` quando `vendor/autoload.php` ainda não existe e roda as migrations pendentes. Uma migration já aplicada não recria a tabela nem apaga os leads.

```bash
docker compose up --build          # constrói a imagem e inicia em http://localhost:8000
docker compose logs -f app         # acompanha a saída do container
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose stop                # para os containers
docker compose down                # para e remove os containers; o SQLite no disco permanece
```

## Endpoints

Todas as rotas ficam sob `/api`. Envie `Accept: application/json`.

| Método | Rota | Descrição | Requer `X-Api-Key` |
|---|---|---|---|
| GET | `/api/leads` | Lista leads | sim |
| GET | `/api/leads/{id}` | Detalha um lead | sim |
| POST | `/api/leads` | Cria um lead | sim |
| PUT | `/api/leads/{id}` | Atualiza um lead | sim |
| DELETE | `/api/leads/{id}` | Remove um lead | sim |

### Filtros da listagem

| Parâmetro | Exemplo | Descrição |
|---|---|---|
| `status` | `?status=QUALIFIED` | filtra por status |
| `source` | `?source=INSTAGRAM` | filtra por origem |
| `search` | `?search=joao` | busca por nome, e-mail ou empresa |

Os filtros podem ser combinados, por exemplo `?status=QUALIFIED&source=INSTAGRAM&search=silva`.

### Exemplos

```bash
# listar
curl -H "Accept: application/json" -H "X-Api-Key: uma-chave-local" \
  "http://localhost:8000/api/leads?status=NEW"

# detalhar
curl -H "Accept: application/json" -H "X-Api-Key: uma-chave-local" \
  http://localhost:8000/api/leads/1

# criar
curl -X POST http://localhost:8000/api/leads \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Api-Key: uma-chave-local" \
  -d '{"name":"Maria Souza","email":"maria.souza@example.com","phone":"(11) 91234-5678","company":"Padaria Exemplo","source":"INSTAGRAM","notes":"Pediu orçamento"}'

# atualizar
curl -X PUT http://localhost:8000/api/leads/1 \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Api-Key: uma-chave-local" \
  -d '{"status":"CONTACTED","notes":"Primeiro contato feito por telefone"}'

# remover
curl -X DELETE http://localhost:8000/api/leads/1 \
  -H "Accept: application/json" -H "X-Api-Key: uma-chave-local"
```

Campos de escrita: `name`, `email` e `source` são obrigatórios na criação; `phone`, `company` e `notes` são opcionais; `status` assume `NEW` se não for informado.

Listagem, detalhe, criação e atualização devolvem o lead pelos mesmos campos públicos: `id`, `external_id`, `name`, `email`, `phone`, `company`, `source`, `status`, `notes`, `created_at` e `updated_at`. `raw_payload` e `synced_at` não entram nessa resposta. A criação responde 201 e as demais consultas e a atualização respondem 200. No detalhe, na criação e na atualização o lead fica em `data`. A listagem devolve `data`, `total` e `summary`.

```json
{
    "data": {
        "id": 1,
        "external_id": null,
        "name": "Maria Souza",
        "email": "maria.souza@example.com",
        "phone": "11912345678",
        "company": "Padaria Exemplo",
        "source": "INSTAGRAM",
        "status": "NEW",
        "notes": "Pediu orçamento",
        "created_at": "2026-11-15T03:00:00.000000Z",
        "updated_at": "2026-11-15T03:00:00.000000Z"
    }
}
```

## API do CRM central (referência)

A API é somente leitura e exige `Authorization: Bearer <token>`.

| Método | Rota | Descrição |
|---|---|---|
| GET | `/leads` | Lista paginada. Parâmetros: `page` (padrão 1), `per_page` (padrão 50, máximo 100) |
| GET | `/leads/{id}` | Detalhe de um lead (404 se não existir) |
| GET | `/sources` | Origens cadastradas |
| GET | `/statuses` | Status do funil |
| GET | `/version` | Versão da API |

Exemplo de resposta de `/leads`:

```json
{
    "data": [
        {
            "id": 1,
            "name": "João da Silva",
            "email": "joao.silva@example.com",
            "phone": "11999999999",
            "company": "Empresa Exemplo",
            "source": "WEBSITE",
            "status": "NEW",
            "notes": "Lead recebido pelo formulário",
            "created_at": "2025-10-06T10:30:00Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 50,
        "total": 1,
        "last_page": 1
    }
}
```

Os erros vêm no formato `{"error": "not_found", "message": "..."}`, com o status HTTP correspondente.

## Ferramentas

```bash
composer test       # PHPUnit (php artisan test)
composer analyse    # PHPStan / Larastan
composer lint       # Laravel Pint (somente verificação)
composer audit      # vulnerabilidades conhecidas nas dependências
```
