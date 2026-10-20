# Tela do pedido "Comanda" e lista com o mais antigo em cima (app do motoboy)

Decisão do Edgard de 2026-10-19. Sketch: `entregas-navigator/.planning/sketches/007-detalhe-do-pedido/`, vencedora
**B: Comanda** (README do sketch, seção "Decisões do Edgard"). Os produtos vêm do servidor
(`2026-10-19-produtos-do-pedido-servidor-design.md`). Até lá, o app mostra a tela sem o bloco de produtos.

## Objetivo

- Simplificar a folha da `OrderScreen`.
- Dar ao motoboy o nome do cliente e os produtos do pedido.
- Na aba Pedidos, mostrar o pedido mais antigo em cima.

## 1. Lista da aba Pedidos

- **Novos pedidos:** do mais antigo ao mais novo, por `created_at`. A oferta que está tocando para ele (`entregas_oferta`)
  continua no topo, como hoje.
- **Em andamento:** as duas consultas de hoje (ativos e atribuídos ainda não despachados) juntas, sem repetir, do mais
  antigo ao mais novo por `created_at`.
- A ordenação fica no app, em `secoesDaLista` (`src/utils/lista-de-pedidos.ts`). Ela não depende do `sort` da API, porque
  o filtro da lista acrescenta no fim os pedidos entre R e 2R. A ordenação é estável: com o mesmo `created_at`, vale a
  ordem de chegada. Pedido sem `created_at` vai para o fim.
- O `comOfertaPrimeiro` (`src/utils/oferta.ts`), que ninguém usa, sai junto com o teste dele.

## 2. Nome do cliente

- Vem de `payload.dropoff.name`, que o servidor grava em MAIÚSCULAS. É mostrado em caixa normal: "MARIANA SOUZA" vira
  "Mariana Souza", e "da", "de", "do", "das", "dos" e "e" ficam minúsculas.
- **Nome genérico** (`Cliente iFood`, vazio, ou igual à rua): não há nome. O título volta a ser a rua, com o rótulo
  "Cliente".
- Nunca aparece **antes do aceite**: no pedido aberto e na oferta, só o bairro.

## 3. Produtos

Formato (o mesmo das duas rotas do servidor, campo `itens`):

```json
[{ "quantidade": 1, "nome": "Pizza grande Calabresa", "opcoes": [{ "quantidade": 1, "nome": "Borda de catupiry" }], "observacao": "sem cebola" }]
```

- `opcoes` e `observacao` são opcionais.
- O app lê `itens` da resposta de `GET v1/entregas/motoboy/pedidos/{id}/ifood` (`useDadosIfood`) ou de `.../integracao`
  (`useDadosDaIntegracao`).
- Sem a chave, `null` ou lista vazia: o bloco de produtos some, e nada mostra "0 itens". É o caso de hoje, antes do
  servidor novo.
- Contagem: a soma das quantidades ("4 itens"; "1 item").
- Linha resumida: "1× Pizza grande Calabresa · 2× Coca-Cola lata 350 ml · 1× Brownie de chocolate", cortada com "…"
  quando não cabe.

## 4. A folha "Comanda" (variante B do sketch 007)

O mapa em tela cheia, a barra do topo, a pílula de resumo e o deslizar continuam como hoje.

| Situação | O que muda |
|---|---|
| **Pedido aberto / oferta** (`CartaoDeOferta`, `CartaoDePedidoAberto`) | Acrescenta a linha resumida dos produtos e o bairro do cliente. Sem nome. |
| **Indo à loja** (recolhida) | Embaixo do nome da loja: "4 itens · iFood #4821" (ou o selo da origem). |
| **Na loja** (aberta) | Cupom: o número grande para o balcão, "para Mariana Souza" e os produtos com caixinhas de conferir. As caixinhas só ajudam: não travam o deslizar, não vão ao servidor e zeram ao fechar a tela. Com todas marcadas, "Tudo conferido". |
| **A caminho do cliente** (recolhida) | Título = nome do cliente (ou a rua, sem nome), com a rua e o bairro embaixo. A cobrança fica numa linha só. |
| **Na porta** (aberta) | Cartões curtos, nesta ordem: cobrança e troco; Ligar (0800 ou telefone da integração) ao lado do localizador; observações do cliente, complemento e referência (faixa amarela); cupom resumido dos produtos. |
| **"Mais"** (fim da folha aberta, recolhido por padrão) | Rota e paradas (`OrderWaypointList`) e o seletor do destino (`CurrentDestinationSelect`); o cartão da loja (`OrderCustomerCard`); o valor detalhado (`ValorDaEntrega`); "Ver todos os status". |

**Saem da tela:**
- "Mais detalhes do pedido" (rastreio, IDs, tipo, datas, campos extras);
- "Observações do pedido" (as `notes` cruas);
- "Comprovação do pedido" (`OrderProofOfDelivery`);
- "Itens do pedido" (`OrderPayloadEntities`, vazio);
- "Comentários do pedido" (`OrderCommentThread`).

A prova de entrega continua no fluxo (`require_pod` → `ProofOfDelivery`). Só sai o bloco da folha.

**Ficam como estão:** os cartões de aviso (cancelado, cancelado pago, trocado), o código de entrega do iFood, o placar
e o alerta "destino mudou".

0800 e localizador aparecem **só nas etapas do cliente** (hoje aparecem também na folha aberta da coleta).

## 5. Traduções

Todo texto novo vai em `translations/pt.json` e `en.json` (chaves em `OrderScreen` ou num grupo `Comanda`). Os textos
que saem da tela deixam de ser usados. Não é preciso apagar as chaves, a menos que um teste exija.

## 6. Testes

Em `scripts/testes/` (`node --experimental-strip-types --test ...`), funções puras:
- ordem da lista: mais antigo em cima, oferta no topo, junção sem repetir, sem `created_at` no fim;
- nome do cliente: caixa normal, preposições, genérico, igual à rua;
- produtos: leitura do `itens` (ausente, `null`, vazio, malformado), contagem, linha resumida;
- caixinhas: estado "tudo conferido".

O `tsc` não pode ganhar erro novo nos arquivos tocados.

## 7. Entrega

- Ramo `comanda-do-pedido` no `entregas-navigator`.
- **Cada build da `main` vira versão mínima** (trava "Atualize o app"): o merge na `main` só com o Edgard.
- Antes do merge: build de teste do ramo (`workflow_dispatch`) e teste no celular por adb.
- A tela funciona sem o servidor novo (sem o bloco de produtos), então o app pode ir antes do servidor.

## Riscos aceitos

- O nome do cliente já vem no recurso do pedido para qualquer motoboy que vê a lista. A regra "não mostrar antes do
  aceite" é só de tela.
- Caixinhas de conferir não persistem: reabrir a tela zera.
