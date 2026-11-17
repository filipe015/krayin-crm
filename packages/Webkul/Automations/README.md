# Automações (Fase 1)

Regras **"Quando… Se… Então…"** que rodam sozinhas nos leads do CRM, configuradas em
**Configurações → Automação → Automações** (formulário simples, sem canvas).

- **Quando** (gatilho): lead entrou em uma etapa (funil + etapa, opcionalmente "vindo da etapa X"),
  lead criado (filtros opcionais: origem e canal = tipo do lead), etiqueta adicionada, atividade criada
  (tipo: reunião, ligação, tarefa…).
- **Se** (condições opcionais, todas precisam valer): funil, etapa, origem, tem a etiqueta, responsável,
  campo do lead = valor. São checadas com o lead **como está quando a regra roda**.
- **Então** (1 a 10 ações, em ordem; a 1ª que falhar interrompe as seguintes):
  - `move_stage` – mover de etapa no mesmo funil (para perder use `mark_lost`);
  - `transfer_pipeline` – **transfere o mesmo lead** (mesmo id) para outro funil/etapa e grava uma nota
    "Transferido do funil X (etapa Y) para o funil Z (etapa W) por automação 'Nome'";
  - `assign_owner` – atribui um usuário fixo (ativo);
  - `add_tag` – adiciona etiqueta;
  - `create_task` – cria atividade do tipo tarefa (título com placeholders, vencimento em N dias,
    responsável = dono do lead ou usuário fixo);
  - `notify_whatsapp` – aviso de texto livre para a **equipe** (número(s) ou grupo `…@g.us`) usando o mesmo
    canal dos avisos do módulo WhatsApp (`SendAgentNotification`, via WAHA). Placeholders: `{{lead}}`,
    `{{nome}}`, `{{etapa}}`, `{{funil}}`, `{{responsavel}}`, `{{link}}`;
  - `webhook` – POST JSON com os dados básicos do lead (id, título, valor, funil, etapa, responsável, origem,
    etiquetas, nome/e-mails/telefones do contato, link). Cabeçalho opcional `X-Automation-Secret`, timeout de
    5 s, **uma tentativa só**, sem seguir redirecionamentos;
  - `mark_lost` – move para a etapa `lost` do funil atual, com o motivo de perda escolhido (se houver).
- **Status da regra**: Ligada, Desligada ou **Teste** (avalia gatilho, condições e ações e grava no histórico o
  que faria, sem alterar nada). Botão **"Simular em um lead"** (informando o ID) na tela da regra: mesma coisa,
  sob demanda, em qualquer status.
- **Rodar só uma vez por lead** (padrão ligado): depois da 1ª execução real (com sucesso ou falha) a regra não
  roda de novo no mesmo lead.
- **Histórico** (aba Histórico): cada execução com regra, lead, gatilho, status (OK / Ignorada / Falhou /
  Teste), passos, erro e duração; filtros por regra, status e lead. Só aparecem execuções de leads que o
  usuário pode ver (`bouncer()->getAuthorizedUserIds()` e funis permitidos).
- **Receitas** ("Usar receita"), sempre criadas em modo **Teste** e com os funis adivinhados pelo nome
  (Pré-Vendas, Closer, Onboarding, Recuperação) quando existem — confira os seletores antes de salvar:
  1. Reunião agendada no Pré-Vendas → transferir para o Closer (1ª etapa);
  2. Ganho no Closer → transferir para o Onboarding (**leia o risco de relatórios abaixo**);
  3. Perdido no Pré-Vendas → transferir para a Recuperação;
  4. Lead novo → atribuir responsável + avisar no WhatsApp;
  5. Entrou na etapa X → tarefa de follow-up em 1 dia.

## Variáveis de ambiente

