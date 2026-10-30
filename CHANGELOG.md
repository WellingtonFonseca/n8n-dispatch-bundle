# Changelog

Um bloco por dia, do mais novo para o mais antigo. Formato fixo: `## AAAA-MM-DD` e uma linha `- ` por mudança (a tela do plugin lê este arquivo).

## 2026-10-30
- Os templates de SMS e de HSM também têm o campo Cálculo nas variáveis de campo do contato e de Custom Object, com a mesma explicação e as mesmas regras do Email
- Nos formulários de SMS e de HSM, o texto que explica os placeholders numéricos ficou entre o título Texto do SMS (ou do HSM) e o campo, em fonte maior
- Novo modo de envio do Email por instância, em Configurações > Plugins > N8N Dispatch, aba Habilitar/Autenticar, campo Enviar o Email por: n8n (padrão) ou Mautic. Atualizar o plugin não muda o modo de nenhuma instância
- No modo Mautic o Email sai pelo mailer do próprio Mautic: respeita Não Contatar e bounce, usa o link de descadastro e o rastreio de abertura e de clique do Mautic. As campanhas, os templates e as variáveis continuam os mesmos; SMS e HSM seguem pelo n8n
- Nesse modo a tela Disparos e o card do contato mostram o que o Mautic sabe (na fila, enviado, aberto, clicou, falhou, bounce, descadastrou), com uma linha por tentativa, e o filtro de status usa o mesmo resultado
- Reenviar vale para o Email que o mailer do Mautic recusou: sai de novo como uma nova tentativa e o histórico guarda todas
- Se o SMTP não responde, cada contato espera no máximo 20 segundos e três falhas seguidas com o mesmo erro param o lote; o Mautic tenta o resto depois
- Um Email sem HTML e sem tema não é enviado nesse modo; os contatos esperam o Email ser corrigido
- A variável {{n8n_...}} também vale no assunto do Email: aparece na aba Variáveis N8N, precisa do prefixo n8n_ e do mapeamento, como no corpo

## 2026-10-29
- Na aba Variáveis N8N do Email, uma variável de campo do contato ou de campo de Custom Object ganha o campo Cálculo: o número digitado é somado ao campo no disparo (negativo subtrai)
- Em campo de data, o cálculo soma dias (no máximo 3650 dias para cada lado) e mantém a hora; a data sai no idioma do template
- Em campo número do contato e em campo int ou decimal de Custom Object, o cálculo soma o valor, sem limite, e mantém as casas do campo
- Só o campo decimal do Custom Object usa as casas do número digitado (2.3 ou 2,3); data, int e número usam só a parte inteira (15.5 vale 15)
- A explicação do cálculo aparece uma vez, no topo da aba, abaixo do texto sobre os placeholders; SMS e HSM não têm o campo

## 2026-10-28
- As listas de templates de SMS e HSM têm a coluna Status com o ícone de ativo e inativo, que abre ao passar o mouse; Ativar e Desativar é o primeiro item do menu da linha
- O badge de status abre dentro da coluna, sem empurrar as outras, e os nomes não quebram de linha
- A lista de templates de HSM tem a coluna Tipo, entre o Template HSM e o ID, com o nome do tipo (Texto ou Imagem Carrossel)
- Com o plugin ativo, a tela do contato oferece o Não Contatar de SMS (e de HSM, que segue o canal de SMS); os disparos já respeitavam essa lista
- Novo histórico de mudanças por dia (este arquivo), em uma aba própria do modal do plugin em Configurações > Plugins

## 2026-10-27
- O nome de uma ação de campanha sempre segue o template que ela dispara, e muda junto quando o template é renomeado
- Reenviar um disparo aceito pelo n8n limpa a marca de falha do Mautic
- A tela Disparos ordena pela data do disparo, do mais novo para o mais antigo
- O campo de data de reagendamento ficou legível nos temas escuros

