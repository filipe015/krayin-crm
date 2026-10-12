# Módulo WhatsApp (WAHA) — publicação no Easypanel

Inbox de WhatsApp dentro do Krayin, ligado a um servidor **WAHA**. Recebe e envia texto,
mostra o status de entrega (✓ ✓✓), reconhece edições e exclusões, e vincula cada conversa a um lead.

```
 WhatsApp ⇄ WAHA (já existe) ──webhook (HMAC)──▶ Krayin ──▶ MySQL
                  ▲                                 │
                  └──── sendText (X-Api-Key) ◀── fila (worker) ◀── Inbox
```

O endpoint antigo do n8n (`…/whatsapp/messages/receive`) continua funcionando sem mudanças.

## 1. Antes de começar

1. **Backup do MySQL.** A publicação cria 3 migrações e altera duas tabelas existentes
   (`whatsapp_conversations` e `whatsapp_messages`).
2. **Teste primeiro num serviço separado**, com uma cópia do banco, antes de tocar no Krayin
   que você usa no dia a dia:
   ```bash
   # no console do serviço MySQL
   mysqldump -u root -p krayin > /tmp/krayin.sql
   mysql -u root -p -e "CREATE DATABASE krayin_teste CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -u root -p krayin_teste < /tmp/krayin.sql
   ```
3. **Envie a branch para o GitHub** (o Easypanel constrói a partir dele):
   ```bash
   git push origin feat/whatsapp-inbox
   ```

## 2. Variáveis de ambiente do serviço do Krayin

Mantenha as que já existem (`APP_KEY`, `DB_*`, e-mail…) e acrescente ou ajuste:

| Variável | Valor | Observação |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://seu-krayin.exemplo.com` | O endereço público, com https |
| `APP_TIMEZONE` | `America/Sao_Paulo` | Os horários das mensagens dependem disso |
| `QUEUE_CONNECTION` | `database` | Usa as tabelas `jobs`/`failed_jobs` que já existem. Sem Redis novo |
| `QUEUE_WORKER` | `true` | O container roda o worker sozinho (padrão) |
| `WAHA_API_URL` | `https://seu-waha.exemplo.com` | Sem barra no final |
| `WAHA_API_KEY` | a chave do WAHA | **Nunca** colocar no repositório |
| `WAHA_HMAC_SECRET` | `openssl rand -hex 24` | 16+ caracteres. Tem que ser **igual** ao do webhook do WAHA |
| `RUN_MIGRATIONS` | `true` só no 1º deploy | Aplica as migrações ao iniciar. Depois volte para `false` |

Gerar os segredos: `openssl rand -hex 24`.

## 3. Volumes

- Se ainda não existir, monte um volume em `/var/www/html/storage/app` (anexos e uploads).
- Sessões de login ficam em arquivo, então **um novo deploy desloga os usuários**. É esperado.

## 4. Passo a passo

1. Backup feito (seção 1).
2. No Easypanel, no serviço do Krayin (ou no serviço de teste): **Source → branch `feat/whatsapp-inbox`**.
3. Ajuste as variáveis (seção 2) e faça o **Deploy**.
4. Confirme as migrações. No console do serviço:
   ```bash
   php artisan migrate:status | grep -i whatsapp
   ```
   As três migrações novas devem aparecer como `Ran`. Se usou `RUN_MIGRATIONS=true`, volte agora para `false`.
5. **Permissões.** Administradores com acesso total já veem tudo. Para os outros perfis, em
   Configurações → Usuários → Perfis, marque **WhatsApp**, **Gerenciar conversas** e **Enviar mensagens**.
6. Entre no Krayin: o menu **WhatsApp** deve aparecer na barra lateral.
7. **Webhook no WAHA** (painel do WAHA → sessão → Webhooks):
   - **URL:** `https://seu-krayin.exemplo.com/admin/whatsapp/waha/webhook`
   - **Eventos:** `message.any`, `message.ack`, `message.edited`, `message.revoked`, `session.status`
   - **HMAC (chave):** o mesmo valor de `WAHA_HMAC_SECRET`
   - **Retries:** política `exponential`, atraso 2 s, 5 tentativas

   Salvar reinicia a sessão do WAHA por alguns segundos. É isso que faz o número aparecer no Krayin: ele
   só conhece um número depois de receber um evento `session.status`. Se o webhook antigo do n8n de teste
   ainda estiver lá, remova.
