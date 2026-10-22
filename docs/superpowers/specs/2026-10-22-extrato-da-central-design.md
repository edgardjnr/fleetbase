# Extrato da central (PDF para o cliente) — design

Data: 2026-10-22. Aprovado pelo Edgard ("PDF para imprimir", "PODE"). Complementa
`2026-10-22-corridas-do-sistema-design.md`.

## Problema

O CSV de Corridas do sistema é o controle interno do dono: mistura resumo e lista, tem colunas internas (`order_…`,
origem, central repetida), dinheiro sem formato ("0,5", "10") e junta todas as centrais. Não serve para enviar a uma
central.

## Solução

Na tela Fleet-Ops → Financeiro → Corridas do sistema, cada linha da tabela "Por central" ganha o botão **Extrato**,
que imprime (o navegador oferece "Salvar como PDF") o extrato **só daquela central**, no período da tela. Só console;
a API não muda (o relatório já traz `centrais[]` e `corridas[]` com `company`).

### Página (A4, pt-BR)

- Cabeçalho: "Entregas · RestaurantePro", título "Extrato de uso — <central>", "Período: dd/mm/aaaa a dd/mm/aaaa",
  "Emitido em dd/mm/aaaa".
- Resumo: "Corridas finalizadas", "Valor por corrida" (um preço: `R$ 0,50`; preço mudou no período: uma linha por
  preço com a quantidade de corridas, ex.: "R$ 0,50 × 12 · R$ 0,60 × 8"), **"Total a pagar"** em destaque.
- Lista: Data, Hora, Nº do pedido (`numero`; sem número, o `pedido`), Loja, Motoboy; coluna **Valor** só quando o
  preço variou no período. Rodapé: "Total: N corridas · R$ X".
- Fora do extrato: id interno, origem, outras centrais, aviso "sem preço". Dinheiro sempre `R$ 1.234,56`.

### Regras

- Corridas da central = `relatorio.corridas` filtradas por `company`. Se `relatorio.lista_cortada` for verdadeiro, o
  botão avisa que a lista está incompleta e não imprime (pedir um período menor).
- Central com corridas "sem preço" no período: aviso antes de imprimir, com opção de imprimir mesmo assim.
- Impressão pelo mecanismo da fatura (`packages/fleetops/CLAUDE.md`, "Imprimir": copia o bloco para o `<body>`, CSS
  `.entregas-imprimindo`, `.entregas-impressao-copia`, `.entregas-nao-imprimir`, `.entregas-so-imprimir`; a cópia sai
  no `afterprint`). O bloco do extrato só existe enquanto imprime.
- A montagem dos dados do extrato é função pura em `utils/corridas-do-sistema.js` (testada no teste-portal).
- Textos em pt-br e en-us; `i18n-check` exit 0.
- O CSV continua como está (controle interno).
