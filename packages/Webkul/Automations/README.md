# Automações (Fase 1 + construtor visual)

Fluxos que rodam sozinhos nos leads do CRM, configurados em **Configurações → Automação → Automações**.
Desde a "Fase 1 do construtor visual" cada regra é editada num **canvas de arrastar e soltar** (Vue Flow,
estilo Salesbot/ManyChat): um **Gatilho** começa o fluxo, **Condições** bifurcam em **Sim/Não** e **Ações**
executam em ordem. A lista de regras continua como tabela (com a coluna "Fluxo" / "Regra antiga").

O que cada bloco faz é o mesmo das regras "Quando… Se… Então…" da Fase 1, descritas abaixo.

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
- **Modelos de fluxo** ("Usar modelo"; antes "receitas"), sempre criados em modo **Teste** e com os funis adivinhados pelo nome
  (Pré-Vendas, Closer) quando existem — confira os seletores antes de salvar:
  1. Reunião agendada no Pré-Vendas → transferir para o Closer (1ª etapa);
  2. Lead novo → atribuir responsável + avisar no WhatsApp;
  3. Entrou na etapa X → tarefa de follow-up em 1 dia.
  **Ganho no Closer → Onboarding** e **Perdido → Recuperação NÃO são receitas** (decisão do usuário,
  opção A): transferir um lead ganho/perdido tira-o dos relatórios (veja o risco abaixo). Para esses dois
  casos use **Configurações → Regras de funil**, que abre um card novo no funil seguinte.

## Construtor visual (fluxos)

### Tela

- **Paleta** à esquerda (gatilhos, condições, ações): arraste um bloco para a tela, ou toque/clique nele para
  adicioná-lo no centro (é o jeito de adicionar no celular). **Tela** no meio (zoom, pan com mouse ou toque,
  minimapa opcional, "Centralizar", "Desfazer" — também Ctrl/Cmd+Z). **Painel** à direita: configuração do
  bloco ou da ligação selecionada, e "Simular em um lead".
- Ligue a bolinha de baixo de um bloco à bolinha de cima de outro. A condição tem duas saídas: **Sim** (verde) e
  **Não** (vermelha). Cada saída tem uma ligação só (uma nova substitui a antiga). Delete/Backspace ou o botão do
  painel apagam o bloco/ligação selecionado.
- Blocos com problema ficam com borda vermelha e "!" (passe o mouse para ver); o painel lista os problemas.
- Botões: **Salvar rascunho** (salva desligado, mesmo inválido), **Salvar em Teste** e **Ativar** (só com o
  fluxo válido). Na lista, os botões Ligada/Teste também recusam um fluxo inválido.
- **Simular** grava uma execução de teste e pinta de verde no canvas o caminho percorrido. No Histórico, o botão
  **"Ver no fluxo"** abre o fluxo com o caminho daquela execução destacado; os passos mostram, em ordem,
  gatilho → condições (com o ramo Sim/Não) → ações.
- Condições disponíveis num bloco: funil, etapa, origem, tem a etiqueta, responsável, **valor do lead**
  (maior/menor/igual…) e campo do lead (**é igual a** ou **contém** — use "contém" com os campos de UTM). Um
  bloco pode juntar até 10 regras com **E** (todas) ou **OU** (basta uma).
- Em tela pequena a paleta vira uma faixa rolável e tela/painel ficam um embaixo do outro.
- Avisos reaproveitados: transferência a partir de uma etapa de ganho (relatórios), WhatsApp de avisos não
  configurado, segredo do webhook mantido.

### Formato do grafo (`automation_rules.flow`, JSON)

```json
{
  "version": 1,
  "nodes": [
    {"id": "t", "type": "trigger", "config": {"trigger_type": "stage_entered", "pipeline_id": 1, "stage_id": 2, "from_stage_id": null}, "position": {"x": 0, "y": 0}},
    {"id": "c", "type": "condition", "config": {"match": "all", "rules": [{"type": "tag", "tag_id": 3}]}, "end": ["no"], "position": {"x": 0, "y": 140}},
    {"id": "a", "type": "action", "config": {"type": "add_tag", "tag_id": 4}, "label": "opcional", "position": {"x": 0, "y": 280}}
  ],
  "edges": [
    {"id": "e1", "source": "t", "target": "c", "branch": null},
    {"id": "e2", "source": "c", "target": "a", "branch": "yes"}
  ],
  "meta": {"source": "builder | legacy | recipe"}
}
```

- `type`: `trigger` | `condition` | `action`. `config` do gatilho = `trigger_type` + a config antiga do gatilho;
  da condição = `match` (`all`/`any`) + `rules` (as condições antigas + `lead_value`); da ação = a ação antiga.
