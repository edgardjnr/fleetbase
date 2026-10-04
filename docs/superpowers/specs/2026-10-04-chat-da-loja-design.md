# Chat da loja com os motoboys (portal da loja)

Data: 2026-10-04. Aprovado pelo Edgard na conversa.

## Objetivo

No portal da loja (`/customer-portal/orders`), o operador da loja conversa com os motoboys:

- **do pedido**: no detalhe do pedido, depois que um motoboy foi chamado/aceitou, "Conversar com o motoboy";
- **livre**: com qualquer motoboy que está no mapa do portal (online ou trabalhando), em "Conversas" → "Nova conversa".

A central participa de todas as conversas e as vê no chat do console. O motoboy conversa pela aba Conversas do app,
sem mudança no app.

## Decisões

- **Uma conversa por par loja × motoboy**, reaproveitada pelos dois caminhos (o do pedido e o livre). O nome é
  "<Loja> · <Motoboy>". Assim a lista de conversas do motoboy não ganha um canal por pedido. Aberta a partir do pedido,
  o campo de texto já vem com "Pedido <número>: ".
- **Participantes**: todos os usuários ativos da loja (Contact `customer` com VendorPersonnel ativo), o usuário do
  motoboy e os usuários da central (`ChatComACentral::usuariosDaCentral`: tipo `admin`/`user` da empresa). Quem falta
  entra a cada abertura (mesma regra do chat do motoboy com a central).
- **Por cima do chat do Fleetbase** (`chat_channels`, `chat_participants`, `chat_messages`, `chat_receipts`): o canal é
  marcado no `meta` (`entregas_conversa_loja` = uuid do Vendor, `entregas_conversa_motoboy` = uuid do Driver). A
  mensagem nasce como no core (`ChatMessage::create` + `notifyParticipants()`): o app recebe pelo socket e pelo push
  (canal `mensagens`), o console pelo chat dele.
- **A loja não usa as rotas de chat do Fleetbase** (a de participantes lista todos os usuários da empresa): só rotas
  nossas, que enxergam apenas as conversas da loja da sessão.
- **Sem socket no portal** (o portal já evita o socket): consulta periódica.

## API (`int/v1/entregas/loja/conversas*`, `ConversasDaLojaController`)

Todas com a loja da sessão (`LojaDoUsuario`), nunca por parâmetro. O `ProtegerPortalLoja` já libera
`entregas/loja/.+` para o usuário de loja. Limitador próprio `entregas-loja-conversas` (120 por minuto por usuário).

| Rota | O que faz |
|---|---|
| `GET loja/conversas` | As conversas da loja: id (public_id do canal), motoboy (nome, foto), última mensagem (texto, quando, se é minha), não lidas do usuário |
| `POST loja/conversas` | Abre (cria ou completa) a conversa. Corpo: `{ motoboy: <id opaco do mapa> }` ou `{ pedido: <public_id> }` |
| `GET loja/conversas/{id}/mensagens` | As últimas 50 mensagens (id, texto, quando, autor {nome, papel: loja/motoboy/central}, minha) e marca como lidas para o usuário |
| `POST loja/conversas/{id}/mensagens` | Envia `{ texto }` (1 a 1000 caracteres) |

Regras:

- **pelo id opaco**: só motoboy que está no mapa do portal agora (a mesma lista do `MotoboysNoMapaDaLoja`); o id do
  motoboy nunca sai para o portal;
- **pelo pedido**: pedido da loja (donos = Vendor e contato do usuário) com motoboy atribuído;
- motoboy sem usuário: 422; conversa de outra loja ou inexistente: 404;
- a resposta nunca traz uuid/public_id de motoboy, de participante nem de usuário.

## Portal

- Cabeçalho da tela Pedidos: botão **Conversas** (com o total de não lidas), ao lado de Mapa/Tabela. Abre uma gaveta à
  direita com a lista de conversas e "Nova conversa" (motoboys do mapa, `loja/motoboys`).
- Conversa aberta: mensagens (as minhas à direita), autor e papel nas dos outros, campo de texto e Enviar.
- Detalhe do pedido, painel Motoboy: botão **Conversar com o motoboy** quando há motoboy no pedido.
- Consultas: a lista a cada 20 s na tela Pedidos (badge), a conversa aberta a cada 5 s; pausa com a aba oculta e espera
  crescente em erro (`espera()` do `utils/entregas-pedido.js`).
- Estado num serviço do engine (`entregas-conversas`), compartilhado entre o cabeçalho, a gaveta e o detalhe.

## Testes

- `scripts/teste-php/conversas-da-loja.php` (php-wasm): funções puras (nome do canal, papel do autor, validação do texto,
  formato da mensagem, quem falta).
- `scripts/teste-portal/conversas.test.mjs`: funções puras do front (mesclar mensagens, total de não lidas, prefixo do
  pedido, texto da última mensagem).

## Riscos aceitos

- O public_id do canal vai ao portal; com ele, o socket (que não autentica inscrição) entrega as mensagens dessa conversa,
  que a loja já vê.
- Uma conversa criada continua existindo: a loja pode escrever ao motoboy depois que o pedido acabou (a central vê tudo).
