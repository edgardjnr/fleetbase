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

Acrescenta também, para a tela de pedidos (`/fleet-ops`, ver "Tela de pedidos" em `packages/fleetops/CLAUDE.md`):
- `motoboys[].corridas_hoje`: as corridas finalizadas hoje (dia de Brasília, `Financeiro::hoje`) por motoboy, de
  `entregas_corridas_finalizadas` (o `motoboy_uuid` vira o `public_id` pelo `drivers`);
- `concluidos`: `{total, ultimos}`, as corridas finalizadas hoje, com os 6 mais recentes primeiro (`id` = public_id do
  pedido, `numero`, `loja`, `motoboy`, `finalizada_em` em ISO de Brasília). É a coluna Concluídos do Quadro.

Tudo sai em lote: 5 consultas, qualquer que seja o número de pedidos. O cache é de **5 s por central**, e o atribuir
limpa (`AoVivo::esquecer`).

Limite herdado do `PedidosNoMapa`: pedido sem coordenada no destino não aparece.

### `POST painel-inicial/pedidos/{id}/motoboy` `{motoboy: driver_public_id}`

Só admin; para os outros, devolve 403.

Usa o `TrocaDoMotoboy::trocar`, a mesma troca do líder.

- **Antes da coleta, com a distribuição ligada** (2026-10-28): o pedido volta à fila e o escolhido recebe a oferta (ver
  "Oferta ao escolhido" em `api/app/Support/Entregas/Distribuicao/CLAUDE.md`). A resposta traz
  `oferta: {motoboy, nome, vence_em}`, e o console avisa "Oferta enviada a Fulano. Se recusar, o pedido volta para a
  fila." (`avisoDaAtribuicao` em `packages/fleetops/addon/utils/painel-inicial.js`; sem nome, "Oferta enviada. Se o
  motoboy recusar, …"). Duplo clique no Atribuir devolve a mesma oferta, sem outro push.
- **Depois da coleta** (ou com a distribuição desligada): a troca direta (trava, `adhoc` falso, um aviso só ao motoboy
  novo e o `assignDriver` ao iFood), e a distribuição se encerra sozinha pelo `ObservadorDaDistribuicao`. Sem `oferta`
  na resposta, o aviso de sempre ("Motoboy atribuído.").
- Logs: "troca do motoboy: oferta ao escolhido" ou "líder trocou o motoboy".
- Testes: `scripts/teste-portal/painel-inicial.test.mjs` (`avisoDaAtribuicao`) e `scripts/teste-php/troca-com-aceite.php`.

## Comanda do pedido (folha do console)

`GET int/v1/entregas/pedidos/{id}/comanda` (`PedidoAbertoController@comanda`, montado pelo `ComandaDaCentral::resposta`,
função pura; o controller só faz as consultas). É a folha do pedido no console, no estilo da Comanda do app do motoboy
(sketch 008 A): junta numa chamada o que o app lê por quatro rotas (`.../ifood`, `.../integracao`, `.../rota` e
`.../valor` do `MotoboyController`).

**Quem lê:**
- `{id}` = public_id ou uuid, sempre filtrado pela empresa da sessão (nenhum model registra o `CompanyScope`). Não
  achou: 404 `{"errors":["Pedido não encontrado."]}`.
- Qualquer usuário da central. Os **valores** (dinheiro) só o admin (`!isNotAdmin()`); para os outros, `valores: null`
  e o `valorDoPedido` nem roda.
- A loja do portal não chega aqui: o `ProtegerPortalLoja` só libera a ela as rotas da `PERMITIDAS_INTERNAS`
  (`entregas/loja/*`), e esta não está na lista (403). O motoboy (líder incluído) leva 403 do `BarrarMotoboyNoConsole`
  em todo `int/v1`, salvo com o papel Administrador.
- **Sem o 0800/localizador do iFood e sem o telefone do cliente da integração**: só o motoboy do pedido os recebe, no
  app (`Dados*DoMotoboy`). Sem o telefone do motoboy.

**Formato e de onde vem cada campo:**

