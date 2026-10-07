# Custo da Google Geocoding API: o `track()` do motoboy geocodifica a cada posição

**Data:** 2026-10-07 · **Estado:** diagnosticado e provado; **nada foi corrigido**. Este relatório é o ponto de partida
da sessão que vai corrigir.

## 1. Sintoma

Projeto Google Cloud **RestaurantePro** (`gen-lang-client-0815669229`):

- **16.076** chamadas à Geocoding API em 14 dias, quase todas de 2 a 7/10/2026, ao custo de cerca de R$ 157 até 6/10.
- O gasto **continuava** em 7/10.

Medido no Console → Plataforma Google Maps → Métricas, com a Geocoding API selecionada:

| Agrupado por | Resultado |
|---|---|
| Credencial | **uma só**: "Google Maps - Restaurante (Geocoding + Distance Matrix)" |
| Plataforma | `PLATFORM_TYPE_WEBSERVICE`, isto é, um servidor chamando a API HTTP |
| Método | `google.places.Geocoding.Http` |
| Horário | rajadas contínuas das 6h às 23h (BRT), de 0,1 a 0,35 req/s, o horário dos motoboys online |
| Por dia | cerca de 1 mil (3/10), 5,4 mil (4/10), 2 mil (5/10), 1,6 mil (6/10) e 5,7 mil (último ponto, 7/10) |

## 2. Origem descartada: o painel RestaurantePro

O repositório `restaurantepro-main` também usa essa chave, em `api/google/geocode.js`, nas edges `public-order` e
`foody-tick` e no `CompanyAddressSettings`. Nenhum desses pontos roda em loop, e os logs mostram volume mínimo:

- Vercel: 5 chamadas a `/api/google/geocode` de 1 a 7/10, e 1 em 6/10.
- Edge `public-order`: 0 chamadas em 6/10.
- Pedidos: 7 de 1 a 6/10.

**O problema não está no RestaurantePro.**

## 3. Causa raiz, provada no banco de produção

`packages/fleetops/server/src/Http/Controllers/Api/v1/DriverController.php`, método `track()`. O código é original do
fleetops-api 0.6.65, a mesma versão do `api/composer.lock`.

```php
$isGeocodable = Carbon::parse($driver->updated_at)->diffInMinutes(Carbon::now(), false) > 10
    || empty($driver->country) || empty($driver->city);          // linha ~323
...
if ($isGeocodable) {
    $geocoded = Geocoder::reverse($latitude, $longitude)->get()->first();   // linha ~360
    $driver->updateQuietly(['city' => $geocoded->getLocality(), 'country' => ...]);
}
```

**Por que vira loop.** No Brasil, a geocodificação reversa do Google costuma devolver a cidade como
`administrative_area_level_2`, **sem `locality`**. Então `getLocality()` devolve `null`, `city` continua vazia e a
condição `empty($driver->city)` fica verdadeira para sempre: **cada posição enviada gera uma chamada paga**.

Três agravantes:
- O app do motoboy (`entregas-navigator/src/contexts/LocationContext.tsx:181`) usa `distanceFilter: 10`. Numa moto
  em movimento, isso dá cerca de 1 posição por segundo, por motoboy. Ele chama `POST v1/drivers/{id}/track` pelo HTTP
  do `react-native-background-geolocation`, além do `trackDriver` manual.
- O cache do geocoder (`config/geocoder.php`, store `geocode`) é por coordenada exata, e cada posição de GPS é
  diferente, então o cache não ajuda.
- A chave usada pelo servidor está no banco, em `settings.key = 'system.services.google_maps'` (Admin → Configurar →
  Serviços do console), e é a **mesma** chave do RestaurantePro. O `GOOGLE_MAPS_API_KEY` do stack não é usado: o
  `stack.env` nem existe na VPS.

**Prova (consultas rodadas pelo Edgard na VPS em 7/10):**

```
drivers: os 4 (Edgard, Lailton, Edmar, Roger) com city = NULL e country = BR
positions de Driver por dia: 02/10 391 · 03/10 4469 · 04/10 2941 · 05/10 1624 · 06/10 6088 · 07/10 780 (parcial)
total = 16.293 posições  ≈  16.076 geocodificações no Google
```