8. **Teste ponta a ponta**, com um número de teste:
   1. Mande uma mensagem de outro celular para o número do WAHA. Ela deve aparecer no Inbox em até 5 s.
   2. Responda pelo Inbox. Deve chegar no celular, com ✓ e depois ✓✓.
   3. Leia no celular: o ✓✓ fica azul.
   4. Edite e apague uma mensagem no celular: o Inbox mostra "editada" e "Mensagem apagada".

## 5. Como conferir que está saudável

No console do serviço do Krayin:

```bash
ps aux | grep "queue:work" | grep -v grep     # o worker está de pé
php artisan schedule:list | grep -i whatsapp  # limpeza de envios travados agendada
php artisan queue:failed                      # deve estar vazio
tail -n 50 storage/logs/laravel.log           # erros da aplicação
tail -n 20 storage/logs/scheduler.log         # saída do agendador
```

## 6. Como o envio funciona (e por que ele é assim)

- O envio vai para uma **fila**. O worker roda no mesmo container do Krayin e é reiniciado a cada hora.
- Mensagens do mesmo número saem com **1,2 s a 2 s de intervalo**, para reduzir o risco de bloqueio.
- A tentativa é **única**. Reenviar automaticamente poderia mandar a mesma mensagem duas vezes se a primeira
  tivesse chegado e a resposta se perdido. Se falhar, aparece "tentar de novo" na mensagem.
- Se o WAHA **recusar** o envio, a mensagem fica como falha, com o motivo. Se o WAHA **não responder** a tempo,
  ela fica "enviando" (pode ter saído). O eco do webhook a confirma, ou, sem confirmação em 5 minutos, ela vira falha.
- O Krayin tenta o **LID** do contato e, se falhar, o **telefone**.

**Para escalar depois:** separe o worker num serviço próprio (mesma imagem, `QUEUE_WORKER=false` no serviço web e o
comando `php artisan queue:work --sleep=3 --tries=1 --timeout=75` no serviço worker). Nesse caso troque
`CACHE_DRIVER` para `redis`, porque o controle de intervalo por número usa cache compartilhado.

## 7. Voltar atrás

- **Código:** volte o serviço para a branch anterior e faça o deploy.
- **Banco:** o mais seguro é **restaurar o backup** feito antes. As migrações têm `down()`
  (`php artisan migrate:rollback`), mas ele apaga as colunas novas e troca por vazio o conteúdo nulo das mensagens
  de mídia.

## 8. Segurança

- O endpoint do webhook é público, mas só aceita requisições **assinadas** (HMAC-SHA512 do corpo). Sem a chave certa,
  responde 401. Sem `WAHA_HMAC_SECRET` de 16+ caracteres, recusa tudo.
- Os anexos passam pelo Krayin, então o navegador **nunca vê** a chave do WAHA. Só tipos seguros abrem na tela;
  qualquer outro é baixado como arquivo.
- Hoje, quem tem a permissão **WhatsApp** vê **todas** as conversas, independentemente do dono do lead.
- Se a chave do WAHA vazar (print, chat, repositório), troque-a: `WAHA_API_KEY` no WAHA e no Krayin.

## 9. Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Aparece o **instalador do Krayin** ("Bem-vindo à Instalação") | O Krayin não achou o arquivo `.env` e acha que não está instalado. **Não clique em Continuar**: o instalador recria as tabelas. No Easypanel, deixe **"Create env file"** ligado e não exclua `.env` no `.dockerignore`. Correção imediata, no console do serviço: `touch /var/www/html/storage/installed` |
| Webhook responde 401 | `WAHA_HMAC_SECRET` diferente do configurado no WAHA (confira espaços e aspas) ou com menos de 16 caracteres |
| O campo de resposta diz que o número está desconectado | O Krayin ainda não recebeu um `session.status`. Salve de novo o webhook no WAHA para reiniciar a sessão |
| Mensagens ficam em "enviando" e viram falha | O worker parou (veja `ps aux`) ou o WAHA está fora do ar. Confira `php artisan queue:failed` |
| Anexos não abrem (503) | `WAHA_API_URL` ou `WAHA_API_KEY` ausentes no Krayin |
| Horários errados | `APP_TIMEZONE` |
| Erro `no LID found` ao enviar | O WAHA não achou o contato. O Krayin já tenta o telefone em seguida; se falhar, a mensagem mostra o motivo |
| Nada acontece ao enviar | `QUEUE_CONNECTION` continua `sync`, ou o worker está desligado (`QUEUE_WORKER=false`) |