- Ligações: gatilho e ação têm no máximo 1 saída (`branch: null`); a condição tem `yes` e `no`. Um ramo sem
  ligação precisa estar em `end` (fim explícito).
- Extensível: blocos de espera/tempo e bot de conversa **não existem nesta fase**; entram como novos `type`
  (o validador hoje recusa tipos desconhecidos).
- As colunas antigas continuam preenchidas: `trigger_type`/`trigger_config` sempre (é por elas que o evento
  encontra as regras) e `conditions`/`actions` quando o fluxo é uma linha reta (gatilho → 1 condição "todas"
  com "Não" = fim → ações). Fluxos com ramificação ficam com `actions = []`.

### Validação (servidor, `Support/FlowValidator.php`; a tela repete as mesmas checagens ao vivo)

- **Sempre recusado (422), até em rascunho**: não é um grafo, mais de `AUTOMATIONS_MAX_NODES` blocos (30) ou
  ligações demais, id inválido/repetido, tipo de bloco/gatilho/condição/ação desconhecido, ligação para bloco
  inexistente, para si mesmo ou com ramo errado. Chaves desconhecidas são descartadas; rótulos são limpos.
- **Impede Ativar/Teste (rascunho pode ser salvo)**: não ter exatamente 1 gatilho, bloco solto (não alcançado a
  partir do gatilho), ciclo, ramo da condição sem ligação e sem "fim", nenhuma ação, ligação entrando no
  gatilho, duas ligações na mesma saída, configuração obrigatória vazia ou apontando para algo que não existe
  (mesmas regras de antes: funis/etapas/usuários/etiquetas existentes, URL de webhook pública http(s) etc.).
- Erros voltam como `flow` (do fluxo) e `flow.nodes.<id>` (de cada bloco).

### Motor

- O evento encontra as regras pelas colunas `trigger_type`/`trigger_config` (sem mudança). Regras com `flow`
  percorrem o grafo a partir do gatilho; regras sem `flow` rodam exatamente como antes.
- Condição escolhe o ramo (checada com o lead **recarregado**, então uma condição depois de uma ação vê o lead já
  alterado); ações executam em ordem; a 1ª ação que falhar interrompe o fluxo.
- **Idempotência**: as mesmas chaves de antes. A execução é "reservada" imediatamente antes da 1ª ação; o hash do
  evento inclui o id do bloco gatilho (`evt:sha1(…|node:<id>)`); "uma vez por lead" continua `once:<regra>:<lead>`
  (memória preservada na conversão). Um caminho que termina antes de qualquer ação vira "Ignorada" (`skip:…`),
  como regra antiga com condições não atendidas. Cada passo do histórico guarda `node` (id do bloco).
- Fila, proteção contra loop (profundidade 3, mesma regra não redispara na cadeia), modo Teste, Simular,
  histórico e purge: sem mudança.

### Conversão das regras antigas

```bash
php artisan automations:convert-rules --dry-run   # só lista
php artisan automations:convert-rules             # converte (idempotente: pode rodar de novo)
```

Cada regra antiga vira, **na mesma linha** (mesmo id: histórico e "uma vez por lead" continuam valendo), o fluxo
linear equivalente: Gatilho → Condição (todas as condições, "todas precisam valer", "Não" = fim) → Ação 1 → Ação 2…
Nome, status (Ligada/Desligada/Teste) e "uma vez por lead" não mudam; as colunas antigas não são tocadas. O teste
`AutomationsFlowTest` roda a mesma regra antes e depois da conversão e compara o resultado (status, passos,
estado do lead). Regras não convertidas continuam rodando no formato antigo (rollout seguro), e abrir uma regra
antiga na tela já mostra o fluxo equivalente (ela vira fluxo ao salvar).

### Build do front (Vue Flow)

- Build próprio do módulo, no padrão Admin/WebForm: `packages/Webkul/Automations/{package.json,vite.config.js}`,
  entrada `src/Resources/assets/js/flow.js`, saída **versionada** em `public/automations/build` (como
  `public/admin/build`), registrada como viter `automations` pelo service provider. Só a tela de Automações carrega
  esse JS/CSS (`vite()->set([...], 'automations')`).
- É um app Vue isolado (com o próprio Vue runtime + Vue Flow, ~108 kB gzip) montado num `<div>` da tela, para não
  depender do bundle pré-compilado do Admin.