A divisão por dia difere porque o banco conta em UTC e o gráfico do Google em BRT. O total bate quase 1 para 1.

Consultas usadas. O Claude não tem SSH: quem roda é o Edgard. A senha vem da variável do próprio container:

```
docker exec -it $(docker ps -q -f name=entregas_database) sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" fleetbase -e "select d.public_id, u.name, d.city, d.country, d.online, d.updated_at from drivers d left join users u on u.uuid = d.user_uuid where d.deleted_at is null order by d.updated_at desc limit 20; select date(created_at) dia, count(*) posicoes from positions where subject_type like \"%Driver%\" and created_at >= \"2026-09-30\" group by dia order by dia;"'
```

## 4. Correções, em ordem de prioridade

### 4.1 Servidor: o `track()` não pode mais geocodificar (zera o custo)

**Restrição do repositório** (CLAUDE.md): alterações em `packages/*/server` **não chegam à produção**, porque o PHP
vem do composer. A correção precisa ir em `api/app`, trocando a classe pelo container no
`AppServiceProvider::register()`, como já é feito com `DispatchAdhocOrders → ReenviarPedidosAbertos` e com
`COMANDOS_SEM_UTC`. Ainda não existe precedente de troca de **controller**, mas o Laravel monta o controller da rota
com `$container->make($classe)`, então o `bind` funciona. A rota é
`$router->match(['put','patch','post'], '{id}/track', 'DriverController@track')`, em
`packages/fleetops/server/src/routes.php:39`.

A cidade do motoboy (`drivers.city`) não aparece em nenhuma tela do Entregas. A seção do portal no CLAUDE.md diz "O
servidor não tem geocodificação". Remover a geocodificação não perde nada visível, **mas confira antes**: rode
`grep` por `city` no console, no app, no portal e em `api/app`.

Duas formas de fazer, a escolher pela sessão:

- **A. Subclasse que neutraliza o geocoder só durante o `track()`, sem copiar o método.**
  `App\Http\Controllers\Entregas\...` estende `Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController`. O
  `track()` dela troca a facade `Geocoder\Laravel\Facades\Geocoder` (accessor `geocoder`) por um objeto cujo
  `reverse()` devolve ele mesmo e cujo `get()` devolve `collect()`, chama `parent::track()` e restaura a original
  num `finally`.
  - Vantagem: não duplica as cerca de 100 linhas do método, e o resto (posição, veículo, broadcast, geofence) segue
    o upstream.
  - Risco: é um truque de facade. A imagem é FrankenPHP, então confirme se roda em modo worker; o `finally` garante
    a restauração, mas confirme. A troca pelo `Facade::swap` também mexe na instância `geocoder` do container.
- **B. Subclasse com cópia do `track()` sem o bloco `$isGeocodable`/`Geocoder::reverse`.**
  - Explícita, mas é mais um ponto "ao atualizar o fleetops-api, confira", como já existe para o
    `ReenviarPedidosAbertos`.

Nas duas formas:
- **Teste** em `scripts/teste-php/` (php-wasm 8.2; uso no cabeçalho do `rodar.mjs`). No mínimo:
  1. um `track()` com motoboy de `city` vazia não chama o geocoder;
  2. posição, veículo e resposta continuam iguais;
  3. **guarda contra o upstream**: o `track()` da cópia em `packages/fleetops` ainda é o único ponto de geocodificação
     desse fluxo e ainda usa `Geocoder::reverse`. Se o upstream mudar, o teste falha e avisa.
- Conferir a sintaxe com `scripts/teste-php/sintaxe.mjs`.
- Documentar no CLAUDE.md (seção de manutenção do fleetops-api): "ao atualizar o fleetops-api, confira o `track()` do
  `Api\v1\DriverController`".
