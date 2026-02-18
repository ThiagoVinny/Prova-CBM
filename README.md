# Prova Bomb (Prometheus) — Sistema de Gerenciamento de Ocorrências

Sistema para criação, acompanhamento e atualização de ocorrências e despachos com:
- Autenticação por X-API-Key
- Idempotência obrigatória em operações mutáveis
- Processamento assíncrono (API retorna 202 Accepted + commandId)
- Concorrência segura (transações + lockForUpdate + chaves únicas)
- Rastreabilidade (Command Inbox + Audit Log)

---
Para desenvolvimento local, consulte "1. Como Rodar Backend e Frontend".

---

Sumário
1. Como Rodar Backend e Frontend
2. Desenho de Arquitetura
3. Estratégia de Integração Externa
4. Padrão Outbox
5. Estratégia de Idempotência
6. Estratégia de Concorrência
7. Pontos de Falha e Recuperação
8. Como Validar Rapidamente (CURLs)
9. Testes Automatizados
10. O que ficou de fora
11. Possível Evolução na Corporação

---

1. Como Rodar Backend e Frontend

Ambiente Recomendado
Para evitar problemas de permissões e compatibilidade, recomenda-se executar em Linux (Ubuntu 22.04+).
No Windows, o ideal é usar WSL2.

Pré-requisitos
- Docker (20.10+)
- Docker Compose (2.0+)
- Git
- (Opcional, para rodar o frontend fora do Docker) Node.js 18+ / 20+

Clonando o Projeto
git clone <URL_DO_REPOSITORIO>
cd Prova-CBM

Como Rodar (Docker Compose)
A forma mais simples é subir tudo pelo Docker:
docker compose up -d --build

1.1 Configurando o Backend (.env)
O backend lê a chave da API a partir de API_KEY.

Crie o .env do backend:
cp backend/.env.example backend/.env

Edite backend/.env (principais pontos):
APP_ENV=local
APP_DEBUG=true

# A API usa essa chave no middleware "api.key"
API_KEY=cbm_prova_2026_key

# Banco (Docker / Postgres)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=cbmse
DB_USERNAME=cbm_prova
DB_PASSWORD=cbm_prova_2026

# Filas (assíncrono)
QUEUE_CONNECTION=database

Observação: o projeto já possui migration de jobs (fila database).

1.2 Rodando migrations
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

1.3 Worker (fila) — não precisa rodar na mão
Não precisa rodar php artisan queue:work manualmente se o docker-compose.yml já tiver o serviço worker.
A ideia é: subiu o docker → worker já processa os comandos.

Como conferir se o worker está rodando:
docker compose ps
docker compose logs -f worker

Se por algum motivo o teu compose ainda não tiver o worker, adicione (exemplo):

  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    container_name: cbm_worker
    working_dir: /var/www
    volumes:
      - ./backend:/var/www
    depends_on:
      - postgres
      - redis
    command: php artisan queue:work --sleep=1 --tries=1 --timeout=90
    networks:
      - cbm_network

E depois:
docker compose up -d --build

1.4 Configurando o Frontend (.env)
No Vite, a chave precisa começar com VITE_.

Crie/edite frontend/.env:
VITE_API_BASE=http://localhost:8000/api
VITE_API_KEY=cbm_prova_2026_key

VITE_API_KEY deve ser a mesma do API_KEY no backend.

1.5 Reiniciar o Vite (quando mudar .env)
O Vite lê o .env apenas quando inicia.
- Se estiver rodando via terminal: Ctrl+C e depois npm run dev de novo.
- Se estiver no Docker: docker compose restart frontend

Estrutura de Portas (Local)
Serviço | Porta | Descrição
API (Nginx → Laravel) | 8000 | Endpoints /api/...
Frontend (Vite) | 5173 | Interface React
PostgreSQL | 5432 | Banco
Redis | 6379 | Cache/apoio

---

2. Desenho de Arquitetura

Visão Geral do Sistema
O sistema é composto por:
- Frontend (React/Vite) consumindo a API
- API Laravel com middleware api.key
- Command Inbox (command_inboxes) para deduplicação + rastreio de comandos
- Fila Laravel (jobs) para processamento assíncrono
- Audit Log (audit_logs) para auditoria das ações executadas
- PostgreSQL como banco principal

[ESPACO PARA INSERIR DIAGRAMA DE ARQUITETURA AQUI]

---

3. Estratégia de Integração Externa

A integração externa foi desenhada para ser segura, rastreável e resiliente, separando:
- Recebimento/validação do request
- Processamento real via fila (assíncrono)

Princípios
- API recebe, valida e registra o comando
- Processamento desacoplado via fila
- Resposta rápida: API retorna 202 Accepted com commandId
- Rastreabilidade: comandos e resultados são auditáveis

Fluxo (alto nível)
1) Sistema Externo envia POST /api/integrations/occurrences com X-API-Key e Idempotency-Key
2) API valida autenticação, idempotência e payload
3) API registra comando no command_inboxes e despacha Job
4) API retorna 202 Accepted com commandId
5) Worker consome Job e cria/atualiza registros (occurrences / dispatches)
6) Cliente consulta GET /api/commands/{commandId} e/ou lista com GET /api/occurrences

---

4. Padrão Outbox