## Envio de imagens, documentos e áudios

Na Inbox, o clipe anexa um arquivo (até 16 MB) e o microfone grava uma mensagem de voz. O texto digitado vira a legenda.

| Tipo | Formatos | Como vai pelo WAHA |
|---|---|---|
| Imagem | jpg, png, webp, gif | `sendImage` (com legenda) |
| Documento | pdf, doc(x), xls(x), ppt(x), txt, csv, zip, rtf | `sendFile` (com legenda) |
| Áudio | ogg, mp3, m4a, aac, wav, webm (e gravação do navegador) | `sendVoice` com `convert` (sai como mensagem de voz) |
| Vídeo | mp4, 3gp, mov, webm | `sendVideo` com `convert` |

- O arquivo fica em `storage/app/whatsapp/outbound` até o WAHA devolver o eco da mensagem; depois a mensagem passa a apontar para o arquivo do WAHA e a cópia local é apagada.
- O limite de upload do PHP vem de `docker/uploads.ini` (20 MB).
- **Requer WAHA com envio de mídia.** Se o seu WAHA responder que `sendImage`/`sendFile`/`sendVoice` exigem a versão Plus, a mensagem fica "Falha no envio" com o motivo.
- Receber e ouvir áudio e ver imagens/documentos já funcionava: o Krayin busca o arquivo no WAHA e o mostra dentro da conversa.


## Respostas rápidas

Mensagens prontas para o atendimento. Quem tem a permissão **Gerenciar respostas rápidas** cria, edita e exclui em **WhatsApp > Respostas rápidas** (link no topo da Inbox).

- **Usar:** na conversa, digite `/` (ou clique em ⚡) e escolha com o mouse, as setas + Enter/Tab. `Esc` fecha a lista. O texto entra no campo para você revisar antes de enviar.
- **Atalho:** letras minúsculas, números, `-` ou `_` (até 30), único.
- **Variáveis:** `{{nome}}` (primeiro nome do contato), `{{nome_completo}}`, `{{telefone}}` e `{{atendente}}` (seu nome). Variável desconhecida fica como está; se o contato não tem nome, a variável some e a pontuação é ajustada.
- **Migração nova:** `2026_10_12_000001_create_whatsapp_quick_replies_table`. Rode `php artisan migrate --force` depois do deploy.
- **Permissões:** usar as respostas vem com o acesso à Inbox (`whatsapp`); o cadastro exige `whatsapp.quick-replies` em Configurações → Usuários → Perfis.


## Funil do lead na conversa

No painel da conversa, quando ela está vinculada a um lead, o bloco **Funil** mostra o funil do lead:

- **Etapa:** a lista traz as etapas do funil do próprio lead. Trocar muda a etapa do lead no Krayin, do mesmo jeito que arrastar no Kanban (mesmos eventos, `closed_at` e `stage_entered_at`). Em **Ganho** pede confirmação; em **Perdido** pede o motivo (opcional).
- **Etiquetas:** adiciona e remove as etiquetas (tags) que já existem no Krayin. Criar etiquetas novas continua sendo em Configurações.
- **Lista de conversas:** a etapa atual do lead aparece ao lado do nome.
- **Permissões:** ver o funil vem com o acesso à Inbox; mudar etapa e etiquetas exige **Gerenciar conversas**. Só leads que o usuário pode ver (equipe/hierarquia) aparecem.
- Sem migração nova.


## Criar lead pela conversa

Na conversa sem lead, o botão **Criar lead** (bloco *Lead*, ao lado de *Vincular a um lead*) abre o lead em um clique:

- **Funil e etapa:** funil padrão do Krayin, primeira etapa. Depois, mude a etapa no bloco **Funil**.
- **Origem:** `WhatsApp` (criada na primeira vez). **Tipo:** o primeiro tipo cadastrado. **Valor:** 0.
- **Responsável:** quem está atendendo a conversa (ou quem clicou, se ninguém assumiu).
- **Contato:** reaproveita a pessoa que já tem esse telefone (com ou sem o 9º dígito); se não houver, cria uma com o nome do WhatsApp (ou o número) e o telefone.
- **Sem duplicar:** se já existe um lead com esse número, ele é só vinculado à conversa. Se ele for de outra equipe, o Krayin avisa e não cria outro.
- **Permissão:** exige poder criar leads (`leads.create`) e **Gerenciar conversas**. Sem migração nova.


