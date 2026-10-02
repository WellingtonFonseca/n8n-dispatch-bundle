# Status do disparo (callback) — guia para montar no n8n

Este guia explica como o Mautic pergunta ao n8n **como cada disparo terminou**
(Email, SMS e HSM) e o que o n8n precisa responder. O resultado aparece no card
do disparo, na Timeline do contato, abaixo de **Body** e **Response**.

## Como funciona, em uma frase

O Mautic guarda os ids que o n8n devolveu no disparo e, de tempos em tempos,
manda ao **mesmo webhook de hoje** a lista dos ids que ainda estão sem resposta
final. O n8n consulta o Mirror (e a Meta, no HSM) e responde com o resultado de
cada id.

```
Mautic (comando agendado)                         n8n
        │  POST webhook                            │
        │  X-N8n-Dispatch-Action: email.status     │
        │  { "items": [ {logSendEmailId: 1}, … ] } │
        ├─────────────────────────────────────────▶│  consulta o Mirror
        │                                          │
        │◀─────────────────────────────────────────┤
        │  { "items": [ {logSendEmailId: 1,        │
        │               outcome: "success"}, … ] } │
        ▼
   grava e mostra no card
```

O n8n **não precisa** de tabela própria nem de workflow agendado: quem sabe o que
está pendente é o Mautic.

## 1. O que o disparo precisa devolver (já existe, com um acréscimo no HSM)

Na resposta do `*.send`, o n8n devolve o id do registro no Mirror (como já faz).
O Mautic usa esse id para acompanhar o disparo depois.

| Canal | Resposta do `*.send` |
|---|---|
| Email | `{ "logSendEmailId": 4298591 }` |
| SMS | `{ "logSendSmsId": 4298591 }` |
| HSM | `{ "uuid": "5c48a721-8cb1-43c7-ac46-048ce65b4233", "logSendHsmId": 4298591 }` |

- Pode vir na raiz ou dentro de `body` (como o nó "Respond to Webhook" já faz).
- **HSM: o `uuid` é novo para o Mautic.** Ele guarda os dois: o `logSendHsmId` é o
  status do **Mirror** e o `uuid` é o status no **broker da Meta**. Cada um é
  acompanhado separadamente.
- Só é acompanhado o disparo que o n8n respondeu com HTTP 2xx **e** devolveu um id.
  Disparo com erro na ida não tem o que acompanhar.
- Disparos em modo `test` ou `paused` não vão ao n8n e não são acompanhados.

## 2. A pergunta do Mautic (nova ação no webhook)

Mesmo webhook, mesmo token (`X-N8n-Dispatch-Token`). O que muda é o header:

| Header `X-N8n-Dispatch-Action` | Quando |
|---|---|
| `email.status` | ids de Email pendentes |
| `sms.status` | ids de SMS pendentes |
| `hsm.status` | ids de HSM pendentes |

No n8n, o nó Webhook (ou um Switch logo depois dele) separa pelo header, como já é
feito com `email.send`, `sms.send` e `hsm.send`.

Corpo, **em lote**:

```json
{ "items": [ { "logSendEmailId": 4298591 }, { "logSendEmailId": 4298592 } ] }
```

SMS usa `logSendSmsId`. No HSM, os dois ids do mesmo disparo vêm no mesmo item:

```json
{ "items": [
  { "logSendHsmId": 4298593, "logSendHsmUuid": "5c48a721-8cb1-43c7-ac46-048ce65b4233" },
  { "logSendHsmId": 4298600 }
] }
```

- O item do HSM pode trazer **só um** dos dois ids (o outro já foi resolvido).
- Números chegam como número e o uuid como texto.
- Cada rodada pergunta no máximo 200 ids por canal (configurável).

## 3. A resposta do n8n (HTTP 200)

```json
{ "items": [
  { "logSendEmailId": 4298591, "outcome": "success" },
  { "logSendEmailId": 4298592, "outcome": "error", "message": "caixa cheia" },
  { "logSendEmailId": 4298593, "outcome": "pending" }
] }
```

