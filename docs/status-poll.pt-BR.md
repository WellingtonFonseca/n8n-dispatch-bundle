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
- **Lotes, várias chamadas seguidas.** A rodada pergunta **tudo o que está pendente**, em
  lotes de **100 ids por chamada** (ajustável na tela do plugin), uma chamada depois da outra
  (a próxima só sai quando a anterior respondeu), até a fila acabar. Com 10 mil pendentes são
  100 chamadas de 100. Cada id é perguntado **uma vez só por rodada**. O n8n precisa dar vazão
  a esse ritmo; lotes menores respondem mais rápido e liberam o workflow para os outros.
- Cada chamada tem um **timeout**, ajustável na tela do plugin: por padrão, **180 segundos sem
  resposta** e **300 segundos no total**. Se o n8n travar, a chamada falha, a rodada para (o que já
  foi respondido antes fica gravado) e a próxima rodada pergunta de novo. Se o workflow só
  responde no fim, o tempo sem resposta é o tempo da chamada inteira: mantenha o timeout bem
  acima do que o n8n leva para responder um lote (se um lote leva 51 s, 60 s é pouco). Cuidado:
  se **todo** lote passar do timeout, a fila nunca anda, porque a rodada falha sempre no mesmo
  ponto; nesse caso aumente o timeout ou diminua o tamanho do lote.
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
- **HTTP 4xx/5xx, timeout ou corpo que não seja JSON com `items`:** aquela chamada é
  ignorada, nada dela muda, a rodada **para** e a próxima rodada pergunta de novo. As
  chamadas anteriores da mesma rodada, que já tinham dado certo, continuam gravadas.
- Um id é perguntado por no máximo 7 dias depois do disparo (configurável). Depois
  disso, deixa de ser perguntado e fica pendente para sempre.

## 5. O que aparece no card da Timeline

Abaixo de **Body** e **Response**, um bloco **Callback**:

- **Callback:** "Verificado em *data e hora*" (da última verificação, no idioma e no
  fuso do sistema) e, na linha de baixo, o selo (`Aguardando`, `Sucesso` ou `Erro`).
  Se o n8n mandou uma `message`, ela aparece num bloco "Mensagem:", seja qual for o
  resultado.
- **HSM:** dois blocos, **Callback** (o `logSendHsmId`) e **Callback Meta** (o
  `logSendHsmUuid`), cada um com o seu "Verificado em", o seu selo e a sua mensagem.
- **Histórico:** no fim do card, um título "Histórico:" que abre ao clicar, com uma
  tabela (Status, Data, Mensagem) por callback, da mais recente para a mais antiga.
  Só existe se algum resultado já mudou.

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
| Ids por chamada ao n8n | 100 | Tamanho do lote (de 10 a 1000). Também vale para o botão **Verificar agora** e para o comando, quando não se passa `--batch`. |
| Timeout sem resposta (segundos) | 180 | Quanto o Mautic espera o n8n responder uma chamada (de 10 a 900). |
| Duração máxima de uma chamada (segundos) | 300 | O máximo que uma chamada inteira pode durar (até 1800); nunca menor que o timeout. |
| Última rodada | (só leitura) | Data, resultado (ok/erro) e o resumo da última rodada agendada. |
| **Verificar agora** | (botão) | Pergunta ao n8n **uma vez, agora**, e mostra o resultado embaixo do botão. Funciona com o liga/desliga desligado. |

### Montando e testando o workflow antes de ligar

O botão **Verificar agora** serve para isso: com o liga/desliga ainda **desligado**, clique nele e
o Mautic faz a pergunta de verdade ao seu webhook (mesma chamada de uma rodada agendada: ações
`email.status`, `sms.status` e `hsm.status`, disparos dos últimos 7 dias).
**O botão faz uma chamada só por canal, com o tamanho de lote da tela (100 por padrão)**, e não esvazia a fila inteira como uma rodada agendada: assim você testa o workflow sem inundá-lo. Você vê chegar no n8n, ajusta o workflow e clica de novo, quantas vezes precisar. Embaixo do
botão aparece, por canal, quantos ids foram perguntados e o que mudou, ou o erro (por exemplo
`webhook returned HTTP 500.`), e uma linha avisando quando não havia nada pendente.

- Usa o webhook **já salvo** nas configurações do plugin: salve antes de clicar, se acabou de
  alterar a URL ou o token.
- É de verdade: as respostas do n8n são **gravadas** (o card e o histórico passam a mostrá-las), e
  só pergunta por ids já acompanhados. Para ter o que perguntar, rode antes a varredura dos
  disparos antigos (seção "Disparos que já existiam antes") ou espere um disparo novo.
- Não mexe no agendamento nem na linha "Última rodada", que são das rodadas agendadas.
- Exige a permissão de gerenciar plugins.

- **Mudar o intervalo** é só editar o campo e salvar. Vale já no próximo ciclo do
  cron do Mautic, sem reiniciar nada.
- **O intervalo não pode ser mais rápido que o cron do Mautic.** A imagem oficial
  roda esses comandos a cada 15 minutos; com 5 minutos configurados, na prática
  roda a cada 15. Intervalos de 1 ou 2 horas não sofrem com isso.