## Filtrar conversas por etapa e etiqueta

Abaixo da busca da lista, dois seletores filtram pelo lead da conversa:

- **Etapa:** uma etapa do funil, ou **Sem lead** para as conversas que ainda não viraram lead. Com mais de um funil, a etapa aparece como `Funil · Etapa`.
- **Etiqueta:** as tags do Krayin.
- Os dois combinam entre si e com as abas (Fila, Minhas, Todas, Fechadas), a busca, "Não lidas" e o número.
- Sem migração nova.


## "Digitando…" e contagem de não lidas na aba

- **Aba do navegador:** o título mostra `(3) WhatsApp Inbox` com o número de conversas abertas, não lidas, que estão na fila ou são suas. Em aba de fundo a lista é atualizada a cada ~15 s.
- **Digitando… / gravando áudio…:** aparece no cabeçalho da conversa aberta e no lugar da prévia na lista. Some sozinho em ~12 s sem novo sinal do WhatsApp.
- **Requer o evento no webhook do WAHA:** na sessão, em Webhooks, marque também **`presence.update`** (além de `message.any`, `message.ack`, `message.edited`, `message.revoked` e `session.status`) e salve.
- Ao abrir uma conversa, o Krayin pede ao WAHA para acompanhar a presença daquele chat (`POST /api/{sessão}/presence/{chat}/subscribe`, no máximo 1x a cada 10 min por chat). O contato só aparece como "digitando" se o WhatsApp dele compartilha essa informação.
- Sem migração nova.


## O cliente vê "digitando…"

Enquanto o atendente escreve na Inbox, o WhatsApp do cliente mostra "digitando…" (e "gravando áudio…" na gravação de voz).

- Avisa uma vez a cada ~5 s enquanto se digita e limpa depois de 4 s sem digitar, ao enviar, ao trocar de conversa ou sair da página.
- Não avisa ao digitar um atalho de resposta rápida (`/...`) nem ao restaurar um rascunho.
- **Desligar:** `WHATSAPP_SEND_TYPING=false` no ambiente do krayin (precisa implantar).
- Usa `POST /api/{sessão}/presence` do WAHA (`typing`, `recording`, `paused`). Sem migração nova.


## Botão WhatsApp do lead abre a Inbox

Em quem tem a permissão **Enviar mensagens** (`whatsapp.send`), os atalhos de WhatsApp do lead levam para a conversa dele na Inbox, em vez do `wa.me`:

- o botão verde **WhatsApp** da página do lead;
- o ícone ao lado de cada telefone do contato;
- o ícone no card do Kanban.

Quem não tem a permissão continua indo para o `wa.me`.

`GET /admin/whatsapp/inbox/open-lead/{lead}` decide o destino:

1. já existe conversa ligada ao lead: abre ela;
2. existe conversa com o mesmo telefone ainda sem lead: liga ao lead e abre;
3. nunca conversaram: cria a conversa no primeiro número conectado (`WORKING`), atribuída a quem clicou, e abre pronta para escrever.

O telefone é o primeiro do contato, só dígitos, com `55` quando faltar. Lead sem telefone responde 422. A Inbox abre com a conversa selecionada (`?c=ID`), mesmo que ela esteja em outra aba (Fila/Minhas/Todas/Fechadas).


## Agendar mensagem

No composer, o relógio 🕒 (ao lado de Enviar, quando há texto ou anexo) agenda a mensagem para uma data e hora, em vez de enviar já.

