# API REST do CRM (v1) e webhooks

Base: `https://<seu-crm>/api/v1`. Autenticação por **chave de API** (Configurações → Outras configurações →
**Integração (API)**): cabeçalho `Authorization: Bearer kcrm_…` (ou `X-Api-Key`). Chaves **somente leitura** só fazem `GET`;
as de **leitura e escrita** também criam e alteram. Limite: 120 requisições por minuto. Respostas em JSON
(`{"data": …, "meta": …}`); erros em `{"error": "codigo", "message": "texto"}` (401 sem chave, 403 sem permissão,
404, 409 conflito de horário, 422 dados inválidos).

## Leitura
| Endpoint | O que devolve |
| --- | --- |
| `GET /ping` | confere a chave; nome, versão e fuso da instalação |
| `GET /pipelines` | funis com etapas (`id`, `name`, `code`: `won`/`lost` marcam fechamento) |
| `GET /users` · `/sources` · `/tags` | responsáveis ativos, origens e etiquetas |
| `GET /leads` | lista paginada. Filtros: `q`, `pipeline_id`, `stage_id`, `status` (open/won/lost), `source_id`, `user_id`, `email`, `phone`, `created_from/to`, `closed_from/to`, `updated_since`, `per_page` (≤100), `page` |
| `GET /leads/{id}` | negócio completo + últimas atividades |
| `GET /appointments?start=&end=` | compromissos e retornos (`kind=appointment|followup|all`, `user_id`, `lead_id`, `include_done`; até 93 dias) |
| `GET /reports/sales-cycle?mode=closing|scheduling&start=&end=` | média, mediana, total e série do ciclo de vendas (mesmos números do dashboard) |
| `GET /reports/leads-summary?start=&end=&pipeline_id=` | criados, ganhos (com valor), perdidos, abertos, por etapa e por origem |

## Escrita (chave com permissão de escrita)
| Endpoint | Corpo |
| --- | --- |
| `POST /leads` | `title` (obrig.), `value`, `description`, `pipeline_id`/`stage_id`, `source_id`, `type_id`, `user_id`, `expected_close_date`, `custom` (campos personalizados por código), e a pessoa: `person_id` **ou** `person: {name, email, phone}`. A pessoa é **reaproveitada** se o e-mail ou telefone já existir (`person_existed`). `on_duplicate: "return"` devolve o negócio aberto da pessoa em vez de criar outro (`created: false`). |
| `PATCH /leads/{id}/stage` | `stage_id` (do funil do negócio), `lost_reason`, `value` (ao ganhar) |
| `POST /leads/{id}/notes` | `comment` |
| `POST /leads/{id}/appointments` | `start` (ISO 8601; sem fuso = horário da instalação), `user_id` (número ou `"auto"` = rodízio/menos ocupado da Agenda), `duration` (15–120), `title`, `location`, `comment`, `ignore_conflict`; `kind: "followup"` cria um **retomar contato** (só data, sem conflito nem lembrete) |
| `POST /appointments/{id}/done` | marca como concluído |

Regras iguais às da tela: agendamento com conflito devolve 409 com os horários; o ciclo de vendas usa a primeira Reunião criada.

## Webhooks de saída
Cadastrados em **Integração (API) → Webhooks**. Eventos: `lead.created`, `lead.stage_changed`, `lead.won`, `lead.lost`,
`appointment.created`, `appointment.done`, `followup.created` (ou todos). Corpo (`POST`, JSON):

```json
{"id": "uuid", "event": "lead.stage_changed", "occurred_at": "2026-10-13T15:52:55-03:00", "source": "Krayin CRM",
 "data": {"lead": { … mesmo formato de GET /leads/{id} … }, "from_stage": {"id": 1, "name": "…", "code": "…"}}}
```

Cabeçalhos: `X-CRM-Event`, `X-CRM-Delivery` (id único), `X-CRM-Timestamp`, `X-CRM-Signature` = `sha256=` + HMAC-SHA256 de
`"{timestamp}.{corpo bruto}"` com o segredo do webhook. Valide a assinatura e recuse timestamps antigos (> 5 min).
Resposta 2xx = entregue; o painel mostra o último status. Os envios saem **depois** da resposta ao usuário, sem repetição
automática (use `GET /leads?updated_since=` para reconciliar).

Atenção: uma automação que **cria** leads pela API também dispara `lead.created`; filtre pela origem para não entrar em ciclo.