- **Aviso de cron parado:** se estiver ligado e não houver rodada há muito tempo
  (3 vezes o intervalo, no mínimo 1 hora), o campo "Última rodada" mostra um
  `ATENÇÃO`. Sem o cron do Mautic rodando também não há disparos de campanha.
- **Uma rodada não começa enquanto a anterior ainda está rodando** (como a rodada esvazia a
  fila inteira, numa fila grande ela pode durar mais que o intervalo). Uma rodada "rodando"
  há mais de 2 horas é considerada morta e deixa de bloquear a seguinte.
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
| `--batch=N` | o da tela (100) | ids por chamada ao n8n |
| `--max-calls=1000` | 1000 | máximo de chamadas por canal em uma rodada (só uma trava de segurança: a rodada vai até esvaziar a fila) |
| `--max-age-days=7` | 7 | ignora disparos mais antigos que isso |
| `--outcome=pending\|error` | `pending` | `error` refaz a pergunta dos que falharam |
| `--webhook-url=URL` | o configurado | chama outra URL (para testes) |
| `--run-id=N` | | uso interno do agendamento (fecha a rodada registrada) |

Saída de exemplo: `email: asked 1, changed 1, unchanged 0, unknown 1, invalid 1`.
Código de saída diferente de zero quando o webhook falha.

## 7. Disparos que já existiam antes (varredura)

Os disparos feitos **antes** de subir esta versão não têm acompanhamento. Para trazê-los
para o formato de callback, rode uma vez, depois de atualizar o plugin:

```bash
php bin/console n8ndispatch:status:backfill --dry-run   # só conta, não grava nada
php bin/console n8ndispatch:status:backfill             # grava
```

O comando percorre os disparos de Email, SMS e HSM já registrados nas campanhas e
começa a acompanhar o id de cada um (como `pending`, com a **data original do
disparo**). Depois disso, o status poll pergunta ao n8n por eles como por qualquer
outro, e o card mostra o callback também nos disparos antigos.

| Opção | Padrão | Para quê |
|---|---|---|
| `--since-days=N` | 7 | até quantos dias para trás olhar |
| `--batch=N` | 500 | quantos registros ler por vez |
| `--dry-run` | | só conta o que seria registrado |

- Só entram disparos **reais** (modo produção, com resposta 2xx do n8n e um id). Teste,
  pausado, com erro na ida ou sem id ficam de fora.
- **Pode rodar de novo à vontade:** o que já está acompanhado é ignorado.
- **HSM:** o `uuid` da Meta nunca foi guardado à parte, mas a resposta completa do n8n
  está salva em cada disparo, e o `uuid` é lido de lá. Só funciona se o n8n já devolvia o
  `uuid` nessa resposta; se não, só o id do Mirror é acompanhado.
- **Atenção ao prazo de 7 dias.** O poll só pergunta por disparos feitos nos últimos 7
  dias (`--max-age-days`, contados a partir da data original do disparo). Depois disso o
  disparo deixa de ser perguntado e fica `pending` para sempre, mesmo que a varredura o
  tenha registrado. Na produção, os primeiros disparos foram na **terça, 29/09/2026**,
  então eles saem da janela em **06/10/2026**. Por isso: suba o plugin e rode a varredura
  **antes** dessa data. Se já tiver passado, rode o poll à mão com um prazo maior, por
  exemplo `n8ndispatch:status:poll --max-age-days=30`, e a varredura com
  `--since-days=30`. A ordem não importa para a varredura em si; o que importa é o poll
  chegar a perguntar antes de o prazo vencer.
- A produção tem, no máximo, 100 disparos: a varredura é instantânea e o poll
  (lotes de 100 ids por chamada, até esvaziar) esvazia a fila numa rodada só.
- Saída de exemplo: `scanned 100, with ids 92, registered 120, already tracked 0, skipped 8`
  (o HSM conta dois ids por disparo).

## 8. Sugestão de workflow no n8n

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

## 9. Como testar sem o n8n

Três scripts em `mautic/scripts/`. O `test-status-poll.sh` sobe um n8n falso (`fake-n8n-status.php`)
dentro do contêiner do Mautic e passa por todo o ciclo para os três canais:
pendente repetido sem encher o histórico, erro, sucesso depois do erro, HSM com
Mirror e Meta, id desconhecido, item inválido, webhook fora do ar e disparo
expirado. O `test-status-backfill.sh` testa a varredura dos disparos antigos. O `fake-n8n-status.php` também mostra, no código, as respostas de
`email.send`, `sms.send` e `hsm.send` no formato acima.

O `test-status-poll-schedule.sh` testa o agendamento: desligado não roda, ligado
roda sozinho num ciclo do cron do Mautic, não repete dentro do intervalo, repete
depois dele, respeita 60/120 minutos e limpa rodadas antigas. Ele altera as
configurações do plugin durante o teste e as restaura no fim.

```bash
cd mautic && ./scripts/test-status-poll.sh && ./scripts/test-status-poll-schedule.sh && ./scripts/test-status-backfill.sh
```