- **Escolher:** campo de data e hora (fuso do CRM, `APP_TIMEZONE`) ou os atalhos "Amanhã 9h" e "Em 1 hora". Mínimo 1 minuto à frente, máximo 1 ano.
- **Na conversa:** a mensagem fica ao final, tracejada, com "Agendada para dd/mm hh:mm", **Mudar horário** e **Cancelar agendamento**. Na lista de conversas aparece 🕒 N.
- **Envio:** o agendador (`whatsapp:send-scheduled`, a cada minuto, via `schedule:run` do cron do container) libera as vencidas e elas seguem o fluxo normal (fila, intervalo entre mensagens, ✓ e ✓✓).
- **Número desconectado:** a mensagem espera; passados 30 min do horário sem o número voltar, vira "Falha no envio" (com **tentar de novo**).
- Não altera a conversa (última mensagem, status) até ser enviada. Funciona com texto, imagem, documento, áudio e vídeo.
- **Atenção a anexos:** o arquivo fica em `storage/app/whatsapp/outbound` até o envio. Um deploy entre o agendamento e a hora apaga o arquivo e a mensagem falha com aviso; para texto não há esse risco.
- **Migração nova:** `2026_10_13_000001_add_scheduled_at_to_whatsapp_messages`. Rode `php artisan migrate --force` depois do deploy. Sem ela a Inbox dá erro ao abrir uma conversa.
- **Permissões:** quem pode enviar mensagens (`whatsapp.send`) agenda, muda o horário e cancela.


### Agendar pelo painel lateral

No painel direito da conversa, o bloco **Agendamentos** (🕒) lista as mensagens agendadas dela e permite preparar uma nova sem usar o composer:

1. **Novo agendamento**.
2. **Título** (opcional, só para você se organizar, ex.: "Lembrete da consulta"), **data e hora** (ou "Amanhã 9h" / "Em 1 hora") e **mensagem**.
3. **Inserir resposta rápida...** preenche o texto já com `{{nome}}` e `{{atendente}}` resolvidos.
4. **Salvar agendamento**. Cada item tem **Editar** (título, horário e texto) e **Cancelar agendamento**.

- O título também aparece em negrito na mensagem agendada, no fim da conversa, e pode ser dado pelo relógio do composer.
- O painel agenda **texto**; para agendar com arquivo, use o relógio do composer. Um agendamento com arquivo pode ter horário, título e legenda editados no painel.
- **Migração nova:** `2026_10_13_000002_add_scheduled_title_to_whatsapp_messages`. Rode `php artisan migrate --force` depois do deploy.

## API oficial do WhatsApp (Meta Cloud API) — Fase 1

Segundo canal, em paralelo ao WAHA. Cada número oficial é uma "sessão" com canal `cloud`
(o ID do número da Meta é o `external_id`; o token fica criptografado no banco).
Esta fase **não** inclui coexistência (usar o app e a API no mesmo número) — isso exige
virar Tech Provider e fica para a Fase 2.

### Variáveis de ambiente (serviço `krayin`)

| Variável | Para quê |
| --- | --- |
| `WHATSAPP_CLOUD_APP_SECRET` | "Chave secreta do app" da Meta; valida a assinatura `X-Hub-Signature-256` dos webhooks |
| `WHATSAPP_CLOUD_VERIFY_TOKEN` | texto qualquer (≥ 16 caracteres) que você inventa; a Meta o devolve ao verificar o webhook |
| `WHATSAPP_CLOUD_GRAPH_VERSION` | opcional, padrão `v23.0` |

Depois de publicar: `php artisan migrate --force` (cria `template_payload` em `whatsapp_messages`).

### Passo a passo na Meta

1. App do tipo Business com o produto **WhatsApp**; adicione o número novo em *API Setup*.
2. Em *WhatsApp → Configuration*: Callback URL = a URL exibida na tela **Conexões** do Krayin
   (`/admin/whatsapp/cloud/webhook`), Verify token = o valor de `WHATSAPP_CLOUD_VERIFY_TOKEN`;
   assine o campo **messages**.
3. Crie um usuário do sistema (Business Settings → System users) com permissões
   `whatsapp_business_messaging` e `whatsapp_business_management` e gere um token **permanente**.
4. Em **Conexões → WhatsApp oficial**, informe ID do número, ID da conta (WABA) e o token.

### Comportamento

- **Janela de 24 h:** texto livre, mídia e áudio só dentro de 24 h da última mensagem do cliente.
  Fora dela o campo de texto é bloqueado e o botão **Enviar modelo** abre os modelos APROVADOS.
- **Modelos:** listados da própria conta (cache de 5 min). Suportados: corpo com variáveis `{{n}}`,
  cabeçalho de texto sem variável e botões sem variável de URL. Os demais aparecem como não suportados
  (criação de modelos pelo CRM fica para a Fase 2).
- **Status:** ✓ enviado, ✓✓ entregue, lido e falha (com o motivo) via webhook `statuses`.
- **Mídia recebida** é servida por proxy (token nunca vai ao navegador). **Mídia enviada:** imagem
  JPEG/PNG, vídeo MP4/3GP, áudio AAC/AMR/MP3/MP4/OGG-Opus e documentos; WebM/WebP/WAV são recusados.
