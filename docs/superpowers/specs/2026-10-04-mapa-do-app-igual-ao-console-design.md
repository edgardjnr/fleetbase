# Mapa do pedido no app do motoboy igual ao do console

Data: 2026-10-04. Pedido do Edgard: "o mapa do Navigator é diferente do site; o veículo também; temos que padronizar".

## Situação anterior

| | Console | App (`LiveOrderRoute`) |
|---|---|---|
| Motoboy | capacete na cor da situação, sem girar, com o nome | `vehicle_avatar` (caminhão padrão da Fleetbase), girando com o GPS |
| Coleta / entrega | círculo verde **P** / vermelho **D** | dois pinos azuis iguais com balão escuro do endereço |
| Linha da rota | OSRM, na cor do status do pedido | Google Directions, **desligado** (pago): sem linha e sem enquadrar a rota |
| Resumo | distância e tempo | nada |

O card de aceitar mostrava só motoboy → loja (sem o cliente). O `DriverMarker` reatribuía um `const` e quebrava a cada posição do socket.

## Decisão

A linha da rota vem da **nossa API**, que consulta o mesmo OSRM do console e guarda em cache. Os celulares não consultam o OSRM público, e o Google Directions continua desligado. O fundo continua sendo o Google Maps (SDK grátis); o que fica igual é o que vai por cima.

## API

`GET v1/entregas/motoboy/pedidos/{id}/rota` (`MotoboyController@rota`, limitador `entregas-motoboy-rota`: 120/min por motoboy).

- Acesso igual ao do `valor`: token de motoboy (`MotoboyDaSessao`, chave de API → 403), pedido dele ou aberto (`GanhosDoMotoboy::podeVer`, senão 404), empresa da sessão.
- Resposta: `{ pedido, rota: { linha: [[lat, lng]…], metros, segundos, aproximado } | null, situacao }`.
  - `rota` é null quando a coleta ou o destino não têm posição.
  - `situacao` (`livre`, `coleta`, `entrega`, `offline`) segue a `SituacaoDoMotoboy`, a mesma regra do capacete do console.
- `App\Support\Entregas\RotaDoPedido`:
  - OSRM `overview=simplified` (polyline, decodificada por nós);
  - até 1000 pontos;
  - cache por pedido e coordenadas: 24 h quando a rota vem do OSRM, 5 min quando é a linha reta (OSRM fora, sem rota, 0 m, um ponto só), que usa km × 1,3 e vem sem tempo;
  - nada no `meta` do pedido.

## App

- `src/utils/mapa-da-entrega.ts` (puro, testado):
  - cores e camadas da linha por status (`ORDER_ROUTE_STATUS_COLORS`/`routeStyleForStatus` do console);
  - cores P/D;
  - situação provisória pelo pedido;
  - resumo "3,2 km · 9 min" (com "≈" na linha reta);
  - posição dos lugares;
  - pontos do enquadramento;
  - capacete só a até 30 km das paradas.
- `src/hooks/use-rota-da-entrega.ts`:
  - cache por pedido + status: 2 min, ou 1 h com o pedido encerrado;
  - no máximo 3 chamadas simultâneas;
  - em erro, o mapa fica só com P e D.
- `MarcadorParada`: círculo com a letra, borda branca e o endereço no balão do toque.
- `MarcadorCapacete`: PNG do console, nome embaixo, sem rotação. A posição vem do GPS do próprio celular (`LocationContext`).
- `LiveOrderRoute`:
  - polyline em duas camadas (tracejada na linha reta);
  - enquadra a rota e o capacete quando a rota, as paradas ou a presença do capacete mudam (o movimento não reenquadra);
  - o capacete some em pedido encerrado;
  - a prop `focusCurrentDestination` saiu: todos os mapas mostram loja → cliente.
- O `DriverMarker`/`VehicleMarker` ficaram com o `let` e a checagem certa do `onHeadingChange`.

## Testes

- `scripts/teste-php/rota-do-motoboy.php` (php-wasm): polyline, cache, linha reta, permissões e situação.
- `node --experimental-strip-types --test scripts/testes/mapa-da-entrega.teste.ts` (app).

## Implantação

Deploy `api` (rota nova) + APK novo. O APK antigo não chama a rota e continua igual.
