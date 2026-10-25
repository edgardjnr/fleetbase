# Painel inicial do console

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

É a tela da raiz do console (`/`, rota `console.home`), que abre logo depois do login. Saiu do sketch 004, variante A
(`.planning/sketches/004-painel-inicial/`), e foi para produção em 2026-10-25. O plano é
`docs/superpowers/plans/2026-10-25-painel-inicial.md`; quando os dois divergirem, vale o código.

## API (`int/v1/entregas`, `PainelInicialController`)

### `GET painel-inicial?inicio=Y-m-d&fim=Y-m-d`

Resumo do período, montado pelo `PainelInicial::montar`.
- Sem as datas, vale o dia de hoje no fuso da empresa (`Financeiro::hoje`).
- O período vai até 31 dias. Data inválida, fim antes do início ou mais de 31 dias dão 422.

**De onde vem cada número:**

| Número | Fonte |
|---|---|
| Corridas, gráfico e rankings | `entregas_corridas_finalizadas`. Tem a hora (`finalizada_em`) e não depende da chave do Financeiro |
| Comparação (`corridas_anterior`, `variacao`) | período de 1 dia compara com o mesmo dia da semana anterior; período de N dias, com os N dias de antes (`AgregadorDoPainel::periodoAnterior`) |
| Faturado das lojas, a pagar aos motoboys e margem | linhas `tipo='corrida'` não estornadas de `entregas_lancamentos`, somadas em centavos. Só com `Financeiro::ligada()`. **Só o admin recebe**; para os outros, vêm `null` |
| Km | `detalhes.km` das corridas dos motoboys no livro. Vem com o Financeiro ligado, também para quem não é admin |
| No prazo (%) | conclusão (`finalizada_em`) ≤ `orders.meta.entregas_prazo_em`. **Pedido sem prazo fica fora do %** (os de antes de 2026-10-20 não têm) |
| Tempo médio | do aceite (`orders.started_at ?: dispatched_at`) até a conclusão |
| Gráfico | 1 dia: por hora, com a média do mesmo dia da semana nas 4 semanas anteriores. Vários dias: uma barra por dia, sem média |
| Lojas e motoboys | os 6 com mais corridas, com o faturado/ganho (só admin) e o vínculo iFood da loja (`VinculosIfood::resumos`) |
| Atenção | a lista do `PainelFinanceiro::montar` (só admin) |

**Cache:**
- 60 s por central + período + admin (`entregas:painel-inicial:resumo:*`);
- 5 min para a lista Atenção (`entregas:painel-inicial:atencao:<central>`), porque o "sem valor" é a conta mais pesada.

### `GET painel-inicial/ao-vivo`

Montado pelo `AoVivo::montar`. Os pedidos em andamento e os motoboys do mapa são os do `MapaDoLider::mapa`, a mesma
lista do mapa do console e da aba Mapa do líder.

Acrescenta a cada pedido:
- `ifood`: o pedido está em `entregas_ifood_pedidos`;
- `oferta`: no pedido sem motoboy, a distribuição aberta, com `fase`, `rodada`, `rodadas` e o `vence_em` da oferta
  pendente em ISO de Brasília. Fica `null` sem distribuição (aguardando a central) ou com a distribuição encerrada.

Tudo sai em lote: 3 consultas, qualquer que seja o número de pedidos. O cache é de **5 s por central**, e o atribuir
limpa (`AoVivo::esquecer`).

Limite herdado do `PedidosNoMapa`: pedido sem coordenada no destino não aparece.

### `POST painel-inicial/pedidos/{id}/motoboy` `{motoboy: driver_public_id}`

Só admin; para os outros, devolve 403.

Usa o `TrocaDoMotoboy::trocar`, a mesma troca do líder:
- trava o pedido;
- grava `adhoc` falso;
- manda um aviso só ao motoboy novo e o `assignDriver` ao iFood.

A distribuição se encerra sozinha pelo `ObservadorDaDistribuicao`. O log sai como "líder trocou o motoboy".

## Front

O componente é `packages/fleetops/addon/components/home/painel-inicial.*`, com o mapa em `home/painel-inicial/mapa.*` e
as contas puras em `utils/painel-inicial.js`. Detalhes em `packages/fleetops/CLAUDE.md`, seção "Painel inicial".

## Testes

- `scripts/teste-php/painel-inicial-agregador.php`
- `scripts/teste-php/painel-inicial.php`
- `scripts/teste-php/painel-inicial-ao-vivo.php`
- `scripts/teste-php/painel-inicial-console.php`
- `scripts/teste-portal/painel-inicial.test.mjs`

Nos stubs, `DB::table('orders')` lê a tabela em memória (`Banco::inserir('orders', …, false)`). O `Order::$todos` só entra
nos joins.

## Ao atualizar o core-api ou o fleetops-api, confira

- se o `MapaDoLider`/`PedidosNoMapa` ainda trazem `motoboy_id`, `coleta`, `prazo_em` e `endereco`;
- se o `TrocaDoMotoboy` ainda exige pedido despachado, porque o "Atribuir" só aparece no pedido sem motoboy.