## 2026-10-24
- Selo de status do n8n com a classe .n8ndispatch-badge e folha de estilo própria
- Um Email em uso por uma campanha não pode mais ser excluído
- Telas de template de SMS e HSM e fluxo de disparo compartilhados, com mais testes automáticos

## 2026-10-23
- Lista de templates HSM ganhou as colunas Router e Template HSM
- Listas de templates HSM e SMS: selo de status do n8n e Ativar/Desativar no menu da linha
- Selos de status sem ícone; no cartão da campanha a palavra abre ao lado do ícone, em maiúsculas
- Status poll: pausa configurável entre as chamadas ao n8n (vale também para o "Verificar agora" e o comando)
- O plugin se chama N8N Dispatch; o ícone do menu e do plugin é a marca do n8n
- Motivo da falha de um disparo recusado e resultado do disparo organizados em classes próprias

## 2026-10-22
- As variáveis de itens usam o SegmentItemResolver do plugin Custom Objects

## 2026-10-19
- Clique com o botão do meio no nome do template abre o modal de detalhes
- Rodapé do modal de detalhes com o visual de botões do Mautic

## 2026-10-17
- Tela Disparos: a coluna de status do passo mostra o modo no momento do disparo
- Tabela do Histórico do disparo segue o padrão de tabelas do Mautic
- O nome de um template de SMS ou HSM abre um modal de detalhes somente leitura, com abas Template e Campanhas, selo Ativo/Inativo e botões no rodapé
- Selos de status: fonte de 11px para 13px

## 2026-10-16
- Tela Disparos: a campanha aparece com o prefixo, "PREFIXO - Nome"

## 2026-10-12
- Ações de campanha recebem o nome da comunicação que disparam (ao salvar a campanha, mais um comando para as já existentes)

## 2026-10-09
- O disparo de HSM envia enro_academic_record
- Novo tipo de HSM: Image Carousel, com alerta único acima dos campos, só https e botão em cada card para abrir a imagem em nova aba

## 2026-10-07
- O cartão e o Preview da campanha mostram o nome do template que o evento dispara

## 2026-10-05
- Reenvio: a mensagem de callback pendente segue o padrão {reenvio: {por, em}}
- Reenvio: o modal de confirmação fica aberto e travado até o n8n responder
- Tela Disparos: guarda o tamanho de página escolhido

## 2026-10-04
- Cada entrada do disparo guarda só a resposta do n8n, em JSON
- O backfill reconstrói todo o Histórico
- Campo do token de autenticação é uma senha com olho para mostrar
- Status poll: "Última execução" e resultado do "Verificar agora" em blocos JSON

## 2026-10-03
- Histórico unificado do disparo, ligado ao log da campanha
- A nota de reenvio aparece em um bloco sob os selos de status
- Modal de detalhes dos Disparos mais largo
- Linhas da tabela mais escuras no tema claro

## 2026-10-02
- Variáveis de Custom Objects resolvem filtros de segmento combinados, e campos de data, vazios e multisseleção
- "Não está em" em multisseleção passa a ser negação
- Status poll: o resultado do callback de Email/SMS/HSM aparece na Timeline, com datas no idioma do sistema, bloco de mensagem e tabela de histórico recolhível
- Backfill dos disparos anteriores ao status poll
- Botão "Verificar agora" nas configurações do plugin
- Status poll consulta a fila inteira em lotes, com timeout e sem execuções sobrepostas; tamanho do lote e timeouts configuráveis
- Selo "test" mais escuro para o texto branco ser legível
- Logo do n8n como ícone do plugin
- Menu principal N8n Dispatch com o submenu Disparos
- Tela Disparos: tabela com data, link do template, do contato e da campanha, selo de callback, coluna de status do passo, menu de opções e modal de detalhes
- Tela Disparos: filtros por campanha, template, período e status do callback
- Reenviar um disparo com erro: chama o n8n de novo pelo listener do passo, grava de novo resposta, rastreio e callbacks, e uma tentativa recusada fica registrada
- A tela Disparos e o reenvio exigem Campanhas > Full

