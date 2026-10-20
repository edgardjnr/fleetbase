# Cronômetro do prazo de entrega

Decisão do Edgard de 2026-10-20. Substitui o relógio do card do portal ("N min" da estimativa do Fleet-Ops, commit
506215e1), que não era o que ele queria.

## Objetivo

Cada pedido em andamento mostra **quanto falta para o prazo de entrega**, com cor:

| Situação | Cor | Texto |
|---|---|---|
| faltam mais de 15 min | verde | "32 min" |
| faltam 15 min ou menos | amarelo | "12 min" |
| passou do horário | vermelho | "Atrasado há 7 min" |

O cronômetro some quando o pedido é concluído ou cancelado. Pedido sem prazo não mostra cronômetro.

## O prazo

Uma regra só, no servidor (`App\Support\Entregas\PrazoDaEntrega`), gravada no pedido na criação:

- **iFood, imediato:** o `delivery.deliveryDateTime` do pedido do Logistics, que é a hora prometida ao cliente. Na sondagem
  de 05/10, ficou em `createdAt + 45 min`.
- **iFood, agendado:** o **fim** da janela (`schedule.deliveryDateTimeEnd`). Sem o fim, o início da janela + 30 min. Sem
  janela, a regra dos outros pedidos.
- **iFood sem data legível:** a regra dos outros pedidos.
- **Todos os outros** (RestaurantePro, portal da loja, central, API): **50 min** depois do `max(criação, scheduled_at)`.
  Assim, o agendado pela central conta a partir do horário agendado.

**Onde fica:** no `meta` do pedido, chave `entregas_prazo_em`, como texto ISO com o fuso de Brasília
(`2026-10-20T19:57:51-03:00`).

- O `meta` já vai, sem código novo, para todas as telas: o recurso v1 do pedido (app, portal) e o interno (console).
- O prazo não é dado sensível: pode ir aos motoboys e ao socket.
- **Gravação:** um hook `Order::creating` no `AppServiceProvider` grava o prazo de 50 min quando o `meta` ainda não tem a
  chave. O criador do iFood grava o prazo dele **antes** de salvar, e o hook não sobrescreve.
  - O `creating` não dispara o `updated`, então a distribuição e os observadores não percebem.
  - O `RegrasPortalLoja` descarta o `meta` que chega no pedido da loja, mas o hook roda depois, no model.
- **Pedidos de antes do deploy** ficam sem a chave, então sem cronômetro. Não há preenchimento retroativo.
- **Mudança do prazo depois de criado** (ORDER_PATCHED, CHANGE_PREPARATION_TIME do iFood, reagendamento pela central)
  **fica fora**. O prazo é o da criação.
- **Nunca em UTC:** a data do iFood (ISO com `Z`) é convertida para o fuso do app antes de gravar (regra de
  `api/CLAUDE.md`).

## O cronômetro (cliente)

Função pura em cada cliente, com a mesma regra:

```
cronometro(prazoIso, agora) → null | { minutos, estado: 'no_prazo' | 'perto' | 'atrasado', texto }
```

- `restante = prazo − agora`. Os minutos são arredondados **para cima**, antes e depois do prazo (59 s viram "1 min";
  0 a 60 s de atraso viram "Atrasado há 1 min", 61 s viram 2; "Atrasado há 0 min" não existe). Ajuste da revisão de
  2026-10-20: arredondando para baixo, o "1 min" de atraso ficava 2 minutos na tela.
- O servidor grava o prazo em `Y-m-d\TH:i:sP` (sem microssegundos), para o Hermes do app ler sem surpresa.
- `restante > 15 min` → `no_prazo`; `0 < restante ≤ 15 min` → `perto`; `restante ≤ 0` → `atrasado`.
- Prazo ausente ou ilegível → `null`.
- Atualização a cada 30 s, com o relógio do aparelho. Um relógio adiantado em segundos não muda os minutos o bastante
  para importar.
- **Cores:** verde, amarelo e vermelho do tema de cada cliente (tokens já existentes: sucesso, aviso, perigo), com texto
  legível nos dois temas.

## Onde aparece

1. **Portal da loja:** no card do pedido em andamento (`Portal::Order::ListCard`), no lugar do relógio atual. Volta o
   ícone de relógio, com o fundo na cor do estado. O `textoDoEta` (commit 506215e1) sai.
2. **Console (Fleet-Ops):**
   - na lista de pedidos (tabela do Fleet-Ops), uma coluna "Prazo" com o cronômetro;
   - no detalhe do pedido, ao lado do status.
3. **App do motoboy:**
   - no card de "Em andamento" (aba Pedidos);
   - na folha do pedido (Comanda), na linha da etapa.

   Não aparece no pedido aberto nem na oferta (antes do aceite).
4. **Alfinete do mapa:** a etiqueta "#4821 · Carlos" embaixo do alfinete do cliente ganha **fundo na cor do estado**
   (texto em contraste). Vale nos três mapas:
   - console (mapa ao vivo);
   - portal da loja;
   - Mapa do líder no app.

   Os dados vêm do servidor (`PedidosNoMapa`), que passa a incluir `prazo_em` em cada pedido (lido do `meta`). Pedido sem
   motoboy (sem etiqueta) não muda.

## Testes

- **php-wasm:**
  - `PrazoDaEntrega`: iFood imediato e agendado (com e sem fim da janela), data com `Z` convertida para -03:00, data
    ilegível, os outros pedidos com e sem `scheduled_at`;
  - o hook não sobrescreve;
  - o criador do iFood grava;
  - o `PedidosNoMapa` devolve `prazo_em`.
- **Clientes:** a função `cronometro` em cada cliente: limites de 15 min e 0, arredondamento, ausente e ilegível. Testes
  em `scripts/teste-portal/` (portal e console) e `entregas-navigator/scripts/testes/` (app).
- **i18n:** "Atrasado há N min" e o rótulo "Prazo" em pt e en, com o `i18n-check` saindo com 0.

## Entrega

- Delivery (API, console e portal): um ramo, com deploy de `api` e `console`. Sem migration: o prazo vai no `meta`.
- App: um ramo. O merge na `main` vira a versão mínima (APK novo obrigatório): só com o Edgard.
- A ordem não importa: sem a chave no `meta`, os clientes não mostram o cronômetro.

## Riscos aceitos

- Pedidos criados antes do deploy ficam sem cronômetro.
- O prazo não acompanha a mudança feita depois da criação.
- O relógio do aparelho define o cronômetro.
- O `meta` com o prazo vai a todos os motoboys pelo recurso do pedido.
