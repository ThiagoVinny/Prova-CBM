# Prova CBM-SE — Sistema de Ocorrências e Despachos (Laravel + Docker + React)

Implementação do desafio “CBM” com foco em **integração assíncrona**, **idempotência**, **concorrência**, **auditoria** e um **frontend React** consumindo a API.

---

## Stack

**Backend**
- Laravel (PHP-FPM) + Nginx
- Postgres (persistência)
- Redis (cache/fila)
- Fila: Laravel Queue com driver **database** (jobs na tabela `jobs`)

**Frontend**
- React + Vite

---

## Como rodar (Backend + Frontend)

### Pré-requisitos
- Docker + Docker Compose

### 1) Subir os containers
Na raiz do projeto:

```bash
docker compose up -d --build
```

### 2) Configurar `.env` do backend
Crie `backend/.env` a partir do exemplo:

```bash
cp backend/.env.example backend/.env
```

Edite `backend/.env` (exemplo mínimo recomendado para rodar no Docker):

```env
APP_NAME=Prova-CBM
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# API Key (usada no header X-API-Key)
API_KEY=cbm_prova_2026_key

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=cbmse
DB_USERNAME=cbm_prova
DB_PASSWORD=cbm_prova_2026

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

REDIS_HOST=redis
REDIS_PORT=6379
```