| Campo | Regra |
|---|---|
| id (`logSendEmailId`, `logSendSmsId`, `logSendHsmId` ou `logSendHsmUuid`) | **Obrigatório.** O mesmo valor que o Mautic enviou. |
| `outcome` | **Obrigatório:** `success`, `error` ou `pending`. |
| `message` | Opcional. Motivo, mostrado no card (útil no `error`). |
| outros campos | Opcionais. São guardados junto, mas não aparecem no card. |

- **Devolva sempre o estado atual**, mesmo que seja o mesmo da vez anterior
  (`pending` de novo, por exemplo). O n8n não precisa lembrar o que já respondeu.
- Também é aceito com a lista dentro de `body`: `{ "body": { "items": [...] } }`.
- **HSM: um item por ponta**, cada um com o seu id e o seu `outcome`:

```json
{ "items": [
  { "logSendHsmId": 4298593, "outcome": "success" },
  { "logSendHsmUuid": "5c48a721-8cb1-43c7-ac46-048ce65b4233", "outcome": "pending" }
] }
```

  Os dois são independentes: pode vir só um, ou cada um com um `outcome`
  diferente. O que não vier continua pendente.

### O que cada `outcome` significa para vocês

Para o disparo, o que interessa é "o nosso lado terminou?". Não é preciso
distinguir entregue/lido:

| `outcome` | Use quando |
|---|---|
| `success` | O Mirror registrou o envio / a Meta aceitou a mensagem. Acabou a nossa parte. |
| `error` | Falhou. Mande o motivo em `message`. |
| `pending` | Ainda sem resposta final. O Mautic pergunta de novo na próxima rodada. |

## 4. O que o Mautic faz com a resposta

- **Mudou o `outcome`** (por exemplo `pending` → `error`): grava uma linha no
  histórico e atualiza o card.
- **Mesmo `outcome` de antes** (por exemplo `pending` de novo): **não grava nada
  novo**. Só atualiza "verificado N vezes, última em …". Ficar 6 horas em
  `pending` não enche o histórico.
- **`success` e `error` saem da fila.** Um `error` pode ser reconsultado depois
  (veja a seção 6) e, se virar `success`, o card mostra o sucesso e o erro antigo
  fica no histórico.
- A `message` pertence ao `outcome` que veio com ela: ao virar `success`, a
  mensagem do erro some do card (continua no histórico).
- **Item que não veio na resposta:** continua pendente, sem erro.
- **Id que o Mautic não conhece** ou **`outcome` inválido:** descartado e contado
  no resultado do comando; não derruba os outros itens do lote.
- **HTTP 4xx/5xx, timeout ou corpo que não seja JSON com `items`:** o lote inteiro
  é ignorado, nada muda e a próxima rodada pergunta de novo.
- Um id é perguntado por no máximo 7 dias depois do disparo (configurável). Depois
  disso, deixa de ser perguntado e fica pendente para sempre.

## 5. O que aparece no card da Timeline

Abaixo de **Body** e **Response**, um bloco **Callback**:

- Email e SMS: um selo (`Aguardando`, `Sucesso` ou `Erro`), a data da última
  mudança, "verificado N vezes, última em …" e a mensagem do erro, se houver.
- HSM: duas linhas, **Mirror** e **Meta**, cada uma com o seu selo.
- Um "Histórico (N)" recolhível, quando houve mudança de resultado, com todas as
  respostas anteriores.

Isto **não altera** o status do evento da campanha nem o registro de envio do
email: o evento continua como "passou" se a ida deu 2xx. O callback é só
informação no card.

## 6. Como o Mautic roda isso sozinho (liga/desliga e intervalo)

**O plugin se agenda sozinho: não é preciso criar nenhum cron.** Ele pega carona no
cron padrão do Mautic (o mesmo que todo Mautic precisa ter para as campanhas
funcionarem, e sem o qual nenhum disparo acontece): toda vez que um desses
comandos começa (`mautic:campaigns:trigger`, `mautic:campaigns:update` ou
`mautic:segments:update`), o plugin vê se está na hora e, se estiver, dispara o
status poll em segundo plano, sem atrasar o comando que o acordou.

A configuração fica em **Settings > Plugins > N8n Dispatch > aba Features**:

