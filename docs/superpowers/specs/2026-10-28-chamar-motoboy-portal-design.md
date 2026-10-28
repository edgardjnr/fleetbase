# Chamar motoboy pelo portal da loja

Decisão do Edgard de 2026-10-28. Desenho aprovado: variante B (https://claude.ai/artifact/K5AArsbFFQ35ArGHG7mySg).
Plano: `docs/superpowers/plans/2026-10-28-chamar-motoboy-portal.md`. Quando o código e este texto divergirem, vale o código.

## O que muda para a loja

O "Novo pedido" do portal (seletor de endereços salvos, modal "Novo endereço" e cadastro do cliente) dá lugar ao painel
**"Chamar motoboy"**, em 3 passos, na mesma rota (`portal.orders.new`, `/customer-portal/orders/new`) e no mesmo lugar da
tela Pedidos (a folha da direita, no lugar da lista; no celular, a folha de baixo). Os botões "Novo pedido" do topo, do
Início e da lista continuam levando a ela.

1. **Cliente.** "Para quem é a entrega?" e a dica "Comece pelo telefone: se o cliente já pediu, o endereço vem junto."
   Campo Telefone primeiro. Com um telefone válido, o portal pergunta ao servidor se ele já pediu **nesta loja**; se sim,
   o cartão "Já pediu antes · N pedidos" com o nome e o último endereço (rua, complemento, bairro, referência) e os botões
   **Usar e ir ao pagamento** (pula o passo 2) e **Outro endereço** (vai ao passo 2 em branco). O Nome vem preenchido
   pelo do último pedido quando a loja ainda não digitou. Botão **Continuar**.
2. **Endereço.** "Onde entregar?" com "Nome · telefone". "Rua e número" com as sugestões do Google (o
   `EnderecoGoogleInput` e as rotas `entregas/enderecos/sugestoes` e `detalhes`, que já existem); escolher uma sugestão
   preenche bairro, cidade, UF e CEP e põe o ponto no mapa. Mapa pequeno, sempre à vista, com o alfinete fixo no centro:
   arrastar o mapa ajusta o ponto. Rua sem número: aviso "Confira o número da casa." (não bloqueia). Complemento e
   Referência. **Voltar** / **Continuar**.
3. **Pagamento e chamar.** Resumo (nome, telefone, endereço, **Alterar**). "O cliente já pagou?" com **Já pago** /
   **Cobrar na entrega** (nenhum vem marcado: a loja precisa escolher). Em cobrar: Valor a cobrar, Forma (Dinheiro,
   Cartão, Pix) e, só em dinheiro, Troco para e a linha "O motoboy leva de troco R$ X". **Observação para o motoboy**.
   Botão **Chamar motoboy**.

Depois de chamar: **"Procurando motoboy"** com o número do pedido, o cliente, o endereço e a cobrança (ou "Já pago"), a
dica "Dá para cancelar até o motoboy sair para a entrega." e os botões **Ver no mapa** (abre o pedido na tela Pedidos) e
**Novo pedido** (painel em branco).

O cliente fica guardado ao pedir: não há tela de cadastro. Cobrança (valor, forma, troco) e observação chegam ao motoboy
no app e à central na comanda do console.

### O que ficou de fora

- **"Corrida · 3,2 km · R$ 9,00" no rodapé:** omitido nesta versão (a decisão permitia). Uma estimativa certa pede a
  rota do OSRM da loja ao ponto a cada ajuste do mapa, a faixa da tabela de km, o multiplicador da loja e a segunda
  tabela (entrega junto, que só se sabe na atribuição). Com qualquer atalho (linha reta × fator), o valor mostrado
  divergiria do cobrado, e a loja pré-paga cobraria a diferença. Se o Edgard quiser depois: rota própria
  `GET loja/estimativa?latitude&longitude` com o OSRM entre o Local da loja e o ponto (sem `Order`, com cache por
  coordenada), `CalculoEntregas::faixaDoKm` × fator do multiplicador vigente da loja e o rótulo "estimativa".
- Itens do pedido (produtos): o portal não tem cardápio.
- Agendamento: continua fora do portal.

## Servidor

### Uma rota que faz tudo: `POST int/v1/entregas/loja/chamar-motoboy`

Controller próprio `App\Http\Controllers\Entregas\ChamarMotoboyController@chamar`, `throttle:20,1`. Já está liberada à
loja pelo `ProtegerPortalLoja` (`#^[A-Z]+ entregas/loja/.+$#`); motoboy e central não são loja e não têm vínculo com
Vendor (403 "Este usuário não está ligado a nenhuma loja. Fale com a central.").

Corpo:

```json
{
  "chave": "6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10",
  "cliente": { "nome": "Mariana Souza", "telefone": "16999990000" },
  "entrega": {
    "rua": "Rua Olinda, 45", "bairro": "Centro", "cidade": "Ribeirão Preto", "uf": "SP", "cep": "14010-000",
    "latitude": -21.18, "longitude": -47.81, "complemento": "Ap 12", "referencia": "Perto da praça"
  },
  "cobranca": { "forma": "dinheiro", "valor": "58,90", "troco_para": "100" },
  "observacao": "Tocar o interfone"
}
```

Num passo só, nesta ordem:

1. **Confere o corpo** (`App\Support\Entregas\Portal\ChamadoDaLoja::validar`, função pura, mensagens para a loja e não
   para um desenvolvedor): chave uuid v4; nome (até 120); telefone com DDD (10 ou 11 dígitos depois de tirar o `55` e o
   `0` da frente; `ChamadoDaLoja::telefone`); rua (até 190); ponto válido; complemento e referência (até 190);
   `cobranca.forma` em `pago | dinheiro | cartao | pix`; valor maior que zero e até R$ 100 mil (aceita "58,90" e
   "1.234,56"); troco só em dinheiro, maior que o valor (igual ou zero = sem troco); observação até 500, com quebra de
   linha. Texto longo é cortado (o front já limita), nunca 422. UF e CEP inválidos (vêm do Google) viram null. 422 com
   todos os erros de uma vez.
2. **Mesma chave = mesmo pedido** (`CriadorDoChamado::repetido`): a loja que tocou de novo porque a rede caiu recebe o
   pedido que já nasceu (200), sem passar pelo crédito nem criar outro. A chave é gerada pelo front ao abrir o painel e
   trocada no "Novo pedido". Única por loja na tabela (`vendor_uuid`, `chave`); a corrida de duas requisições iguais cai
   na chave única e devolve a primeira.
3. **Crédito da loja pré-paga**: a mesma regra e a mesma trava do portal antigo (`entregas:carteira:<loja>`, 30 s, espera
   10 s; 422 "Crédito insuficiente…", 409 "Outro pedido da loja está sendo criado…"). O código sai do
   `RegrasPortalLoja::criarComCredito` para `App\Support\Entregas\PrePago\CreditoNoPedido::criar`, que os dois usam
   (recusa = `App\Support\Entregas\PedidoRecusado`, com o status e a mensagem).
4. **Coleta = Local da loja** (`LojaDoUsuario::coleta`, com empresa, dono e coordenadas; senão 422 "A loja ainda não tem
   endereço de coleta cadastrado. Fale com a central."). **Ponto a pelo menos 30 m** da coleta
   (`RegrasPortalLoja::DISTANCIA_MINIMA_DA_COLETA_METROS`: 422 "O ponto da entrega está no mesmo lugar da loja. Arraste o
   mapa até o local da entrega.") **e a no máximo 50 km** (`CriadorDoPedidoDaIntegracao::DISTANCIA_MAXIMA_METROS`, regra
   nova no portal: pega a sugestão do Google de outra cidade; 422 "O ponto da entrega está a mais de 50 km da loja.
   Confira a cidade e o ponto no mapa.").
5. **Acha ou cria o Local do cliente** (ver "Local do cliente").
6. **Cria o pedido** numa transação, no molde do `CriadorDoPedidoDaIntegracao`: Payload (coleta com o waypoint atual,
   entrega), `Order` com `customer` = a loja (Vendor; o extrato, a fatura e a carteira saem disso), `OrderConfig::default()`
   (o `transport`), `status = created`, `adhoc = true`, **sem notas** e `meta.customer_portal` (`created_by_uuid`,
   `created_at`, como o portal grava). O prazo de 50 min entra pelo `Order::creating` (`GravarPrazoDoPedido`). Junto, a
   linha em `entregas_portal_pedidos`.
7. **Despacha** como o portal e a integração: com a `TravaDoPedido`, o pedido relido, `adhoc` e
   `firstDispatchWithActivity` na sessão da loja → `OrderDispatched` → distribuição (ofertas e rodadas). Falha (trava
   ocupada, erro) só vai ao log; o pedido existe e a central despacha à mão (o portal antigo também não refazia).
8. Responde 201 `{pedido: {id, numero, cliente, endereco, cobranca}}` (`numero` = rastreio, senão public_id; `cobranca` =
   o texto do app, null quando já pago).

Logs (sem nome, telefone, endereço nem observação): `[entregas] portal da loja: motoboy chamado` (`pedido`, `loja`,
`endereco_reaproveitado`), `chamado repetido`, `trava do pedido ocupada; despacho fica para a central`,
`falha ao despachar o pedido aos motoboys`, `loja sem local de coleta válido`.

**Por que uma rota só, e não o front encadeando `POST places` + `POST orders`:**

- As duas chamadas do portal do Fleetbase passam pelo `RegrasPortalLoja`, que exige um Place salvo e recusa o mesmo
  nome + rua (anti-sobrescrita do `firstOrNew` do `PlaceController`): o cliente repetido com outro número da mesma rua
  esbarraria nisso, e a solução seria inventar nomes.
- Cobrança e observação não têm onde entrar no `POST orders` do portal (o `meta` é descartado pelo `RegrasPortalLoja`, e
  as `notes` viram selo de origem: uma observação "Mesa #4" viraria o selo "Mesa #4" no console, no portal e no app).
- Duas chamadas sem transação deixam Places órfãos quando a segunda falha (sem crédito, 30 m), e a repetição pela rede
  cria pedido em dobro. Uma rota com chave resolve as duas coisas.
- O resto (coleta fixa, 30 m, pré-pago, despacho, distribuição, prazo) é reaproveitado das mesmas classes.

O `POST customer-portal/int/v1/orders` com as regras do `RegrasPortalLoja` continua funcionando (compatibilidade); o
front deixa de usá-lo.

### `GET int/v1/entregas/loja/clientes?telefone=`

`ChamarMotoboyController@clientes`, `throttle:60,1`. Telefone normalizado pelo `ChamadoDaLoja::telefone`; inválido →
`{cliente: null}` (sem 422: o front pergunta enquanto a loja digita). Só os pedidos **da loja da sessão**
(`company_uuid` + `vendor_uuid`); o telefone que só pediu em outra loja volta `null`.

```json
{ "cliente": { "nome": "Mariana Souza", "telefone": "16999990000", "pedidos": 3,
  "endereco": { "rua": "RUA OLINDA, 45", "bairro": "CENTRO", "cidade": "RIBEIRÃO PRETO", "uf": "SP", "cep": "14010-000",
    "complemento": "Ap 12", "referencia": "Perto da praça", "latitude": -21.18, "longitude": -47.81 } } }
```

Nome, complemento e referência vêm da última linha (como a loja digitou); rua, bairro, cidade, UF, CEP e ponto, do Local
(em maiúsculas, como o Fleetbase grava). Local apagado ou sem ponto: `endereco: null` (o cartão mostra só o nome e o
"Outro endereço"). `pedidos` = linhas do telefone na loja. Só os chamados feitos por este painel contam: os pedidos
antigos do portal não têm telefone guardado.

### Local do cliente (o Place de entrega)

- **Reaproveita** o Local de uma das últimas 20 linhas da loja com o mesmo telefone quando nome, rua e complemento batem
  (sem diferença de maiúsculas e espaços nas pontas) e o ponto está a até 30 m (`CriadorDoChamado::REAPROVEITAR_ATE_METROS`).
  É o caso do "Usar e ir ao pagamento": o front reenvia os dados do último endereço.
- **Senão, cria** um Local novo com `Place::create` (não passa pelo `PlaceController`, então não há `firstOrNew` nem
  sobrescrita): dono = a loja (como os endereços do portal antigo), nome = o do cliente (o card, o detalhe, a comanda e o
  app mostram o nome do Local), rua, complemento em `street2`, bairro, cidade, UF, CEP, país BR e o ponto, em maiúsculas
  com `mb_strtoupper` (o `PlaceObserver` só faz `strtoupper`).
- **Nunca altera** um Local existente: pedidos antigos usam o ponto dele no km da cobrança, como já valia no portal.
- **O telefone não vai para o Local:** o Place de entrega sai na API v1 para os motoboys (lista de pedidos abertos);
  o telefone fica só na linha do portal e chega só ao motoboy do pedido em andamento (regra do `DadosDaIntegracaoDoMotoboy`).

### Onde ficam cobrança, observação, telefone, complemento e referência: tabela própria

Tabela nova **`entregas_portal_pedidos`** (migration `2026_10_28_150000_create_entregas_portal_pedidos_table.php`):
`company_uuid`, `vendor_uuid`, `order_uuid` (único), `place_uuid`, `chave` (ascii_bin; única com o `vendor_uuid`),
`cliente_nome`, `cliente_telefone`, `complemento`, `referencia`, `observacoes`, `cobranca_forma`, `cobranca_centavos`,
`troco_para_centavos`, `timestamps`; índice (`vendor_uuid`, `cliente_telefone`). As colunas da cobrança têm o mesmo nome e
formato das da integração (centavos; forma `pago | dinheiro | cartao | pix`).

Leitura e gravação num lugar só: `App\Support\Entregas\Portal\PedidosDoPortal` (`doPedido`, `daChave`, `doTelefone`,
`clienteDoTelefone`, `comoLinhaDaIntegracao`, `resumo`). O `doPedido` engole o erro de banco (log
`[entregas] portal da loja: pedidos do portal ilegíveis no banco`) e devolve null: nos segundos entre o `service update`
e a migration do deploy, o app, a comanda e o detalhe do portal seguem como pedido sem linha, sem 500.

**Por que não reaproveitar a `entregas_integracao_pedidos` com uma origem "portal":**

- O `ObservadorDosPedidosDaIntegracao` (no `Order::updated` e no `OrderDriverAssigned`) enfileira um aviso a cada
  mudança de qualquer pedido com linha ali, e o `AvisosDaIntegracao` o manda ao webhook da **loja** conectada. Uma loja
  com o RestaurantePro conectado que chamasse motoboy pelo portal mandaria `pedido.aceito`, `pedido.a_caminho` etc. de
  pedidos que o RestaurantePro não conhece. Separar exigiria um `if` em cada um desses lugares.
- O `entregas:integracao-acompanhar` redespacha a cada minuto as linhas sem `despachado_em` e reenvia avisos.
- A chave da integração da loja consulta e cancela pedidos pela linha (`PedidosDaIntegracao::daLoja` aceita o
  `order_…`): o sistema da loja passaria a cancelar pedidos feitos no portal.
- O `CalculoEntregas` (join), o `CartaoDoAlarme`, o `AvisosDoMotoboy` e a comanda tratam a linha como "pedido do sistema
  da loja": o selo do console viraria "Integração", e o cancelamento pela loja poderia ser tomado por cancelamento da
  origem. Os campos `pedido_externo_id`, `sistema`, `numero`, `ultimo_aviso`, `avisos_enviados` não fazem sentido aqui.

A tabela própria não entra em nenhum desses caminhos. Só três leitores passam a olhá-la, cada um com uma linha:

| Leitor | O que muda |
|---|---|
| App do motoboy: `GET v1/entregas/motoboy/pedidos/{id}/integracao` (`MotoboyController@integracao`) | `PedidosDaIntegracao::doPedido($uuid) ?? PedidosDoPortal::comoLinhaDaIntegracao(PedidosDoPortal::doPedido($uuid))`: a linha do portal no formato da da integração (`sistema` = "Portal", `numero` null, sem itens, sem cancelamento pago), entregue pelo mesmo `DadosDaIntegracaoDoMotoboy::resposta` |
| Comanda da central: `GET int/v1/entregas/pedidos/{id}/comanda` (`PedidoAbertoController` + `ComandaDaCentral::resposta`) | parâmetro novo `portal:`; `origem = 'portal'`, `sistema` null, cobrança pela `CobrancaDaIntegracao`, observação, complemento e referência da linha. O console não mostra selo para `portal` (o `seloDaOrigem` só conhece `ifood` e `integracao`), então nada muda na tela além dos dados |
| Detalhe do pedido no portal: `GET loja/pedidos/{id}/itens` (`PortalLojaController@itens`) | `?? PedidosDoPortal::doPedido(...)`: a observação aparece no painel "Itens do pedido" (os itens seguem null) |

### Precisa de APK? Não.

O app já chama `.../integracao` para todo pedido que não é do iFood pelas notas (`useDadosDaIntegracao` em
`OrderScreen.tsx` e `AdhocOrderCard.tsx`) e, com `integracao: true`, já mostra (conferido no código do
`entregas-navigator`):

- a cobrança em uma linha na loja e na porta ("Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100", `textoDaCobrancaDaIntegracao`)
  e "Já pago" com `forma = 'pago'`;
- as observações em amarelo e o complemento com a referência (`AvisosDaPorta`), e a observação na comanda da loja
  (`ComandaDoPedido`);
- "Ligar para o cliente" com o telefone, só ao motoboy do pedido em andamento (`LigarParaOClienteDaIntegracao`);
- o selo de origem: `origemDosDados` devolve `{sistema: 'Portal', numero: null}`, e o selo sai **"Portal"** no card de
  aceitar e na tela do pedido. É o mesmo nome do selo do card do portal da loja ("Portal") e diz a verdade; para não ter
  selo nenhum seria preciso um APK (fica assim).

O cartão do alarme (Kotlin, `CartaoDoAlarme`) não muda: continua sem cobrança para o pedido do portal.

### Migration? Sim, uma tabela nova

O `bash deploy/atualizar.sh api` roda o `deploy.sh` (migrations) depois do `service update`; nos segundos sem a tabela,
o `PedidosDoPortal::doPedido` devolve null (acima) e só as duas rotas novas dariam 500, mas o console antigo ainda não as
chama.

## Front (portal da loja)

- Rota `portal.orders.new` mantida: o `model` lê só a loja (`loja/minha-loja`, para o centro do mapa) e continua pondo a
  coleta no rascunho do `customerPortalOrderCreation` (o P da loja no mapa grande, como hoje). O template passa a ser
  `<Portal::ChamarMotoboy @loja={{this.model.loja}} />`.
- Componente `Portal::ChamarMotoboy` (`addon/components/portal/chamar-motoboy.{js,hbs}`): estado em campos `@tracked` (o
  endereço numa classe com campos `@tracked`, porque o `EnderecoGoogleInput` liga o `@value` em duas vias); busca do
  cliente em `task` reiniciável com espera de 400 ms; o chamar em `task` que descarta o segundo toque; erros do servidor
  no `notifications.serverError` (o 422 traz a frase pronta).
- Componente `Portal::ChamarMotoboy::Ponto` (`.../chamar-motoboy/ponto.{js,hbs}`): o `LeafletMap` com o alfinete fixo
  no centro, `@onDragend` (só o gesto da loja move o ponto: o `moveend` dispara também no carregamento e poria o ponto na
  loja), roda e pinça com zoom no centro (como o `CoordinatesInput` com `@mapCenter`). **Por que não o `CoordinatesInput`
  direto:** ele esconde o mapa num menu "Selecionar no mapa" e mostra latitude e longitude em texto; o desenho pede o mapa
  sempre à vista. O mecanismo (alfinete no centro, centro da loja) é o mesmo.
- Funções puras em `addon/utils/chamar-motoboy.js` (telefone, valor em centavos, troco, problema de cada passo, corpo da
  requisição, linha do endereço, endereço da sugestão e do cliente, chave). O telefone segue a regra do servidor
  (`ChamadoDaLoja::telefone`): mudou lá, mude cá. Teste `scripts/teste-portal/chamar-motoboy.test.mjs`.
- Textos `customer-portal.ui.entregas.chamar.*` (pt-BR e en-US). CSS `ps-cm-*` no fim do `customer-portal-engine.css`, só
  com os tokens `--pi-*` (tema escuro).
- **A tela antiga sai:** `Portal::Order::Form`, `Form::Route`, `Form::Notes` e o modal `portal-order-place-form` (com os
  re-exports em `app/`) são apagados depois de conferir que nada mais os usa.

## Riscos e respostas

| Risco | Resposta |
|---|---|
| Telefone repetido de clientes diferentes (família, número que trocou de dono) | O cartão mostra nome e endereço antes de usar; a loja toca "Outro endereço" e troca o nome. Nome diferente nunca reaproveita o Local (nasce outro), e o Local antigo não muda |
| Endereço sem número (o Google devolve só a rua) | Aviso "Confira o número da casa." no passo 2; a loja digita o número na própria rua. Não bloqueia (há endereços sem número) |
| Google fora do ar ou sem a chave | `BuscaDeEnderecos` devolve lista vazia: a loja digita a rua e arrasta o mapa (abre na loja). Sem ponto arrastado, "Continuar" fica desligado com a dica. Ponto a menos de 30 m da loja: 422 claro |
| Sugestão de outra cidade | Teto novo de 50 km da loja (422) |
| Lista de Locais crescendo | Reaproveita o Local do mesmo cliente no mesmo ponto; cresce um por endereço novo de cliente, como no portal antigo e na integração. Os Locais ficam com dono = a loja, o que separa por loja no console. Não há limpeza automática |
| Pedido em dobro (toque duplo, rede caiu) | `task` com `drop` no front + chave única por loja no servidor |
| Loja pré-paga sem crédito | Mesma trava e mesmas mensagens do portal antigo; o 422 aparece no aviso de erro |
| Loja vendo cliente de outra loja | `clientes` filtra por empresa e loja da sessão; teste de isolamento no php-wasm |
| Telefone vazando | Fora do Place e dos logs; só o motoboy do pedido em andamento o recebe; a comanda da central não o mostra |
| Observação com "#" virando selo | A observação não vai para as notas (as notas ficam vazias) |