- **Deploy:** só a API, com `bash deploy/atualizar.sh` na VPS. O push em `main` não publica.
- **Verificação depois do deploy:** no Console Google, o gráfico por segundo da Geocoding vai a cerca de zero com
  motoboys online. No banco, o número de `positions` continua subindo e o Google não acompanha mais.

**Atenção:** não "consertar" gravando a cidade a partir de `administrative_area_level_2` sem tirar o reverse do
caminho quente. Com a cidade preenchida, o código ainda geocodifica a cada volta de mais de 10 min parado, e qualquer
resposta sem cidade recoloca o loop. O objetivo é **zero** chamadas pagas por posição.

### 4.2 Chaves Google separadas, depois limite diário

- **Não ponha limite diário na chave atual antes de separá-la.** O Entregas esgotaria o limite logo de manhã, e o
  cálculo de frete do delivery do RestaurantePro (`/api/google/*`, `public-order`, `foody-tick`, menos de 20 por dia)
  passaria a falhar pelo resto do dia.
- **Entregas:** depois de 4.1, avaliar se o servidor precisa de chave Google. Ela também alimenta a busca de endereço
  (`PlaceSearch`/`GeocoderController`) no console e no portal, que hoje marcam no mapa, então confirme o uso real.
  Se precisar, crie uma chave **própria** no projeto `entregas-restaurantepro`, restrita à Geocoding API, com limite
  diário, e cadastre em Admin → Serviços. Se não precisar, esvazie `system.services.google_maps`.
- **RestaurantePro:** depois da separação, restrinja a chave "Google Maps - Restaurante" às APIs Geocoding e Distance
  Matrix, ponha limite diário de cerca de 300 na Geocoding e troque a chave. Ela está no histórico do git do
  `restaurantepro-main` (relatório de segurança de 02/09, A4) e nunca foi trocada. É preciso atualizar a env da
  Vercel, os secrets do Supabase e o `.env.local`.
- Mudar configuração da conta Google exige o "sim" explícito do Edgard em cada ação.

### 4.3 App do motoboy: menos posições (exige APK novo)

`entregas-navigator/src/contexts/LocationContext.tsx:181`: subir o `distanceFilter` de 10 m para 30–50 m e avaliar
um intervalo mínimo entre envios. Isso reduz bateria, escrita em `positions` e broadcast de socket. O custo do Google
já some com 4.1, então este passo é otimização.

Antes de mexer, confira o que depende da frequência atual:
- chegada a 100 m da coleta e da entrega pelo GPS, que dispara `arrivedAtOrigin`/`arrivedAtDestination` no iFood;
- o mapa do líder e o mapa da loja (atualização a cada 5–10 s);
- o deslizar do capacete.

### 4.4 RestaurantePro: desperdício pequeno, prioridade baixa

Fica no repositório `restaurantepro-main`. São centavos, e é opcional:
- cada pedido de entrega geocodifica o mesmo endereço até 3 vezes (navegador, `public-order`, `foody-tick`), e
  `orders`/`customer_addresses` não guardam lat/lng;
- os proxies `api/google/*` não têm limite de volume no servidor, e a guarda de origem é contornável com cabeçalho
  forjado (relatório CORS-02, de 14/09).

## 5. O que já foi conferido e não é a causa

- **Geocodificação a cada tecla, em render, lista ou polling:** não existe, nem no RestaurantePro nem no portal do
  Entregas.
- **Retry sem limite:** os despachos da Foody tiveram 1 tentativa cada.
- **Uso por terceiros da chave do RestaurantePro:** o volume é todo explicado pelas `positions`, então não há sinal
  disso. Mesmo assim a chave deve ser trocada (seção 4.2).
- **Maps SDK (1.788 chamadas no projeto RestaurantePro): NÃO conferido.** O app do motoboy usa o Maps SDK for Android
  com chave do projeto `entregas-restaurantepro`, segundo o CLAUDE.md, então essas chamadas devem vir de outro mapa,
  provavelmente o mapa ao vivo do console ou do portal com uma chave do projeto RestaurantePro. Para conferir: em
  Métricas, escolher a API Maps, agrupar por Credencial e depois por Plataforma e Domínio.
