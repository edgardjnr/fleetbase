# Produtos do pedido no servidor (iFood e RestaurantePro)

Decisão do Edgard de 2026-10-19. É a parte de servidor da tela "Comanda" do app
(`2026-10-19-detalhe-do-pedido-app-design.md`, sketch 007 B).

## Objetivo

Guardar os produtos dos pedidos do iFood e do RestaurantePro e entregá-los ao app do motoboy no formato `itens`:

```json
[{ "quantidade": 1, "nome": "Pizza grande Calabresa", "opcoes": [{ "quantidade": 1, "nome": "Borda de catupiry" }], "observacao": "sem cebola" }]
```

- `quantidade`: inteiro de 1 a 999.
- `nome`: texto de 1 a 120 caracteres, sem espaços sobrando.
- `opcoes`: lista de até 20 itens, cada um com `quantidade` (padrão 1) e `nome`.
- `observacao`: texto de até 200 caracteres.

Esses campos são opcionais. Quando um deles vem vazio ou inválido, só ele cai. Item sem `nome` válido não entra.

A lista tem no máximo 50 itens. Lista vazia ou sem nenhum item válido grava `null`.

## 1. iFood

- **Origem:** o `GET /logistics/v1.0/orders/{id}`, que o criador do pedido já faz, traz
  `items[{index, name, quantity, options[{name, quantity}]}]`. A sondagem de `docs/ifood/referencia-logistics.md`
  ("Pedido do Logistics") registrou o formato com `name`.
  - A tabela da documentação (seção `items`, "não traz nome") é de outra resposta.
  - **Conferir no primeiro pedido de teste** se o `name` vem preenchido. Se não vier, o item não entra, e o bloco some no
    app.
- **Mapeamento:** `PedidoDoIfood::mapear` passa a devolver `itens`, convertidos pela regra acima e na ordem do `index`.
  O iFood não manda observação por item nesse formato: `observacao` só entra se algum campo de observação do item vier.
- **Gravação:** coluna nova `itens` (JSON, nula) em `entregas_ifood_pedidos`, por migration nova em
  `api/database/migrations`. O criador grava junto com o resto da linha.
- **Alterações (ORDER_PATCHED / EDIT_ITEMS):** hoje o evento só é registrado. **Fica fora** (risco aceito): o pedido
  editado depois de criado mostra os itens da criação.
- **Pedidos antigos:** ficam com `itens` nulo. Não há preenchimento retroativo.

## 2. RestaurantePro (integração)

- **Contrato:** `POST v1/entregas/integracao/pedidos` aceita o campo opcional `itens` no formato acima
  (`PedidoDaIntegracao`, validação).
  - Com o campo inválido, o pedido **não é recusado**: os itens inválidos caem, e o resto do pedido entra. Os itens são
    um extra e não podem derrubar a entrega.
  - Sistema que não manda `itens` continua igual.
- **Gravação:** coluna nova `itens` (JSON, nula) em `entregas_integracao_pedidos`, gravada pelo
  `CriadorDoPedidoDaIntegracao`.
- **Reenvio** (mesmo `pedido_externo_id`): devolve o pedido existente, como hoje, sem atualizar os itens.
- **Lado do RestaurantePro** (`restaurantepro-main`, edge `supabase/functions/entregas-delivery`): monta `itens` com os
  produtos do pedido do cardápio (quantidade, nome do produto, adicionais como `opcoes` e a observação do item, se
  houver) e envia no `POST pedidos`. O deploy da edge segue o roteiro do plano 3 da integração.

## 3. Entrega ao app

- `GET v1/entregas/motoboy/pedidos/{id}/ifood` (`DadosIfoodDoMotoboy::resposta`) e `.../integracao`
  (`DadosDaIntegracaoDoMotoboy`) passam a devolver `itens`: a lista normalizada, ou `null`.
- Os itens vão para **qualquer motoboy que pode ler a rota**, inclusive com o pedido ainda aberto: o app mostra a linha
  resumida antes do aceite. O telefone e o localizador continuam só para o motoboy do pedido em andamento.
- Leitura defensiva: JSON corrompido na coluna → `null` e um log warning, sem derrubar a resposta.

## 4. Fora do escopo

- O painel do console (iFood e Integração) e o portal da loja não mostram os itens.
- A atualização dos itens pelo ORDER_PATCHED.
- O `payload.entities` do Fleet-Ops não é usado.

## 5. Testes

- php-wasm (`scripts/teste-php/`):
  - normalização dos itens (limites, campos inválidos, ordem pelo `index`);
  - `mapear` do iFood com `items`;
  - criador do iFood gravando `itens`;
  - validação e gravação da integração (com, sem e com `itens` inválidos);
  - as duas respostas ao motoboy (com, sem, JSON quebrado, pedido aberto);
  - migrations nos stubs de esquema.
- Edge do RestaurantePro: o teste que o repositório dela usar, ou uma função pura testável que monta os `itens`.

## 6. Implantação

1. `bash deploy/atualizar.sh api`, com as migrations. Sem variável nova.
2. Deploy da edge `entregas-delivery` no Supabase do RestaurantePro.
3. Conferir no primeiro pedido de teste do iFood (Terraço) e do RestaurantePro se `itens` chega na rota do motoboy:
   `GET .../ifood` e `.../integracao` com o token de um motoboy.