- O Dockerfile roda só o `npm install && npm run build` da raiz; como o build do módulo está commitado, **o deploy
  não precisa de mudança**. Ao mexer nos `.vue`/`.js`/`.css` do módulo, rode e commite o build:
  ```bash
  cd packages/Webkul/Automations && npm install && npm run build
  ```

## Variáveis de ambiente

| Variável | Padrão | O que faz |
|---|---|---|
| `AUTOMATIONS_ENABLED` | `false` | Chave geral. Desligado: nada é escutado/enfileirado, sem menu, a tela responde 404. |
| `AUTOMATIONS_HIDE_NATIVE` | `false` | Esconde do menu "Configurações → Automação → Workflows" (nativo). **Só esconde**: workflows nativos já salvos continuam rodando. Obs.: o `CRM_DISABLED_FEATURES` padrão já desliga `settings.automation.workflows`. |
| `AUTOMATIONS_MAX_DEPTH` | `3` | Quantas automações podem se encadear (ver "Proteção contra loop"). |
| `AUTOMATIONS_RETENTION_DAYS` | `90` | Histórico mais velho que isso é apagado por `automations:purge` (diário, 03:30). |
| `AUTOMATIONS_MAX_RULES` | `100` | Limite de regras por CRM. |
| `AUTOMATIONS_MAX_NODES` | `30` | Limite de blocos por fluxo do construtor visual. |
| `AUTOMATIONS_WEBHOOK_TIMEOUT` | `5` | Timeout (s) da ação webhook (máx. 15). |
| `AUTOMATIONS_QUEUE` | vazio | Fila dos jobs (vazio = fila padrão). |

O aviso no WhatsApp precisa de `WHATSAPP_NOTIFY_SESSION` e do WAHA (`WAHA_API_URL`/`WAHA_API_KEY` ou
`WHATSAPP_NOTIFY_WAHA_*`). Sem isso, a ação é registrada como **Ignorada** e a regra continua (falha silenciosa,
com log `info`). `WHATSAPP_NOTIFY_TO`/`EVENTS` não são usados aqui: o destino vem da ação.

## Como ligar (cada CRM)

1. Fazer deploy do código (branch mesclada na `main`).
2. **Rodar as migrations à mão** no console de **cada** CRM (o deploy do Easypanel não roda migrations), em
   `/var/www/html`:
   ```bash
   runuser -u www-data -- php artisan migrate --force
   runuser -u www-data -- php artisan automations:convert-rules --dry-run
   runuser -u www-data -- php artisan automations:convert-rules
   ```
   Cria `automation_rules`/`automation_runs` (1ª vez) e a coluna `automation_rules.flow` (construtor visual), e
   converte as regras existentes em fluxos. Sem a migration, com o módulo ligado, os eventos só geram aviso no log
   (nada quebra o salvamento dos leads), mas a tela dá erro ao salvar. A conversão é opcional (regras antigas
   continuam rodando) e pode ser repetida.
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

- Sem gatilhos de tempo, blocos de espera, bot de conversa, rodízio de responsável nem envio de WhatsApp
  **ao lead** (Fases 2/3).
- Um fluxo com ramificação não pode ser editado por clientes antigos da API (formato `actions`): salvar no formato
  antigo transforma a regra de novo em regra antiga.
- O canvas não atualiza sozinho se outra pessoa editar o mesmo fluxo ao mesmo tempo (vale o último a salvar).
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
- Desfazer a conversão (volta ao formato antigo os fluxos em linha reta; fluxos com ramificação são listados e
  mantidos): `php artisan automations:convert-rules --revert` (`--dry-run` para só listar).
- Desfazer o construtor visual no banco: `php artisan migrate:rollback --step=1 --force` (só a migration
  `2026_11_18_000001_add_flow_to_automation_rules_table`). Antes de apagar a coluna `flow` ela **desliga** os fluxos
  com ramificação (ficariam sem ações no formato antigo); os demais voltam a rodar pelo formato antigo, que sempre
  foi mantido preenchido. Nada do histórico é apagado. Voltar o código para a versão anterior sem esse rollback
  também funciona: o código antigo ignora a coluna `flow`.
- Remover as tabelas (apaga regras e histórico): `php artisan migrate:rollback --path=packages/Webkul/Automations/src/Database/Migrations --force`.
- As mudanças já feitas nos leads (transferências, etiquetas, tarefas) **não** são revertidas automaticamente; o
  histórico de cada execução e as notas "Automação: …" mostram o que foi feito em cada lead.

## Testes

```bash
php artisan test tests/Feature/AutomationsEngineTest.php tests/Feature/AutomationsScreenTest.php tests/Feature/AutomationsFlowTest.php tests/Unit/Automations
```