## 2026-10-01
- Variáveis de Email exigem o prefixo n8n_; aviso de variáveis sem mapeamento (ponto pulsante na aba, aviso após salvar) e bloqueio ao salvar um Email existente com variáveis sem mapeamento
- Email exige Nome e Endereço do remetente (tela e API), com aviso no formulário
- Templates de HSM e SMS exigem variáveis numéricas, e bloqueiam variáveis sem valor, com alerta ao vivo
- Correção: a checagem de mapeamento falhava em requisições de API sem customHtml
- Textos do plugin e motivos de falha do Histórico do contato traduzidos para pt_BR
- Horário de cancelamento e reagendamento no fuso do sistema

## 2026-09-30
- O seletor "Enviar via n8n (Email)" mostra só Emails publicados
- Títulos do canvas traduzidos e ícone do canal nos nós do canvas e do Preview
- Selo de status no Preview da campanha
- A aba de variáveis do Email atualiza ao fechar o builder
- Decimais de Custom Objects com no mínimo duas casas; datas e decimais seguem o idioma do template

## 2026-09-29
- Aba "Variáveis N8N" e aba "Campanhas" na edição do Email
- Edição dos templates de SMS e HSM dividida nas abas Template e Campanhas

## 2026-09-28
- Cartão da Timeline mostra o estado pendente/agendado
- Confirmação antes de cancelar um disparo agendado; registra quem cancelou ou reagendou, e quando

## 2026-09-24
- Telas de Templates de SMS e HSM completas, com Clonar e Excluir
- Correção: clonar um template de SMS não zerava o id
- Campo hsmTemplate (nome do template no WhatsApp) e hsmTemplateId na ação de campanha

## 2026-09-23
- Tela de Templates de SMS; a ação de campanha de SMS usa um template
- Variáveis de Custom Objects consideram todas as condições de segmento do objeto
- Template de SMS mostra as campanhas que o usam e não pode ser excluído em uso
- Campos ies_* do contato renomeados para inst_*

## 2026-09-21
- Nova ação de campanha "Enviar via n8n (SMS)" e "Enviar via n8n (HSM)", com histórico nativo
- Datas das variáveis no formato pt-BR
- Contatos na lista Não Contatar não recebem Email, SMS nem HSM
- README com instruções de instalação

## 2026-09-18
- Cabeçalho X-N8n-Dispatch-Action renomeado de template.sync para email.save
- A mensagem de erro real do n8n aparece no motivo da falha do evento
- Timeline do contato mostra o payload, o cartão visual, o "pausado", a resposta completa do n8n (também nas falhas) e o logSendEmailId
- Snapshot do HTML do template no disparo em produção
- Conformidade com Não Contatar e descadastro no disparo
- Rodapé de descadastro passa a ser gravado de verdade no banco do Mautic
- Pixel de abertura removido (as leituras nunca chegavam ao Mautic)
- Stat de Email enviado só em sucesso confirmado; falha registrada como "Email failed" na Timeline nativa
- Selo de status expande ao passar o mouse

## 2026-09-17
- modified_by_email no payload de sincronização
- Seletor de status (test / production / paused) na ação "Enviar via n8n (Email)", com selo no card do builder
- Status pausado pula o disparo; valores do status em inglês
- Correção: o seletor de status sempre mostrava "Test"

## 2026-09-16
- Primeiros testes automáticos (PHPUnit) dos resolvers de variáveis e da sincronização com o Mirror

## 2026-09-15
- Plugin N8n Dispatch: cada Email salvo é sincronizado com o Mirror por webhook
- Preheader injetado no HTML enviado ao Mirror e assunto no payload
- Ação de campanha "Enviar via n8n (Email)" com variáveis dinâmicas
- Variáveis vindas de Custom Objects e disparo real para o n8n
- Cabeçalho X-N8n-Dispatch-Action para identificar o tipo de chamada