| Variável | Padrão | O que faz |
|---|---|---|
| `AUTOMATIONS_ENABLED` | `false` | Chave geral. Desligado: nada é escutado/enfileirado, sem menu, a tela responde 404. |
| `AUTOMATIONS_HIDE_NATIVE` | `false` | Esconde do menu "Configurações → Automação → Workflows" (nativo). **Só esconde**: workflows nativos já salvos continuam rodando. Obs.: o `CRM_DISABLED_FEATURES` padrão já desliga `settings.automation.workflows`. |
| `AUTOMATIONS_MAX_DEPTH` | `3` | Quantas automações podem se encadear (ver "Proteção contra loop"). |
| `AUTOMATIONS_RETENTION_DAYS` | `90` | Histórico mais velho que isso é apagado por `automations:purge` (diário, 03:30). |
| `AUTOMATIONS_MAX_RULES` | `100` | Limite de regras por CRM. |
| `AUTOMATIONS_WEBHOOK_TIMEOUT` | `5` | Timeout (s) da ação webhook (máx. 15). |
| `AUTOMATIONS_QUEUE` | vazio | Fila dos jobs (vazio = fila padrão). |

O aviso no WhatsApp precisa de `WHATSAPP_NOTIFY_SESSION` e do WAHA (`WAHA_API_URL`/`WAHA_API_KEY` ou
`WHATSAPP_NOTIFY_WAHA_*`). Sem isso, a ação é registrada como **Ignorada** e a regra continua (falha silenciosa,
com log `info`). `WHATSAPP_NOTIFY_TO`/`EVENTS` não são usados aqui: o destino vem da ação.

## Como ligar (cada CRM)

1. Fazer deploy do código (branch mesclada na `main`).
2. **Rodar as migrations à mão** no console do CRM (o deploy do Easypanel não roda migrations):
   ```bash
   php artisan migrate --force
   ```
   Cria `automation_rules` e `automation_runs`. Sem isso, com o módulo ligado, os eventos só geram aviso no log
   (nada quebra o salvamento dos leads), mas a tela dá erro.
3. Definir `AUTOMATIONS_ENABLED=true` (e, se quiser, `AUTOMATIONS_HIDE_NATIVE=true`) nas variáveis do serviço e
   reiniciar. Se usar `config:cache`, rodar `php artisan config:clear && php artisan config:cache`.
4. Dar a permissão "Automações" (Configurações → Usuários → Perfis) a quem vai configurar. Perfis "todas as
   permissões" já têm. A permissão aparece no editor de perfis mesmo com o módulo desligado.
5. Conferir se o **worker da fila** (`QUEUE_WORKER=true`, fila `database`) está rodando: as regras rodam em jobs.
   O **scheduler** já roda a cada minuto (o `automations:purge` entra nele).
6. Criar as regras em modo **Teste**, olhar o Histórico por alguns dias e só então mudar para **Ligada**.

## Como funciona por dentro

- **Detecção de mudança de etapa**: pelo evento `updated` do model `Lead` (`wasChanged('lead_pipeline_stage_id')`,
  etapa antiga = `getOriginal`). Isso cobre Kanban, página do lead, edição, API, Inbox do WhatsApp, regras de botão
  e as próprias transferências feitas por automação — inclusive caminhos que não disparam `lead.update.before/after`.
  Não cobre alterações feitas direto no banco (`DB::table('leads')->update`), como a importação CSV e a exclusão de etapa.
- **Lead criado**: evento `lead.create.after` (formulário, criação rápida, API, WebForm, WhatsApp, Regras de funil).
  Um lead criado direto numa etapa **não** dispara "entrou na etapa" — use o gatilho "Lead criado".
- **Etiqueta / atividade**: eventos `lead.tag.attached` e `lead.activity.attached` (pivôs `LeadTag`/`LeadActivity`).
- Cada evento × regra compatível vira **1 job** (`RunAutomationRule`, `tries = 1`, despachado `afterCommit`).
  Tudo é `try/catch` + log: **erro em automação nunca impede salvar o lead**.
- **Idempotência**: `automation_runs.idempotency_key` é única. Execução real de um evento = `evt:<sha1(regra|lead|gatilho|contexto)>`
  (o contexto tem um id do evento), regra "uma vez por lead" = `once:<regra>:<lead>`, teste = `dry:…`,
  condição não atendida = `skip:…`. O mesmo job entregue duas vezes não faz nada na 2ª.