| Campo | Padrão | Para quê |
|---|---|---|
| Verificar o status dos disparos (callback) | **Desligado** | Liga/desliga. Ligue para o Mautic começar a perguntar ao n8n. |
| Verificar a cada (minutos) | 60 | `60` = 1 hora, `120` = 2 horas. Mínimo 5, máximo 1440. |
| Última rodada | (só leitura) | Data, resultado (ok/erro) e o resumo da última rodada agendada. |

- **Mudar o intervalo** é só editar o campo e salvar. Vale já no próximo ciclo do
  cron do Mautic, sem reiniciar nada.
- **O intervalo não pode ser mais rápido que o cron do Mautic.** A imagem oficial
  roda esses comandos a cada 15 minutos; com 5 minutos configurados, na prática
  roda a cada 15. Intervalos de 1 ou 2 horas não sofrem com isso.
- **Aviso de cron parado:** se estiver ligado e não houver rodada há muito tempo
  (3 vezes o intervalo, no mínimo 1 hora), o campo "Última rodada" mostra um
  `ATENÇÃO`. Sem o cron do Mautic rodando também não há disparos de campanha.
- Ligado e sem nenhuma rodada ainda: mostra "Ainda não rodou" (começa na próxima
  rodada do cron do Mautic).
- Também é preciso que a integração N8n Dispatch esteja habilitada e com a
  `webhook_url` configurada.

### O comando (para rodar à mão ou em um cron de verdade, se quiserem)

```bash
php bin/console n8ndispatch:status:poll
```

O comando **não depende** do liga/desliga: rodado à mão, ele sempre pergunta.

| Opção | Padrão | Para quê |
|---|---|---|
| `--channel=email\|sms\|hsm` | os três | perguntar só de um canal |
| `--limit=200` | 200 | máximo de ids por canal por rodada |
| `--max-age-days=7` | 7 | ignora disparos mais antigos que isso |
| `--outcome=pending\|error` | `pending` | `error` refaz a pergunta dos que falharam |
| `--webhook-url=URL` | o configurado | chama outra URL (para testes) |
| `--run-id=N` | | uso interno do agendamento (fecha a rodada registrada) |

Saída de exemplo: `email: asked 1, changed 1, unchanged 0, unknown 1, invalid 1`.
Código de saída diferente de zero quando o webhook falha.

## 7. Sugestão de workflow no n8n

1. **Webhook** (o de sempre) → **Switch** pelo header `X-N8n-Dispatch-Action`:
   três saídas novas: `email.status`, `sms.status`, `hsm.status`.
2. **Split Out** em `body.items` (um item do n8n por id).
3. Consultar o Mirror pelo id (e, no HSM, a Meta pelo `logSendHsmUuid`).
4. **Code / Set** que transforma cada resultado em `{ <idKey>, outcome, message? }`
   (veja a tabela da seção 3).
5. **Aggregate** de volta em uma lista e **Respond to Webhook** com
   `{ "items": [...] }`.

Dica: se a consulta ao Mirror falhar para um id específico, **não o inclua** na
resposta (ou responda `pending`); ele será perguntado de novo na próxima rodada.

## 8. Como testar sem o n8n

Dois scripts em `mautic/scripts/`. O `test-status-poll.sh` sobe um n8n falso (`fake-n8n-status.php`)
dentro do contêiner do Mautic e passa por todo o ciclo para os três canais:
pendente repetido sem encher o histórico, erro, sucesso depois do erro, HSM com
Mirror e Meta, id desconhecido, item inválido, webhook fora do ar e disparo
expirado. O `fake-n8n-status.php` também mostra, no código, as respostas de
`email.send`, `sms.send` e `hsm.send` no formato acima.

O `test-status-poll-schedule.sh` testa o agendamento: desligado não roda, ligado
roda sozinho num ciclo do cron do Mautic, não repete dentro do intervalo, repete
depois dele, respeita 60/120 minutos e limpa rodadas antigas. Ele altera as
configurações do plugin durante o teste e as restaura no fim.

```bash
cd mautic && ./scripts/test-status-poll.sh && ./scripts/test-status-poll-schedule.sh
```