Nesta entrega o modelo é “Command Inbox + Queue (database)” para desacoplar o processamento.
O padrão Outbox tradicional (tabela outbox + publisher) pode ser adicionado como evolução quando houver broker externo (RabbitMQ/Kafka) e necessidade de publicação atômica de eventos.

Por que Outbox?
Sem Outbox, há risco de inconsistência:
- Persistir no banco e falhar ao publicar na fila → evento “perdido”
- Publicar na fila e falhar ao persistir → duplicidade/efeitos fora de sincronia

Como seria (evolução)
- API grava “command_inbox + outbox” na mesma transação
- Um publisher publica outbox pendente para o broker
- Um consumer processa e marca como concluído

---

5. Estratégia de Idempotência

A idempotência é obrigatória na criação de ocorrências e em todas as operações de escrita.

Requisitos
- Toda requisição de escrita (POST/PUT/PATCH) deve enviar Idempotency-Key
- O sistema registra o comando no command_inboxes antes de qualquer processamento

Rotas com idempotência (mutáveis)
- POST /api/integrations/occurrences
- POST /api/occurrences/{id}/start
- POST /api/occurrences/{id}/resolve
- POST /api/occurrences/{id}/cancel
- POST /api/occurrences/{id}/dispatches
- POST /api/dispatches/{id}/close
- PATCH /api/dispatches/{id}/status

Frontend
- O frontend React gera automaticamente Idempotency-Key em operações mutáveis.

Controle (deduplicação)
A deduplicação é feita por:
- idempotency_key (chave do cliente)
- scope_key (escopo; ex.: externalId, occurrenceId, dispatchId)
- type (tipo do comando; ex.: create_occurrence)
- payload_hash

Payload diferente com mesma chave
Se (idempotency_key + scope_key + type) já existe e o payload_hash diverge:
- retorna 409 Conflict
- não executa nenhum efeito

TTL e Cleanup
TTL padrão: 24 horas (pode ser configurável por ENV)
Estratégia de cleanup: executar por schedule/worker dedicado (fora do fluxo de request).

---

6. Estratégia de Concorrência

Para evitar race conditions, o sistema utiliza múltiplas camadas de proteção.

Mecanismos
- Transações atômicas (DB::transaction)
- Lock pessimista (lockForUpdate) no command_inboxes e nas entidades críticas
- Restrições/uniques no banco para impedir duplicidade de vínculos e transições inválidas

Garantias
- Requisições simultâneas não duplicam comandos
- Apenas um worker processa uma entidade por vez
- Transições inválidas são bloqueadas pelo domínio/validações

---

7. Pontos de Falha e Recuperação

1) Falha na API antes do commit
- Nada é persistido
- Cliente pode reenviar com a mesma Idempotency-Key

2) Falha durante a transação
- Rollback
- Reenvio seguro com a mesma Idempotency-Key

3) Falha no worker
- Comando pode ficar pending/failed
- Pode ser reprocessado (conforme estratégia de retry)

4) Falha no banco
- Escritas falham e API retorna erro
- Recuperação: retentativa após DB voltar

Estratégias de resiliência
- Idempotência (retries seguros)
- Processamento assíncrono (API não bloqueia)
- Auditoria (audit_logs)
- Rastreamento de comandos (command_inboxes)

---

8. Como Validar Rapidamente (CURLs)

Ajuste X-API-Key conforme seu backend/.env (API_KEY).

Os exemplos assumem API local em http://localhost:8000.

1) Criar ocorrência via integração externa (assíncrono)
curl -X POST "http://localhost:8000/api/integrations/occurrences" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: <API_KEY>" \
  -H "Idempotency-Key: ext-2026-000123-create" \
  -d '{
    "externalId": "EXT-2026-000123",
    "type": "incendio_urbano",
    "description": "Incêndio em residência",
    "reportedAt": "2026-02-01T14:32:00-03:00"
  }'

Resposta esperada: 202 Accepted com commandId.

2) Consultar status do comando
curl -X GET "http://localhost:8000/api/commands/<commandId>" \
  -H "X-API-Key: <API_KEY>"

3) Listar ocorrências
curl -X GET "http://localhost:8000/api/occurrences" \
  -H "X-API-Key: <API_KEY>"

---

9. Testes Automatizados

Rodando testes na API (dentro do container)
docker compose exec app php artisan test
# ou
docker compose exec app ./vendor/bin/phpunit

Os testes cobrem cenários como:
- idempotência (repetição da mesma chave)
- transições válidas/inválidas (status)
- rastreio de comandos (commands)
- concorrência (quando aplicável)

---

10. O que ficou de fora

Para manter a solução viável no contexto atual, não foram implementados (ou ficaram como evolução):
- Outbox completo com publisher separado (broker externo)
- DLQ estruturada (além de logs/status)
- Retry com backoff exponencial real
- Observabilidade avançada (métricas + tracing)
- Cleanup automático completo (apenas estratégia descrita)

---

11. Possível Evolução na Corporação
- Outbox completo + publisher + broker (RabbitMQ/Kafka)
- DLQ e painel de reprocessamento assistido
- Observabilidade (Prometheus/Grafana + OpenTelemetry)
- CQRS (read model separado)
- Tracing fim-a-fim (correlation-id)
