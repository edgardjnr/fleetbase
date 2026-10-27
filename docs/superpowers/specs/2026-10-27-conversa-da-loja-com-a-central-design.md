# Conversa da loja com a central (portal da loja)

Data: 2026-10-27. Aprovado pelo Edgard na conversa.

## Objetivo

No portal da loja, a loja abre uma conversa com a central sozinha. Até aqui a loja só falava com os motoboys
(`2026-10-04-chat-da-loja-design.md`), e a central só entrava se alguém a adicionasse pelo chat do console.

## Decisões

- **Um canal por loja com a central**, criado na primeira abertura pela loja (não antes, para o console não ganhar
  conversas vazias) e reaproveitado depois.
- **Marca no `meta`:** `entregas_conversa_loja` = uuid do Vendor e `entregas_conversa_central` = uuid do Vendor, sem
  `entregas_conversa_motoboy`. Por ter a marca da loja, o canal já passa pelo `ConversasDaLoja::daLoja` (mensagens,
  envio e o limitador `entregas-loja-conversas` sem mudança) e só a loja dona o enxerga. A busca do canal com motoboy
  exige a marca do motoboy, então os dois tipos nunca se confundem.
- **Participantes:** quem abriu, os usuários ativos da loja (`ConversasDaLoja::usuariosDaLoja`) e **a central inteira**
  (decisão do Edgard): `ChatComACentral::usuariosDaCentral`, quem abre o console (corrigido em bed171ff: em produção os
  motoboys têm `type = user`, então o cadastro de motoboy decide). Motoboy e usuário de loja nunca entram pelo lado da
  central. Trava no cache (Redis) por loja contra dois canais com dois toques seguidos.
- **O canal ganha quem falta ao abrir e a cada mensagem nova** (`ChatMessage::created`): quem falta da loja ou da
  central entra (inclusive quem saiu). Sai só o usuário de loja (`customer`) que não está mais ativo nela (inativo ou de
  outra loja); o resto fica, inclusive quem a central adicionar pelo console. O gancho só age com a loja do `meta` da
  mesma empresa do canal (o console grava o `meta` que vem na requisição).
- **Papel do autor:** na conversa com a central, quem não é loja aparece como "central" (um motoboy com o papel
  Administrator está lá como central).
- **Nome do canal:** "Loja · <nome da loja>" (só "Loja" sem nome). É o que a central vê no chat do console, onde
  responde. A mensagem nasce como as outras (`ChatMessage::create` + `notifyParticipants()`): o console é avisado pelo
  chat dele; o portal lê na consulta periódica (5 s com a conversa aberta, 20 s a lista).
- **Empresa sem ninguém na central:** a abertura devolve 422 com "A central não está disponível para conversa agora.".
- **A regra antiga continua para as conversas com motoboy:** nelas a central não entra sozinha.
- **Fora do escopo:** botão "Falar com a central" no detalhe do pedido (pode vir depois).

## API

- `POST int/v1/entregas/loja/conversas` aceita `{ "central": true }`, além de `motoboy` (id opaco do mapa) e `pedido`.
  Responde `{ conversa }` no mesmo formato da lista (`ConversasDaLoja::item`, sem passar pelo corte da lista).
- `GET int/v1/entregas/loja/conversas`: cada item ganha `tipo` (`"central"` ou `"motoboy"`). No da central,
  `motoboy` vem `{ nome: null, foto: null }`. A ordem continua pela mensagem mais recente; o corte de 50 vale só para as
  conversas com motoboy, e a da central vem sempre, mesmo mais antiga.
- Código: `ConversasDaLoja::abrirComACentral(Vendor $loja, string $usuario): ?ChatChannel` e `nomeDaCentral()`;
  `listar` monta o `tipo`; o `ConversasDaLojaController@abrir` ganha o ramo `central`.

## Portal

- A lista de Conversas mostra sempre, no topo, o item **"Central"** (ícone `headset`): com a conversa já criada, traz a
  última mensagem, o horário e as não lidas; sem ela, o resumo "Fale com a central".
- Tocar abre a conversa (cria na primeira vez) pelo `POST` com `central: true`. O título da conversa aberta é "Central".
- Funções puras em `utils/conversas.js`: `itensDaLista(conversas)` põe a da central no topo (ou um item fixo sem
  conversa) e as dos motoboys depois, na ordem que vieram.
- O total de não lidas do topo e o aviso sonoro já somam todas as conversas da lista: passam a contar a da central sem
  mudança.
- Textos novos em `customer-portal.ui.entregas.*` (pt-BR e en-us).

## Testes

- `scripts/teste-php/conversas-com-a-central.php`: participantes (loja + central, sem motoboy nem `customer` do lado da
  central), reaproveitamento do canal, marca no `meta`, nome, `tipo` na lista, e uma loja não abre o canal da outra.
- `scripts/teste-portal/conversas.test.mjs`: `itensDaLista` (item fixo sem conversa, central no topo, motoboys depois).
- `node scripts/i18n-check.cjs ...` com exit 0.

## Documentação

`packages/customer-portal/CLAUDE.md`, em "Chat da loja com os motoboys": a conversa com a central, e a regra "a central
não entra sozinha" restrita às conversas com motoboy. Linha em `docs/historico.md`.

## Produção

Deploy api + console depois do push.
