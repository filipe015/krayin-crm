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
| Webhook responde 401 | `WAHA_HMAC_SECRET` diferente do configurado no WAHA (confira espaços e aspas) ou com menos de 16 caracteres |
| O campo de resposta diz que o número está desconectado | O Krayin ainda não recebeu um `session.status`. Salve de novo o webhook no WAHA para reiniciar a sessão |
| Mensagens ficam em "enviando" e viram falha | O worker parou (veja `ps aux`) ou o WAHA está fora do ar. Confira `php artisan queue:failed` |
| Anexos não abrem (503) | `WAHA_API_URL` ou `WAHA_API_KEY` ausentes no Krayin |
| Horários errados | `APP_TIMEZONE` |
| Erro `no LID found` ao enviar | O WAHA não achou o contato. O Krayin já tenta o telefone em seguida; se falhar, a mensagem mostra o motivo |
| Nada acontece ao enviar | `QUEUE_CONNECTION` continua `sync`, ou o worker está desligado (`QUEUE_WORKER=false`) |