- Agendamentos para número oficial são reavaliados contra a janela no momento do envio.
- A API oficial não devolve eco de mensagens enviadas pela própria API (só status) e não tem "digitando…".

### Áudio gravado no navegador (API oficial)

A Meta não aceita WebM, que é o que o Chrome grava. Por isso, ao enviar uma gravação (ou um
áudio em formato recusado) por um número oficial, o servidor converte com `ffmpeg` para
OGG/Opus mono e envia como mensagem de voz. O `ffmpeg` vem na imagem Docker; se estiver em outro
caminho, defina `WHATSAPP_FFMPEG_BINARY`. Sem `ffmpeg`, o microfone fica escondido nas conversas
oficiais e o envio de gravações é recusado com aviso. Arquivos de áudio já aceitos (MP3, M4A, AAC,
OGG, AMR) anexados manualmente seguem como arquivo de áudio, sem conversão.

### Lembretes de consulta com modelo (API oficial)

- **Agendar um modelo:** em *Enviar modelo*, marque "Agendar o envio deste modelo", escolha data/hora
  (e um título opcional). Aparece em "Agendamentos" no painel lateral; ali só dá para mudar data e título
  (o texto fica como foi preenchido). O envio acontece mesmo com a janela de 24h fechada.
- **Respostas de botões → funil:** em *Conexões → Respostas de botões*, cadastre o texto do botão
  (ex.: "Confirmar") e a etapa e/ou etiqueta a aplicar. Quando o cliente toca no botão, o lead da
  conversa é movido/etiquetado. Só vale para toque em botão (não para texto digitado igual) e a etapa
  precisa ser do funil do lead. A conversa precisa estar vinculada a um lead.
- Depois do deploy: `php artisan migrate --force` (cria `whatsapp_button_rules`).

### Lembretes automáticos de consulta

Em *Conexões → Lembretes de consulta* escolha: a etapa que dispara (ex.: "Consulta agendada"), as
etapas em que os lembretes continuam valendo (ex.: "Consulta confirmada"), o tipo de atividade que marca
a consulta (padrão: Reunião), o modelo aprovado, o valor de cada variável e os horários (24h, 2h…).

- A cada minuto, `whatsapp:sync-reminders` agenda, move e cancela lembretes (mensagens agendadas do
  tipo modelo, aparecem em *Agendamentos* e podem ser canceladas à mão; um lembrete cancelado à mão
  não é recriado).
- Só considera atividades futuras, não concluídas, de leads na etapa. Lembrete cujo horário já passou
  é pulado (nunca enviado atrasado). Se a atividade muda de horário, os lembretes pendentes acompanham;
  se for concluída/excluída ou o lead sair das etapas que mantêm lembretes, os pendentes são cancelados.
- Usa a conversa do lead no número oficial; se não houver, vincula uma conversa existente com o telefone
  do lead ou cria uma (um modelo pode abrir conversa). Lead sem telefone é ignorado.
- Depois do deploy: `php artisan migrate --force` (cria `whatsapp_reminder_settings` e `whatsapp_reminders`).

### Painel de super admin: endpoint de saúde

Defina `WHATSAPP_CONTROL_KEY` (24+ caracteres, aleatória, única por instalação) e o painel passa a ler
`GET /admin/whatsapp/control/health` com o cabeçalho `X-Control-Key`. A resposta traz versão do módulo,
última execução do agendador, números conectados (status/qualidade) e contadores do mês (mensagens
recebidas, enviadas, modelos, falhas em 24h, agendadas, usuários e leads). Nenhum dado de cliente final.
Sem a variável, a rota responde 404. Opcional: `APP_VERSION` aparece como `app_version`.

### Instalar o Krayin para um novo cliente

Um projeto/serviço novo no Easypanel, banco MySQL próprio, `APP_KEY` fixo e volume em
`/var/www/html/storage/app`. Depois do deploy, **no console do app**:
`cd /var/www/html && php artisan krayin-crm:install` (nunca pela tela do navegador, que roda
`migrate:fresh`). O aviso "APP_KEY is already present" é esperado. O `docker-entrypoint.sh` cria um
`.env` vazio quando não existe, para o Krayin reconhecer a instalação a cada deploy.