### 3) Instalar dependências e preparar banco
```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

> O service `worker` já roda `php artisan queue:work` automaticamente no compose.

### 4) Configurar `.env` do frontend
Crie `frontend/.env`:

```env
VITE_API_BASE=http://localhost:8000/api
VITE_API_KEY=cbm_prova_2026_key
```

O compose já inicia o Vite em `localhost:5173`.

---

## Estrutura de Portas

| Serviço | Porta | Descrição |
|---|---:|---|
| Frontend (React/Vite) | 5173 | Interface web do sistema |
| Nginx (Laravel) | 8000 | Backend HTTP (API + web) |
| PostgreSQL | 5432 | Banco de dados |
| Redis | 6379 | Cache/infra (disponível no compose) |

---

## Links locais (atalhos)

- **Sistema (Frontend):** http://localhost:5173  
- **Backend (Laravel):** http://localhost:8000  
- **API Base:** http://localhost:8000/api


---

## Endpoints e autenticação

### 🔐 Autenticação (obrigatória em todas as rotas)
Todas as rotas exigem:

- `X-API-Key: <valor do API_KEY do backend>`

### 📡 Integração externa (assíncrona)
**POST** `/api/integrations/occurrences`

Headers:
- `Idempotency-Key: <string-unica>`
- `X-API-Key: <...>`

Body:
```json
{
  "externalId": "EXT-2026-000123",
  "type": "incendio_urbano",
  "description": "Incêndio em residência",
  "reportedAt": "2026-02-01T14:32:00-03:00"
}
```

Resposta (sempre assíncrona):
```json
{ "commandId": "uuid", "status": "accepted" }
```

Consultar status do processamento:
- **GET** `/api/commands/{commandId}`

---

## API interna

### Listar ocorrências (com filtro)
- **GET** `/api/occurrences?status=in_progress&type=incendio_urbano&perPage=15&page=1`

### Detalhar ocorrência (inclui histórico de despachos)
- **GET** `/api/occurrences/{id}`

### Iniciar atendimento
- **POST** `/api/occurrences/{id}/start`
- Header obrigatório: `Idempotency-Key`

### Encerrar ocorrência
- **POST** `/api/occurrences/{id}/resolve`
- Header obrigatório: `Idempotency-Key`

### Cancelar ocorrência (status)
- **PATCH** `/api/occurrences/{id}/status`
- Header obrigatório: `Idempotency-Key`
- Body:
```json
{ "status": "cancelled" }
```

### Criar despacho
- **POST** `/api/occurrences/{id}/dispatches`
- Header obrigatório: `Idempotency-Key`
- Body:
```json
{ "resourceCode": "ABT-12" }
```

### Alterar status do despacho
- **PATCH** `/api/dispatches/{id}/status`
- Header obrigatório: `Idempotency-Key`
- Body:
```json
{ "status": "en_route" }
```

---

## Regras de status e transições (concorrência + domínio)

### Occurrence
Status:
- `reported` → `in_progress` → `resolved`
- `reported` → `cancelled`
- `in_progress` → `cancelled`

Transições inválidas geram **falha do command** (status `failed`) e **não alteram** a entidade.

### Dispatch
Status:
- `assigned` → `en_route` → `on_site` → `closed`

---

## Desenho de arquitetura (placeholder)

<img width="1294" height="475" alt="Captura de tela 2026-02-17 230404" src="https://github.com/user-attachments/assets/f7d6954b-5746-4a01-8829-132f9e374fb6" />

<!--
![Diagrama de Arquitetura](docs/arquitetura.png)
-->

---

## Estratégia de integração externa

Foi aplicado o padrão **Command Inbox**:
1. A API recebe o evento externo e **registra** um comando em `command_inboxes` (`pending`).
2. Retorna **202 Accepted** com `commandId`.
3. Um **job** (queue) processa o comando:
   - cria/atualiza a ocorrência com base no `externalId`
   - registra auditoria
   - marca o comando como `processed` (ou `failed` em erro)

**Por que isso atende o enunciado:** o sistema não bloqueia o request externo e mantém rastreabilidade do processamento por `commandId`.

---

## Estratégia de idempotência (obrigatória)

### Onde a chave é armazenada?
Em banco (Postgres), na tabela:
- `command_inboxes` (campos principais: `idempotency_key`, `type`, `payload`, `status`, `processed_at`, `error`)

Há uma constraint **única**:
- `(idempotency_key, type)`

### Por quanto tempo?
Atualmente, **indefinidamente** (persistido em banco).  
Evolução sugerida: job de limpeza por TTL (ex.: manter 7/30 dias) ou arquivamento.

### Como lida com payload diferente na mesma chave?
- Para a integração externa: compara uma **assinatura hash** do payload normalizado.
- Para comandos internos: compara campos críticos do payload (ex.: `occurrenceId`, `dispatchId`, `status`, `resourceCode`).
- Se a chave já existe **com payload diferente**, retorna **409 Conflict** (sem duplicar efeito).

---

## Estratégia de concorrência (obrigatória)

O sistema se protege contra:
- **dois eventos externos chegando ao mesmo tempo**
- **dois comandos internos simultâneos**
- **transições inválidas de status**

Técnicas usadas:
1. **Constraints únicas** no banco:
   - `occurrences.external_id` (evita duplicar ocorrência)
   - `command_inboxes.(idempotency_key, type)` (evita duplicar comando)
2. **Transações** + **lock pessimista (`SELECT ... FOR UPDATE`)** durante o processamento:
   - trava o `command_inbox` do commandId
   - trava a ocorrência/despacho alvo antes de alterar status/criar despacho
3. **State machine** no domínio:
   - `transitionTo()` valida transições permitidas
   - transição inválida lança `DomainException` → command vai para `failed`

---

## Auditoria (obrigatória)

Toda mudança relevante gera registro em `audit_logs` com:
- `entity_type` (`occurrence` / `dispatch`)
- `entity_id`
- `action`
- `before` / `after` (JSON)
- `meta` (JSON: `source`, `commandId`, `idempotencyKey`, etc.)

Isso permite rastrear:
- o que mudou
- quando mudou
- qual origem (ex.: `sistema_externo` / `operador_web`)
- qual comando disparou a alteração

---

## Frontend (obrigatório)

Interface React simples com:
- lista de ocorrências (filtros por status/tipo + busca local)
- detalhe da ocorrência
- histórico de despachos
- status atual e ações (start/resolve/cancel + criar despacho + mudar status do despacho)

Configuração:
- `VITE_API_BASE` e `VITE_API_KEY` no `frontend/.env`

---

## Testes automatizados (mínimo)

Cobertos:
- Idempotência da integração externa
- Mudança de status válida/inválida
- Auditoria sendo gerada
- Concorrência simulada (criação concorrente por mesmo `externalId` e duplicidade de comandos internos)

Rodar:
```bash
docker compose exec app php artisan test
# ou
docker compose exec app ./vendor/bin/phpunit
```

---

## Pontos de falha e recuperação

### Worker parado / fila acumulando
- Sintoma: `command` fica `pending`
- Recuperação: subir/checar o `worker`
```bash
docker compose logs -f worker
docker compose up -d worker
```

### Command falha
- `GET /api/commands/{commandId}` retorna `status: failed` e `error`
- Normalmente ligado a transição inválida, dados inválidos ou erro inesperado

### Banco fora
- API não consegue persistir comandos/ocorrências.
- Recuperação: restabelecer Postgres e reprocessar fluxos (o design idempotente ajuda).

---

## O que ficou de fora (intencional / backlog)

- Retentativa automática com backoff (hoje o worker está com `--tries=1`)
- Dead-letter queue / fila de “não entregues”
- Cache de leitura (ex.: lista de ocorrências)
- Observabilidade completa (correlation-id, tracing distribuído, métricas)
- Documentação OpenAPI/Swagger
- Endpoint de consulta de audit logs (auditoria está registrada, mas não exposta via API)

---

## Como poderia evoluir na corporação (CBM)

- Substituir queue database por Redis/RabbitMQ para maior escala
- Introduzir retries/backoff + DLQ
- Expor auditoria e relatórios operacionais
- Controle de acesso (perfis: operador, supervisor, auditor)
- Observabilidade (logs estruturados, correlation-id por request/command, dashboards)
- Normalização do catálogo de tipos de ocorrência e regras por tipo (ex.: prazos/SLAs)

---

## Checklist do enunciado (o que foi atendido)

- [x] Processamento assíncrono (integração e comandos internos)
- [x] Idempotência (Idempotency-Key + bloqueio por type)
- [x] Concorrência (constraints + transação + lockForUpdate + state machine)
- [x] Auditoria (status de ocorrência e despacho + metadados de origem)
- [x] Frontend React consumindo API
- [x] Testes mínimos (idempotência, status válido/inválido, auditoria, concorrência simulada)