| Campo | Fonte |
|---|---|
| `pedido` | `PedidosNoMapa::umDoLider($pedido, true)`: o mesmo item do Ao vivo e do mapa do líder (`id`, `numero`, `latitude`, `longitude`, `endereco`, `status`, `motoboy`, `motoboy_id`, `aceito`, `criado_em`, `prazo_em`, `loja`, `atualizado_em`, `coleta`). Com o `true`, o destino sem coordenada vem com `latitude`/`longitude` null em vez de sumir |
| `encerrado`, `situacao_final` | `status` em `StatusDoPedido::ENCERRADOS`; `concluido` (`completed`, `done`), `cancelado` (`CANCELADOS`) ou null (inclusive `expired`) |
| `origem`, `sistema`, `teste` | `ifood` (linha em `entregas_ifood_pedidos`), `integracao` (`entregas_integracao_pedidos`, com o `sistema`) ou null; `teste` = pedido de teste do iFood |
| `numero` | o da linha do iFood ou da integração; sem linha, o `pedido.numero` (internal_id, rastreio, public_id) |
| `cliente` | o Local de entrega (`payload.dropoff`, senão a última parada). `nome` cru (em maiúsculas, como gravado; o front põe em caixa normal), null quando vazio, "Cliente iFood" ou igual à rua (um começa com o outro), a regra do `nomeDoCliente` do portal e do app. `endereco` = `street1` (o número já vem nela; sem ela, o `address`), `bairro` = `neighborhood`, `cidade` = `city`. `complemento`/`referencia` da linha do iFood ou da integração; sem linha, o `street2` do Local e referência null (no iFood o `street2` junta os dois e repetiria) |
| `observacoes` | `observacoes` da linha; sem linha, null |
| `cobranca` | `{texto, centavos, forma, troco_para_centavos}` do `CobrancaIfood`/`CobrancaDaIntegracao` (as mesmas do app); `texto` null = nada a cobrar na entrega; sem linha, `cobranca` null |
| `itens` | `ItensDoPedido::doBanco` da linha (null sem linha, sem itens ou JSON quebrado) |
| `loja` | `nome` do Vendor dono (`customer_uuid`; sem ele, o dono do Local de coleta, como o chat do pedido; sem os dois, o nome do Local de coleta), `endereco` do Local de coleta, `telefone` do Vendor (senão o do Local de coleta) |
| `motoboy` | `{id: public_id, nome}` do `driverAssigned`, ou null |
| `linha_do_tempo` | `tracking_statuses` do `tracking_number_uuid` do pedido, sem os apagados, do mais antigo ao mais novo: `{codigo, texto (status), detalhes, em}`. O texto vem do Fleet-Ops em inglês: o front traduz pelo `codigo` |
| `rota` | `RotaDoPedido::doPedido` (`{linha, metros, segundos, aproximado}`, OSRM com cache), ou null sem coordenadas ou na falha |
| `valores` | só admin: `{km, aproximado, faixa: {de_km, ate_km, acima}, valor_motoboy, valor_loja, junto, multiplicador}` do `CalculoEntregas::valorDoPedido($pedido, driver_assigned_uuid)`. `valor_loja` = valor da loja da faixa × fator da loja do multiplicador (a conta do `ValorDaEntrega::final`). Falha no cálculo: o mesmo objeto com tudo null |
| `conclusao` | só no concluído: `em` = `entregas_corridas_finalizadas.finalizada_em`; sem ela, o status `COMPLETED`; sem ele, o `updated_at` (como o relatório). `minutos` desde o `created_at` |

Campos além do pedido original: `linha_do_tempo[].detalhes` e, em `valores`, `aproximado`, `faixa` e `junto`.

Datas em ISO de Brasília (`2026-10-24T21:10:00-03:00`): texto sem fuso do banco é lido no fuso do app (regra do
`api/CLAUDE.md`).

**Efeito colateral aceito do `valorDoPedido`** (só quando o admin abre a folha): o mesmo do card de aceitar do app e do
alarme. Na primeira vez que o pedido tem km, grava o `entregas.km_rota` no meta do pedido (`updateQuietly`: sem eventos,
sem socket e sem mexer no `updated_at`) e congela a faixa em `entregas_valores_pedido` (`ValoresCongelados`). Pedido
lançado no financeiro não muda. A prévia do pedido sem motoboy não grava nada.

Falha na rota ou no valor não derruba a folha: o campo sai null e o log `[entregas] comanda: falha ao montar a rota do
pedido` / `... ao calcular o valor do pedido` (só o public_id e a classe do erro; `entregas_application`).

Teste: `scripts/teste-php/comanda-do-pedido.php`.

## Front

A mesma API serve à tela de pedidos do Fleet-Ops (`/fleet-ops`: Mapa e Quadro, componentes `tela-pedidos/*`). O
"Trocar motoboy" do detalhe usa o mesmo `POST` do Atribuir.

O componente do painel é `packages/fleetops/addon/components/home/painel-inicial.*`, com o mapa em `home/painel-inicial/mapa.*` e
as contas puras em `utils/painel-inicial.js`. Detalhes em `packages/fleetops/CLAUDE.md`, seção "Painel inicial".

## Testes

- `scripts/teste-php/painel-inicial-agregador.php`
- `scripts/teste-php/painel-inicial.php`
- `scripts/teste-php/painel-inicial-ao-vivo.php`
- `scripts/teste-php/painel-inicial-console.php`
- `scripts/teste-php/comanda-do-pedido.php` (a comanda; o `pedidos-no-mapa.php` cobre o `umDoLider` com `mesmoSemDestino`)
- `scripts/teste-portal/painel-inicial.test.mjs`

Nos stubs, `DB::table('orders')` lê a tabela em memória (`Banco::inserir('orders', …, false)`). O `Order::$todos` só entra
nos joins.

## Ao atualizar o core-api ou o fleetops-api, confira

- se o `MapaDoLider`/`PedidosNoMapa` ainda trazem `motoboy_id`, `coleta`, `prazo_em` e `endereco`;
- se o `TrocaDoMotoboy` ainda exige pedido despachado, porque o "Atribuir" só aparece no pedido sem motoboy;
- comanda: se a `tracking_statuses` ainda tem `tracking_number_uuid`, `code`, `status`, `details`, `created_at` e
  `deleted_at` (o `Order::trackingStatuses` liga pelo `tracking_number_uuid`) e se o status da conclusão ainda é o
  código `COMPLETED`;
- comanda: se o `Place` ainda tem `street1`, `street2`, `neighborhood`, `city`, `phone` e o acessor `address`, e o
  `Vendor`, o `phone`;
- comanda: se o `HasMetaAttributes::updateMeta` do core ainda grava com `updateQuietly` (o km que o `valorDoPedido`
  guarda no meta não pode disparar o `OrderObserver` nem o socket).