- **Proteção contra loop** (`AutomationContext`): uma mudança feita por pessoa/API tem profundidade 0; a regra que ela
  dispara roda na profundidade 1; mudanças feitas pelas ações dela disparam regras na profundidade 2, e assim por diante.
  Eventos na profundidade `AUTOMATIONS_MAX_DEPTH` (3) **não disparam mais nada**, e uma regra **nunca dispara de novo no
  mesmo lead dentro da mesma cadeia** (A → B → A para no 2º A). Os cortes ficam no histórico como "Ignorada" com o motivo.
- **Ator**: atividades/notas criadas pela automação não inventam usuário (`user_id` vazio na nota; na tarefa, o
  `user_id` é o responsável escolhido) e trazem o texto **"Automação: <nome da regra>"**. O histórico do lead
  (atividades "system" do Krayin) registra as mudanças de campo sem usuário.
- Mudanças feitas pelas ações disparam `lead.update.before/after` como o Kanban: Workflows nativos e webhooks da API
  (Configurações → API) reagem a elas também.

## Transferência e relatórios de ganho (IMPORTANTE)

A transferência mantém o mesmo lead (id, atividades, conversas de WhatsApp ligadas por `lead_id`, origem/UTM,
etiquetas, valor) e **preserva o `closed_at`** de um lead ganho/perdido (não "reabre" a data).

Porém, o código atual conta ganhos **pela etapa atual**: o Dashboard (`Admin/Helpers/Reporting/Lead`) e as Metas
(`Goals/Support/GoalActuals::sales`) contam leads cuja etapa **atual** tem `code = won`, em funis que "contam nos
relatórios". Portanto, **transferir um lead ganho do Closer para o Onboarding tira esse ganho dos relatórios e das
Metas** (o mesmo vale para perdidos transferidos para a Recuperação, nas métricas de perda). Além disso, se depois
alguém mover o lead no Onboarding pelo Kanban, o `LeadRepository` zera o `closed_at` (etapa aberta).

Enquanto isso não muda, para "Ganho no Closer → Onboarding" prefira **Configurações → Regras de funil** (abre um
card novo e mantém o ganho no Closer). A tela mostra esse aviso quando a regra transfere a partir de uma etapa de ganho.
Uma Fase 2 pode gravar o ganho em `lead_events` e fazer Dashboard/Metas contarem por evento.

Também: se o Closer tiver uma Regra de funil (clona ao ganhar) **e** uma automação de transferência, o lead vai
para o Onboarding duas vezes (o original transferido + o clone). Use só uma das duas.

## Limitações (Fase 1)

- Sem gatilhos de tempo, atrasos, rodízio de responsável nem envio de WhatsApp **ao lead** (Fases 2/3).
- "Canal" no gatilho "Lead criado" = **tipo do lead** (`lead_type_id`).
- Condição "campo = valor" compara texto (sem diferenciar maiúsculas); para campos de opção aceita o id ou o rótulo.
- A ação webhook bloqueia `localhost` e IPs privados escritos na URL, mas não resolve DNS (um domínio que aponte para
  IP interno não é detectado).
- Com `QUEUE_CONNECTION=sync` (ambiente local) as regras rodam dentro da requisição; em produção (fila `database`) rodam
  segundos depois, no worker. No Kanban, um card movido por uma automação só muda de lugar ao recarregar a tela.
- Leads transferidos para um funil que o responsável não pode ver (usuário com funis restritos) somem da visão dele.
- O `automations:purge` mantém as linhas `once:…` (são a memória do "uma vez por lead").

## Como desfazer

- Desligar uma regra: botão **Desligada** na lista (nada roda; histórico fica).
- Desligar o módulo todo: `AUTOMATIONS_ENABLED=false` e reiniciar. Nada mais é escutado; tabelas ficam.
- Mostrar de novo o Workflows nativo: `AUTOMATIONS_HIDE_NATIVE=false`.
- Remover as tabelas (apaga regras e histórico): `php artisan migrate:rollback --path=packages/Webkul/Automations/src/Database/Migrations --force`.
- As mudanças já feitas nos leads (transferências, etiquetas, tarefas) **não** são revertidas automaticamente; o
  histórico de cada execução e as notas "Automação: …" mostram o que foi feito em cada lead.

## Testes

```bash
php artisan test tests/Feature/AutomationsEngineTest.php tests/Feature/AutomationsScreenTest.php tests/Unit/Automations
```
