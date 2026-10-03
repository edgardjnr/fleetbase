# Portal da Loja — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** cada restaurante (loja) entra num portal próprio, cria pedidos só com o destino (coleta fixa no endereço da loja), acompanha, cancela antes do aceite e vê o extrato — sem enxergar nada de outra loja; os motoboys continuam recebendo os pedidos de todas.

**Architecture:** reativa o engine `@fleetbase/customer-portal-engine` (frontend local em `packages/customer-portal`; API `fleetbase/customer-portal-api 0.0.13` já instalada via Composer). Loja = `Vendor` (type `customer`) do Fleet-Ops com um `Place` próprio (dono = o Vendor); usuários da loja = `Contact type=customer` + `VendorPersonnel`. O PHP do portal, do Fleet-Ops e do core vem do Composer e não pode ser editado: as regras ficam em **dois middlewares globais nossos** em `api/app` — `ProtegerPortalLoja` (o que o usuário de loja pode chamar; nega por padrão) e `RegrasPortalLoja` (coleta fixa, destino com coordenadas, despacho aos motoboys, cancelamento, endereços). Endpoints novos em `api/app/Http/Controllers/Entregas/`.

**Tech Stack:** Laravel (Fleetbase core-api 1.6.61, fleetops-api 0.6.65, customer-portal-api 0.0.13 — as cópias em `packages/core-api`, `packages/fleetops/server` e `packages/customer-portal/server` são dessas mesmas versões), Ember 5 engines (Glimmer, ember-concurrency, ember-intl 6), pnpm.

**Spec:** `docs/superpowers/specs/2026-10-02-portal-da-loja-design.md`

---

## Fatos do código (verificados nas versões de produção)

**Autenticação e permissões**
- `fleetbase.protected` = AddQueuedCookies + StartSession + `auth:sanctum` + `SetupFleetbaseSession` (grava `session('user')`, `session('company')`) + `AuthorizationGuard` + TrackPresence + ValidateETag. Middleware global roda **antes** → identificar o usuário com `Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken())?->tokenable` (o core usa essa mesma classe).
- **A API pública `v1/*` aceita o token Sanctum de qualquer usuário** (`AuthenticateOnceWithBasicAuth::authenticateSanctumToken`), sem permissões por papel → com o token da loja, `GET v1/orders` devolveria os pedidos de todas as lojas. Precisa ser negada para usuário de loja.
- `AuthorizationGuard` só confere permissão em controller de recurso. O papel **"Fleet-Ops Customer"** (dado a todo usuário de cliente) tem `list/create/update/delete contact` (sem filtro → contatos de todas as lojas), `list/create/update/delete place` e **`iam update user`**: `PUT int/v1/users/{id}` aceita `user.role`, `user.permissions`, `user.policies` → dá para virar Administrador. Não confiar nas permissões do Fleetbase.
- Login do portal (`POST customer-portal/int/v1/auth/login`, `{identity, password}` → `{token}`): só `type=customer`; **exige usuário verificado** (`email_verified_at` ou `phone_verified_at`); **não confere `status`**. Em lugar nenhum o Fleetbase barra usuário `inactive` (nem login, nem token).
- `User`: `type` e `password` são guarded; `changePassword($s)` (hash no mutator, salva), `activate()` / `deactivate()` (status do usuário e do vínculo com a empresa), `isNotAdmin()`, `tokens()` (Sanctum).

**Portal do Cliente (API, Composer)**
- Rotas em `customer-portal/int/v1/` (todas com `fleetbase.protected`, exceto `auth/login` e `two-fa/*`). Conta = Vendor com `VendorPersonnel` ativo do contato do usuário (tem prioridade) ou o próprio Contact. Pedidos, endereços e membros são filtrados pela conta (`customer_uuid` / `owner_uuid`).
- `POST orders`: se `payload` é array → monta; se é **string → usa um payload existente da empresa por public_id** (de qualquer loja); senão lê `pickup`/`dropoff`/`return`/`waypoints`/`entities` do topo. `meta` é gravado como veio (o cache do km da cobrança mora em `meta.entregas.km_rota`). Também aceita `files` (uuids) e `scheduled_at`. **Cria o pedido com `status=created`, sem `adhoc` e sem despachar** → nenhum motoboy seria avisado.
- `resolveAccountPlace(string)` só acha Place com `owner_uuid` = conta e `owner_type` = `Utils::getMutationType($conta)` (= nome da classe, `Fleetbase\FleetOps\Models\Vendor`); senão devolve **null** (o pedido sairia sem coleta).
- O front do portal manda os campos no topo (`pickup`, `dropoff`, `return`, `waypoints`, `entities`, `files`…) com `serializePlace` (uuid, public_id, latitude, longitude, location…).
- `POST places` / `PATCH places/{id}`: só leem `latitude`/`longitude` do corpo. O mapa do portal (`ModelCoordinatesInput` → "Selecionar no mapa") grava a posição em `place.location` (GeoJSON `[lng, lat]`), **que o servidor ignora** → sem lat/lng, cai na geocodificação Google, e **o servidor não tem chave Google** (`GOOGLE_MAPS_API_KEY` vazio) → endereço em (0,0) ou erro 500. O km da cobrança sai das coordenadas.
- `cancelOrder` cancela em qualquer status não encerrado (só `update(status=canceled)`); `rescheduleOrder` existe.
- Sem checagem de admin: `settings/config` (grava a configuração do portal), `contacts/{id}/convert-to-vendor`. `account/personnel-candidates` lista **todos** os contatos `customer` da empresa (usuários de todas as lojas). `POST account/personnels` aceita `contact` = qualquer contato customer da empresa.
- Front do portal chama em `int/v1`: `settings/branding` (rota `application` do portal e do console), `users/me`, `users/locale` (GET/POST), `auth/session`, `auth/organizations`, `two-fa/check`, `files/upload` (foto do perfil, `type=user_avatar`, `subject_uuid` = id do usuário), `PUT users/{id}` (perfil), `geocoder/reverse` e `geocoder/query` (mapa de coordenadas). Se `customer-portal/int/v1/account` falhar, cai em `int/v1/customers` (não deve acontecer para loja).

**Pedidos e motoboys**
- Criação pela central: `dispatched` padrão `true` → `firstDispatchWithActivity()`; com `adhoc=true`, o listener `HandleOrderDispatched` avisa os motoboys `available` e `online` num raio de `adhoc_distance` (null → opção da empresa `fleetops.adhoc_distance`, padrão 6000 m) da coleta.
- "Motoboy aceitou" = `orders.started = 1` (aceite adhoc: `POST v1/orders/{id}/start` com `assign`). `driver_assigned_uuid`/`dispatched` sozinhos não servem.
- **`orders.customer_type` varia conforme a rota que criou o pedido:** o portal grava `Fleetbase\FleetOps\Models\Vendor`; a criação pelo console (`normalizeCustomerType` → `getModelClassName`) e a API v1 gravam `\Fleetbase\FleetOps\Models\Vendor` (barra inicial). **O critério canônico da loja do pedido é `orders.customer_uuid`** (como no `PortalOrderService` do portal).
- Driver: `name` e `photo_url` (avatar do usuário) são accessors; `location` é Point.

**Armadilhas do Fleetbase achadas na execução**
- `Contact::user()` é `belongsTo(User)->where('type', $this->type)`: em `with`/`load`/`refresh`/`whereHas` a relação é montada numa instância vazia (`type IS NULL`) e nunca acha o usuário. Usar **`Contact::anyUser`**. Pelo mesmo motivo, o Fleetbase **não detecta e-mail/telefone repetido entre contatos de cliente** (o `whereHas('user')` de `createUserFromContact` falha): o mesmo login ficaria ligado a duas lojas — e o portal juntaria os pedidos de todas as contas do usuário.
- `CustomerUserConflictException extends UserAlreadyExistsException` (o `catch` da subclasse vem primeiro).
- `PlaceObserver::creating` passa nome e endereço para maiúsculas (`strtoupper`, só na criação) — comportamento de todo o Fleetbase; mantido.
- `POST customer-portal/int/v1/places` usa `Place::firstOrNew([empresa, dono, name = strtoupper(name ?: street1), street1 = strtoupper(street1)])` e **sobrescreve** um endereço salvo com o mesmo nome e rua (inclusive o Local da loja, que é da mesma conta). `PATCH`/`DELETE places/{id}` também mudam/apagam endereços usados por pedidos antigos (o km da cobrança sai das coordenadas atuais; Place apagado some do payload).

**Modelos e cadastro**
- `Contact::create(type customer)` → `ContactObserver::saving` → `createUser()`: User `type=customer`, `status=pending`, senha aleatória, papel "Fleet-Ops Customer", vínculo com a empresa. Erros: `Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException` (e-mail/telefone já de outro contato), `Fleetbase\FleetOps\Exceptions\CustomerUserConflictException` (e-mail/telefone de usuário da equipe), `\Exception('... is not available.')`.
- `VendorPersonnel`: fillable `vendor_uuid, contact_uuid, role, status, invited_by_uuid`; relações `vendor()`, `contact()`. `Vendor`: `vendorPersonnel()` (HasMany), `place()`, fillable com `place_uuid`, `type`, `status`. O portal cria a conta-empresa com `Vendor type=customer`.
- Nenhum desses modelos tem escopo global ligado à sessão → dá para consultar no middleware global.

**Front (console)**
- `@fleetbase/fleetops-data` `customer`: `customer_type` + `isVendor`; `vendor`: `place_uuid` + `@belongsTo('place') place`. `order/form/details.js#selectCustomer` grava `customer_type` como `fleet-ops:<tipo>`.
- `ember-ui` `CoordinatesInput`: centro padrão do mapa = whois ou **Singapura** (`DEFAULT_LATITUDE = 1.3521`); `ModelCoordinatesInput` grava `model.location = new Point(lng, lat)`.
- `modalsManager.show(..., { confirm })`: se `confirm` devolve promise, o modal fecha no `finally`; com `keepOpen: true`, fica aberto até chamar o `done` recebido em `confirm(modalsManager, done)`.
- Portal: re-exports em `packages/customer-portal/app/` para rotas, controllers, templates e componentes; traduções completas (`node scripts/i18n-check.cjs customer-portal` → exit 0).

**Ambiente**
- Não há PHP local. Sintaxe PHP: `php-parser` (npm) no scratchpad (Task 0). Teste real: contra produção após o deploy (Task 14).

## Mapa de arquivos

**API (`api/`)**
- Create `app/Support/Entregas/LojaDoUsuario.php` — loja (Vendor) e Place de coleta de um usuário.
- Create `app/Support/Entregas/CalculoEntregas.php` — faixas, km da rota, pedidos concluídos, linhas de entrega, loja do pedido.
- Modify `app/Http/Controllers/Entregas/PagamentoMotoboysController.php` — usa `CalculoEntregas`.
- Create `app/Http/Controllers/Entregas/LojasController.php` — CRUD admin de lojas e usuários.
- Create `app/Http/Controllers/Entregas/PortalLojaController.php` — `minha-loja`, `extrato`, `pedidos/{id}/motoboy`.
- Create `app/Http/Middleware/ProtegerPortalLoja.php` — controle de acesso do usuário de loja.
- Create `app/Http/Middleware/RegrasPortalLoja.php` — regras de negócio das rotas do portal.
- Modify `app/Http/Kernel.php`, `app/Providers/RouteServiceProvider.php`.

**Console** — Modify `console/package.json`, `console/pnpm-workspace.yaml`, `console/Dockerfile.dockerignore`, `console/app/router.js` (se não for gerado), `console/pnpm-lock.yaml`.

**ember-ui** — Modify `addon/components/coordinates-input.js`, `addon/components/model-coordinates-input.hbs` (centro do mapa).

**Fleet-Ops (`packages/fleetops/`)** — Create `addon/routes/management/lojas.js`, `addon/controllers/management/lojas.js`, `addon/templates/management/lojas.hbs`; Modify `addon/routes.js`, `addon/components/layout/fleet-ops-sidebar.js`, `addon/components/order/form/details.{js,hbs}`, `addon/components/order/form/route.{js,hbs}`, `translations/{en-us,pt-br}.yaml`.

**Portal (`packages/customer-portal/`)** — `addon/templates/portal.hbs`, `addon/routes.js`, `addon/extension.js`, rotas ocultas, `addon/components/portal/settings/members.*`, `addon/controllers/portal/account.js`, formulário de pedido (`form.*`, `form/route.*`, `modals/portal-order-place-form.hbs`, validação, `routes/portal/orders/new.js`), detalhe (`details.*`, novo `details/motoboy.*`), lista (`controllers/portal/orders.js`, `routes/portal/orders.js`), mapa (`workspace/map.js`), extrato (novos), re-exports em `app/`, traduções.

**Outros** — Create `scripts/php-lint.cjs`, `scripts/teste-isolamento-lojas.mjs`; Modify `.gitignore`, `CLAUDE.md`.

---

### Task 0: Ferramenta de lint PHP

**Files:** Create `scripts/php-lint.cjs`

- [ ] **Step 1: instalar o parser no scratchpad (fora do repo)** — `npm --prefix "$SCRATCH/phplint" i php-parser@3` (SCRATCH = diretório de scratchpad da sessão).

- [ ] **Step 2: criar o script**

```js
// Uso: PHP_PARSER_DIR=<pasta com node_modules/php-parser> node scripts/php-lint.cjs arquivo1.php [arquivo2.php...]
// Só sintaxe (não há PHP no Windows), na versão da produção (PHP 8.2, docker/Dockerfile).
// Sai com 1 se algum arquivo não parsear.
const path = require('path');
const fs = require('fs');
const Engine = require(path.resolve(process.env.PHP_PARSER_DIR || '.', 'node_modules', 'php-parser'));
const parser = new Engine({ parser: { version: '8.2', extractDoc: true }, ast: { withPositions: true } });
let falhou = false;
for (const arquivo of process.argv.slice(2)) {
    try {
        parser.parseCode(fs.readFileSync(arquivo, 'utf8'), arquivo);
        console.log('ok   ', arquivo);
    } catch (e) {
        falhou = true;
        console.log('ERRO ', arquivo, '-', e.message);
    }
}
process.exit(falhou ? 1 : 0);
```

- [ ] **Step 3: validar** — `PHP_PARSER_DIR="$SCRATCH/phplint" node scripts/php-lint.cjs api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php` → `ok` e exit 0; um arquivo com erro de sintaxe proposital (no scratchpad) → `ERRO` e exit 1.
- [ ] **Step 4: Commit** — `git add scripts/php-lint.cjs && git commit -m "Scripts: lint de sintaxe PHP sem PHP local"`

---

### Task 1: Reativar o portal no console

**Files:** Modify `console/package.json`, `console/pnpm-workspace.yaml`, `console/Dockerfile.dockerignore`, `console/app/router.js` (só se não for gerado no build), `console/pnpm-lock.yaml`

- [ ] **Step 1: ver o que o commit 1725beee tirou** — `git show 1725beee -- console/package.json console/pnpm-workspace.yaml console/Dockerfile.dockerignore console/app/router.js console/app | grep -n -B3 -A3 "customer-portal"`
- [ ] **Step 2: devolver exatamente o que saiu** (mesmo formato e posição das linhas vizinhas): a dependência `"@fleetbase/customer-portal-engine": "link:../packages/customer-portal"`, a linha do workspace e a exceção no `Dockerfile.dockerignore`. Validar o dockerignore com `@balena/dockerignore` (CLAUDE.md: padrões com `/` no final já excluíram tudo): `packages/customer-portal/addon/engine.js` precisa ser **incluído** e `packages/customer-portal/server/src/routes.php` pode ficar de fora.
- [ ] **Step 3: router** — se o commit 1725beee mexeu em `console/app/router.js`, reverter essa parte; se o router é gerado no build a partir do `node_modules` (CLAUDE.md), não editar à mão e anotar isso no relatório.
- [ ] **Step 4: lockfile** — `cd console && pnpm install` → sem erro; `git diff --stat console/pnpm-lock.yaml` mostra o bloco do `@fleetbase/customer-portal-engine` de volta. Não commitar `node_modules`.
- [ ] **Step 5: Commit** — `git add console/package.json console/pnpm-workspace.yaml console/Dockerfile.dockerignore console/pnpm-lock.yaml [console/app/router.js] && git commit -m "Console: reativa o Portal do Cliente (portal da loja)"`

---

### Task 2: Loja do usuário (`LojaDoUsuario`)

**Files:** Create `api/app/Support/Entregas/LojaDoUsuario.php`

- [ ] **Step 1: criar a classe**

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;

/**
 * Entregas RestaurantePro: a loja (Vendor) de um usuário do portal.
 *
 * Usuário da loja = Contact type=customer ligado ao Vendor por VendorPersonnel ativo
 * (mesma regra do PortalAccountResolver do customer-portal-api).
 */
class LojaDoUsuario
{
    public static function vendor(?string $userUuid): ?Vendor
    {
        if (!$userUuid) {
            return null;
        }

        return Vendor::whereHas('vendorPersonnel', function ($query) use ($userUuid) {
            $query->where('status', 'active')->whereHas('contact', function ($contato) use ($userUuid) {
                $contato->where('user_uuid', $userUuid)->where('type', 'customer');
            });
        })->first();
    }

    /** Local de coleta da loja (o Place do Vendor). */
    public static function coleta(?Vendor $vendor): ?Place
    {
        if (!$vendor || !$vendor->place_uuid) {
            return null;
        }

        return Place::where('uuid', $vendor->place_uuid)->first();
    }

    public static function contato(?string $userUuid): ?Contact
    {
        return $userUuid ? Contact::where(['user_uuid' => $userUuid, 'type' => 'customer'])->first() : null;
    }
}
```

- [ ] **Step 2: lint** → ok.
- [ ] **Step 3: Commit** — `git add api/app/Support/Entregas/LojaDoUsuario.php && git commit -m "API: resolve a loja do usuário do portal"`

---

### Task 3: Extrair o cálculo (`CalculoEntregas`) e agrupar a cobrança pela loja dona do pedido

**Files:** Create `api/app/Support/Entregas/CalculoEntregas.php`; Modify `api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php`

Regra: **para pedidos sem loja, o resultado tem de ser idêntico ao de hoje** (mesmas chaves `nome:<nome em minúsculas>` / public_id do Place, mesmo `loja_nome`, mesmos valores). Pedido com loja (`customer_type` = classe do Vendor) passa a ter chave `loja:<public_id do Vendor>` e nome = nome do Vendor.

- [ ] **Step 1: criar `CalculoEntregas`**

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: pagamento dos motoboys e cobrança das lojas por faixa de km.
 * Usado pela tela "Pagamento e cobrança" (PagamentoMotoboysController) e pelo extrato do
 * portal da loja (PortalLojaController).
 *
 * Uma tabela de faixas só (`entregas.faixas`): cada faixa tem o km máximo e dois valores, o pago
 * ao motoboy e o cobrado da loja. A entrega vale o valor da faixa em que o km dela cai
 * (0 < km ≤ 1 → 1ª faixa, 1 < km ≤ 2 → 2ª…). Acima da última faixa vale o valor da última.
 *
 * A loja de cada pedido é o Vendor dono do pedido (o cliente do pedido), como nos pedidos do
 * portal da loja e nos que a central cria escolhendo a loja. Pedidos sem loja caem no nome do
 * local de coleta (lojas com o mesmo nome são agrupadas, porque a integração pode criar um Place
 * novo por pedido); sem nome, no próprio Place.
 *
 * O km de cada entrega é a rota de rua loja (pickup) → cliente (dropoff), calculada pelo OSRM
 * uma única vez e guardada no meta do pedido (`entregas.km_rota`). O campo `orders.distance`
 * não serve: é a distância *restante*, que vai a ~0 quando o pedido termina.
 *
 * O período é filtrado pela data em que o pedido foi concluído (tracking status COMPLETED),
 * no fuso da organização.
 */
class CalculoEntregas
{
    /** Chave da tabela de faixas nas configurações da organização. */
    public const CHAVE_FAIXAS = 'entregas.faixas';

    public const MAX_FAIXAS = 50;

    /** Quantas rotas novas calcular por requisição (a tela repete enquanto houver pendentes). */
    public const LIMITE_CALCULOS = 40;

    /** Linha reta → rua, usado só quando o OSRM não responde. */
    public const FATOR_ESTIMATIVA = 1.3;

    /**
     * Pedidos concluídos no período, com a data de conclusão em `entregas_concluido_em`.
     * $filtro recebe a query para restringir (por motoboy, por loja).
     */
    public function pedidosConcluidos(string $companyUuid, Carbon $inicio, Carbon $fim, ?\Closure $filtro = null): Collection
    {
        $conclusoes = DB::table('tracking_statuses')
            ->select('tracking_number_uuid', DB::raw('MIN(created_at) as concluido_em'))
            ->where('company_uuid', $companyUuid)
            ->where('code', 'COMPLETED')
            ->whereNull('deleted_at')
            ->groupBy('tracking_number_uuid');

        return Order::query()
            ->leftJoinSub($conclusoes, 'conclusoes', 'conclusoes.tracking_number_uuid', '=', 'orders.tracking_number_uuid')
            ->where('orders.company_uuid', $companyUuid)
            ->where('orders.status', 'completed')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.driver_assigned_uuid')
            ->whereBetween(DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at)'), [$inicio, $fim])
            // condição booleana: com a closure como condição, o when() a executaria só para decidir
            ->when($filtro !== null, $filtro)
            ->select('orders.*', DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at) as entregas_concluido_em'))
            ->with(['payload', 'driverAssigned'])
            ->orderBy('entregas_concluido_em')
            ->get();
    }

    /**
     * Uma linha por pedido: loja, motoboy, km, faixa e os dois valores.
     * Retorna [entregas, pendentes] (pendentes = pedidos ainda sem km).
     */
    public function entregas(Collection $pedidos, string $fuso): array
    {
        $calculos  = 0;
        $pendentes = 0;
        $entregas  = [];
        $faixas    = $this->faixas();
        $lojas     = $this->lojasDosPedidos($pedidos);

        foreach ($pedidos as $pedido) {
            $rota = $this->rotaDoPedido($pedido, $calculos < static::LIMITE_CALCULOS, $calculado);
            if ($calculado) {
                $calculos++;
            }
            if ($rota === null) {
                $pendentes++;
            }

            $motoboy           = $pedido->driverAssigned;
            $coleta            = $pedido->payload?->getPickupOrFirstWaypoint();
            $km                = $rota ? round($rota['metros'] / 1000, 2) : null;
            $faixa             = $km === null ? null : $this->faixaDoKm($faixas, $km);
            [$loja, $lojaNome] = $this->lojaDoPedido($pedido, $coleta, $lojas);

            $entregas[] = [
                'loja'          => $loja,
                'loja_nome'     => $lojaNome,
                'pedido'        => $pedido->public_id,
                'id_interno'    => $pedido->internal_id,
                'motoboy'       => $motoboy?->public_id,
                'motoboy_nome'  => $motoboy?->name,
                'concluido_em'  => Carbon::parse($pedido->entregas_concluido_em, 'UTC')->setTimezone($fuso)->toIso8601String(),
                'origem'        => $this->enderecoCurto($coleta),
                'destino'       => $this->enderecoCurto($pedido->payload?->getDropoffOrLastWaypoint()),
                'km'            => $km,
                'fonte'         => $rota['fonte'] ?? null,
                'faixa'         => $faixa,
                'valor_motoboy' => $faixa ? $faixa['motoboy'] : null,
                'valor_loja'    => $faixa ? $faixa['loja'] : null,
            ];
        }

        return [$entregas, $pendentes];
    }

    /**
     * Loja do pedido: o Vendor dono do pedido; sem loja, o nome do local de coleta; sem nome, o Place.
     *
     * @return array{0: ?string, 1: ?string} [chave, nome]
     */
    public function lojaDoPedido(Order $pedido, ?Place $coleta, Collection $lojas): array
    {
        $vendor = $pedido->customer_type === Vendor::class ? $lojas->get($pedido->customer_uuid) : null;
        if ($vendor) {
            return ['loja:' . $vendor->public_id, $vendor->name];
        }

        if (!$coleta) {
            return [null, null];
        }

        $nome = trim(mb_strtolower((string) $coleta->name));

        return [$nome !== '' ? 'nome:' . $nome : $coleta->public_id, $coleta->name ?: $this->enderecoCurto($coleta)];
    }

    /**
     * Lojas (Vendor) donas dos pedidos, numa consulta só, indexadas pelo uuid.
     * Inclui lojas excluídas, para o histórico da cobrança não mudar de agrupamento.
     */
    protected function lojasDosPedidos(Collection $pedidos): Collection
    {
        $uuids = $pedidos->where('customer_type', Vendor::class)->pluck('customer_uuid')->filter()->unique()->values();

        return $uuids->isEmpty() ? collect() : Vendor::withTrashed()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
    }
```

Em seguida, **copiar do controller sem mudar uma linha do corpo**, só trocando `protected` por `public`: `faixas()`, `normalizarFaixas()`, `faixaDoKm()`, `agrupar()`, `rotaDoPedido()`, `temCoordenadas()`, `enderecoCurto()` (com os docblocks). `chaveDaLoja()` não vem (substituído por `lojaDoPedido`). Fechar a classe.

- [ ] **Step 2: reduzir o controller** — o arquivo inteiro fica:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: tela "Pagamento e cobrança" (Fleet-Ops → Recursos), só administradores.
 * O cálculo (faixas, km da rota, loja de cada pedido) fica em App\Support\Entregas\CalculoEntregas.
 */
class PagamentoMotoboysController extends Controller
{
    public function relatorio(Request $request, CalculoEntregas $calculo)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $request->validate([
            'inicio'  => ['required', 'date_format:Y-m-d'],
            'fim'     => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'motoboy' => ['nullable', 'string'],
        ]);

        $companyUuid = session('company');
        $fuso        = Company::where('uuid', $companyUuid)->value('timezone') ?: 'America/Sao_Paulo';
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->utc();
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->utc();

        $pedidos = $calculo->pedidosConcluidos($companyUuid, $inicio, $fim, function ($query) use ($request) {
            if ($request->filled('motoboy')) {
                $query->whereHas('driverAssigned', fn ($driver) => $driver->where('public_id', $request->input('motoboy')));
            }
        });

        [$entregas, $pendentes] = $calculo->entregas($pedidos, $fuso);

        $motoboys    = $calculo->agrupar($entregas, 'motoboy', ['motoboy', 'motoboy_nome'], 'valor_motoboy')->sortByDesc('valor')->values();
        $lojas       = $calculo->agrupar($entregas, 'loja', ['loja', 'loja_nome'], 'valor_loja')->sortByDesc('valor')->values();
        $totalPagar  = round($motoboys->sum('valor'), 2);
        $totalCobrar = round($lojas->sum('valor'), 2);

        return response()->json([
            'inicio'    => $request->input('inicio'),
            'fim'       => $request->input('fim'),
            'fuso'      => $fuso,
            'faixas'    => $calculo->faixas(),
            'pendentes' => $pendentes,
            'totais'    => [
                'entregas' => count($entregas),
                'km'       => round(collect($entregas)->sum(fn ($item) => $item['km'] ?? 0), 2),
                'valor'    => $totalPagar,
                'cobrar'   => $totalCobrar,
                'margem'   => round($totalCobrar - $totalPagar, 2),
            ],
            'motoboys'  => $motoboys,
            'lojas'     => $lojas,
            'entregas'  => $entregas,
        ]);
    }

    public function salvarFaixas(Request $request, CalculoEntregas $calculo)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $request->validate([
            'faixas'           => ['present', 'array', 'max:' . CalculoEntregas::MAX_FAIXAS],
            'faixas.*.ate_km'  => ['required', 'numeric', 'gt:0', 'max:1000'],
            'faixas.*.motoboy' => ['required', 'numeric', 'min:0', 'max:100000'],
            'faixas.*.loja'    => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $faixas  = $calculo->normalizarFaixas($request->input('faixas', []));
        $limites = array_column($faixas, 'ate_km');
        if (count($limites) !== count(array_unique($limites))) {
            return response()->json(['errors' => ['Há duas faixas com o mesmo "até km".']], 422);
        }

        Setting::configureCompany(CalculoEntregas::CHAVE_FAIXAS, $faixas);

        return response()->json(['faixas' => $faixas]);
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem ver o pagamento dos motoboys e a cobrança das lojas.']], 403);
        }

        return null;
    }
}
```

- [ ] **Step 3: lint** dos dois arquivos → ok.
- [ ] **Step 4: conferir que o front não depende do formato da chave** — `grep -rn "nome:" packages/fleetops/addon` → nenhuma ocorrência.
- [ ] **Step 5: Commit** — `git add api/app && git commit -m "API: cálculo de entregas compartilhado; cobrança agrupada pela loja dona do pedido"`

**Revisões depois da implementação (aprovadas; o código no repo é a referência):**
- `->when($filtro !== null, fn ($query) => $query->where(fn ($grupo) => $filtro($grupo)))` — no Laravel 10 o `when()` executa uma closure passada como condição; e o filtro (fronteira de isolamento do extrato da loja) fica agrupado.
- Loja do pedido pelo **uuid do cliente**: `lojasDosPedidos` busca `Vendor::withTrashed()->without('place')->whereIn('uuid', <customer_uuid de todos os pedidos>)`; `lojaDoPedido` usa `$lojas->get($pedido->customer_uuid)` (o `customer_type` varia com/sem barra inicial).
- `rotaDoPedido`: endereço apagado ou sem coordenadas depois do cálculo → vale o km em cache.
- Disjuntor do OSRM por requisição (depois da primeira falha, vai direto para a estimativa) e `entregas(..., int $limiteCalculos = self::LIMITE_CALCULOS)`.
- Eager load das relações usadas por pedido (coleta, destino, paradas, usuário do motoboy) para não ter N+1.

---

### Task 4: API admin de lojas (`LojasController`)

**Files:** Create `api/app/Http/Controllers/Entregas/LojasController.php`; Modify `api/app/Providers/RouteServiceProvider.php`

Contrato (todas só admin, prefixo `int/v1/entregas/lojas`):

| Método | Rota | Corpo | Resposta |
|---|---|---|---|
| GET | `/` | — | `{lojas:[Loja]}` |
| POST | `/` | `{nome, telefone?, endereco:{street1, street2?, neighborhood?, city, province?, postal_code?, country?, latitude, longitude}}` | `{loja:Loja}` |
| PUT | `/{id}` | mesmo do POST | `{loja:Loja}` |
| POST | `/{id}/usuarios` | `{nome, email, telefone?, senha}` | `{loja:Loja}` |
| PUT | `/{id}/usuarios/{contato}/senha` | `{senha}` | `{ok:true}` (derruba as sessões abertas) |
| PUT | `/{id}/usuarios/{contato}/ativo` | `{ativo: bool}` | `{loja:Loja}` (desativar derruba as sessões) |

`Loja = {id, nome, telefone, endereco:{street1, street2, neighborhood, city, province, postal_code, country, latitude, longitude} | null, usuarios:[{id, nome, email, telefone, ativo}]}`.

- [ ] **Step 1: criar o controller**

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: cadastro das lojas (restaurantes) atendidas, só para administradores.
 *
 * Loja = Vendor type=customer (o mesmo tipo que o Portal do Cliente usa) com um Place próprio
 * (dono = o Vendor), que é o local fixo de coleta.
 * Usuário da loja = Contact type=customer + VendorPersonnel. O login (User type=customer) nasce no
 * ContactObserver com senha aleatória e status pending; aqui recebe a senha inicial, é marcado como
 * verificado (o portal recusa login não verificado e não há e-mail configurado) e é ativado.
 * O Fleetbase não barra usuário inativo; quem barra é o ProtegerPortalLoja. Por isso desativar e
 * trocar a senha também apagam os tokens (sessões abertas).
 */
class LojasController extends Controller
{
    /** Tipo de Vendor das lojas. */
    public const TIPO_LOJA = 'customer';

    public function index(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $lojas = Vendor::where('company_uuid', session('company'))->where('type', static::TIPO_LOJA)->orderBy('name')->get();

        return response()->json(['lojas' => $lojas->map(fn ($vendor) => $this->formatar($vendor))->values()]);
    }

    public function store(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $dados = $this->validarLoja($request);

        $vendor = DB::transaction(function () use ($dados) {
            $vendor = Vendor::create([
                'company_uuid' => session('company'),
                'name'         => $dados['nome'],
                'phone'        => $dados['telefone'] ?? null,
                'type'         => static::TIPO_LOJA,
                'status'       => 'active',
            ]);
            $place = $this->salvarEndereco($vendor, null, $dados);
            $vendor->update(['place_uuid' => $place->uuid]);

            return $vendor;
        });

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    public function update(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        $dados  = $this->validarLoja($request);

        DB::transaction(function () use ($vendor, $dados) {
            $vendor->update(['name' => $dados['nome'], 'phone' => $dados['telefone'] ?? null]);
            $atual = $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
            $place = $this->salvarEndereco($vendor, $atual, $dados);
            if ($vendor->place_uuid !== $place->uuid) {
                $vendor->update(['place_uuid' => $place->uuid]);
            }
        });

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    public function adicionarUsuario(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        $dados  = $request->validate([
            'nome'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:190'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'senha'    => ['required', 'string', 'min:8', 'max:100'],
        ]);

        try {
            DB::transaction(function () use ($vendor, $dados) {
                $contato = Contact::create([
                    'company_uuid' => session('company'),
                    'name'         => $dados['nome'],
                    'email'        => mb_strtolower($dados['email']),
                    'phone'        => $dados['telefone'] ?? null,
                    'type'         => 'customer',
                ]);
                $usuario = $contato->refresh()->user;
                abort_if(!$usuario, 422, 'Não foi possível criar o login do usuário.');

                // a central é quem cadastra: o e-mail vale como verificado (o portal recusa login não verificado)
                $usuario->email_verified_at = now();
                $usuario->changePassword($dados['senha']);
                $usuario->activate();

                VendorPersonnel::updateOrCreate(
                    ['vendor_uuid' => $vendor->uuid, 'contact_uuid' => $contato->uuid],
                    ['role' => 'member', 'status' => 'active', 'invited_by_uuid' => session('user')]
                );
            });
        } catch (UserAlreadyExistsException $e) {
            return response()->json(['errors' => ['Já existe um usuário com este e-mail ou telefone.']], 422);
        } catch (CustomerUserConflictException $e) {
            return response()->json(['errors' => ['Este e-mail ou telefone pertence a um usuário da central.']], 422);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'not available')) {
                return response()->json(['errors' => ['Este e-mail ou telefone já está em uso.']], 422);
            }
            throw $e;
        }

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    public function trocarSenha(Request $request, string $id, string $contato)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor  = $this->acharLoja($id);
        $dados   = $request->validate(['senha' => ['required', 'string', 'min:8', 'max:100']]);
        $usuario = $this->acharMembro($vendor, $contato)->contact?->user;
        abort_if(!$usuario, 404, 'Usuário sem login.');

        $usuario->changePassword($dados['senha']);
        // derruba as sessões abertas com a senha antiga
        $usuario->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    public function alterarAcesso(Request $request, string $id, string $contato)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor  = $this->acharLoja($id);
        $ativo   = (bool) $request->validate(['ativo' => ['required', 'boolean']])['ativo'];
        $membro  = $this->acharMembro($vendor, $contato);
        $usuario = $membro->contact?->user;

        $membro->update(['status' => $ativo ? 'active' : 'inactive']);
        if ($usuario && $ativo) {
            $usuario->activate();
        } elseif ($usuario) {
            $usuario->deactivate();
            $usuario->tokens()->delete();
        }

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    protected function validarLoja(Request $request): array
    {
        return $request->validate([
            'nome'                  => ['required', 'string', 'max:120'],
            'telefone'              => ['nullable', 'string', 'max:30'],
            'endereco.street1'      => ['required', 'string', 'max:190'],
            'endereco.street2'      => ['nullable', 'string', 'max:190'],
            'endereco.neighborhood' => ['nullable', 'string', 'max:120'],
            'endereco.city'         => ['required', 'string', 'max:120'],
            'endereco.province'     => ['nullable', 'string', 'max:60'],
            'endereco.postal_code'  => ['nullable', 'string', 'max:20'],
            'endereco.country'      => ['nullable', 'string', 'size:2'],
            'endereco.latitude'     => ['required', 'numeric', 'between:-90,90', 'not_in:0'],
            'endereco.longitude'    => ['required', 'numeric', 'between:-180,180', 'not_in:0'],
        ]);
    }

    /** O Place da loja: dono = o Vendor (assim o portal o reconhece como da loja), nome = nome da loja. */
    protected function salvarEndereco(Vendor $vendor, ?Place $place, array $dados): Place
    {
        $endereco = $dados['endereco'];
        $place    = $place ?: new Place(['company_uuid' => session('company')]);
        $place->fill([
            'owner_uuid'   => $vendor->uuid,
            'owner_type'   => Utils::getMutationType($vendor),
            'name'         => $dados['nome'],
            'phone'        => $dados['telefone'] ?? null,
            'street1'      => $endereco['street1'],
            'street2'      => $endereco['street2'] ?? null,
            'neighborhood' => $endereco['neighborhood'] ?? null,
            'city'         => $endereco['city'],
            'province'     => $endereco['province'] ?? null,
            'postal_code'  => $endereco['postal_code'] ?? null,
            'country'      => $endereco['country'] ?? 'BR',
            'location'     => new Point((float) $endereco['latitude'], (float) $endereco['longitude']),
        ]);
        $place->save();

        return $place;
    }

    protected function acharLoja(string $id): Vendor
    {
        return Vendor::where('company_uuid', session('company'))
            ->where('type', static::TIPO_LOJA)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();
    }

    protected function acharMembro(Vendor $vendor, string $contato): VendorPersonnel
    {
        return VendorPersonnel::where('vendor_uuid', $vendor->uuid)
            ->whereHas('contact', fn ($q) => $q->where(fn ($q2) => $q2->where('uuid', $contato)->orWhere('public_id', $contato)))
            ->with('contact.user')
            ->firstOrFail();
    }

    protected function formatar(Vendor $vendor): array
    {
        $place   = $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
        $membros = VendorPersonnel::where('vendor_uuid', $vendor->uuid)->with('contact.user')->get();

        return [
            'id'       => $vendor->public_id,
            'nome'     => $vendor->name,
            'telefone' => $vendor->phone,
            'endereco' => $place ? [
                'street1'      => $place->street1,
                'street2'      => $place->street2,
                'neighborhood' => $place->neighborhood,
                'city'         => $place->city,
                'province'     => $place->province,
                'postal_code'  => $place->postal_code,
                'country'      => $place->country,
                'latitude'     => $place->location?->getLat(),
                'longitude'    => $place->location?->getLng(),
            ] : null,
            'usuarios' => $membros->filter(fn ($m) => $m->contact)->map(fn ($m) => [
                'id'       => $m->contact->public_id,
                'nome'     => $m->contact->name,
                'email'    => $m->contact->email,
                'telefone' => $m->contact->phone,
                'ativo'    => $m->status === 'active' && $m->contact->user?->status === 'active',
            ])->values(),
        ];
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem gerenciar as lojas.']], 403);
        }

        return null;
    }
}
```

Conferir nos fontes locais antes de finalizar (mesmas versões da produção): `Place` aceita `owner_uuid`, `owner_type`, `location` no fill (`packages/fleetops/server/src/Models/Place.php`); `Point` é `Fleetbase\LaravelMysqlSpatial\Types\Point` com construtor `(lat, lng)`; `Contact` tem relação `user()` e aceita `type`/`company_uuid` no fill; `User` usa `HasApiTokens` (`tokens()`).

- [ ] **Step 2: rotas** — no `RouteServiceProvider`, dentro do grupo `int/v1/entregas` existente (depois das de pagamento), e `use App\Http\Controllers\Entregas\LojasController;` no topo:

```php
                        // cadastro das lojas e dos usuários do portal (Fleet-Ops → Recursos → Lojas)
                        Route::get('lojas', [LojasController::class, 'index']);
                        Route::post('lojas', [LojasController::class, 'store']);
                        Route::put('lojas/{id}', [LojasController::class, 'update']);
                        Route::post('lojas/{id}/usuarios', [LojasController::class, 'adicionarUsuario']);
                        Route::put('lojas/{id}/usuarios/{contato}/senha', [LojasController::class, 'trocarSenha']);
                        Route::put('lojas/{id}/usuarios/{contato}/ativo', [LojasController::class, 'alterarAcesso']);
```

- [ ] **Step 3: lint** dos dois arquivos → ok.
- [ ] **Step 4: Commit** — `git add api/app && git commit -m "API: cadastro de lojas e usuários das lojas (admin)"`

**Revisões depois da implementação (aprovadas; o código no repo é a referência):** o login do contato é lido por `Contact::anyUser` (nunca `user`, ver "Armadilhas"); `catch (CustomerUserConflictException)` antes de `catch (UserAlreadyExistsException)`; `adicionarUsuario` recusa (422) e-mail ou telefone já usado por outro contato de cliente da empresa, antes de criar; ao editar a loja, **se as coordenadas mudarem, nasce um Local novo** (o antigo perde o dono e fica só com os pedidos antigos) — o km e a cobrança do histórico não mudam quando a loja se muda.

---

### Task 5: API do portal da loja (`PortalLojaController`)

**Files:** Create `api/app/Http/Controllers/Entregas/PortalLojaController.php`; Modify `api/app/Providers/RouteServiceProvider.php`

- [ ] **Step 1: criar o controller**

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\LojaDoUsuario;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: dados do portal da loja (usuário type=customer).
 * Tudo filtrado pela loja do usuário da sessão; a loja nunca vem por parâmetro.
 */
class PortalLojaController extends Controller
{
    public const STATUS_ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled'];

    /** Rotas novas calculadas por consulta do extrato (o portal não repete a chamada como a tela da central). */
    public const LIMITE_CALCULOS_PORTAL = 10;

    public function minhaLoja(CalculoEntregas $calculo)
    {
        $vendor = $this->lojaDaSessao();
        $coleta = LojaDoUsuario::coleta($vendor);

        return response()->json([
            'loja' => [
                'id'       => $vendor->public_id,
                'nome'     => $vendor->name,
                'endereco' => $coleta ? $calculo->enderecoCurto($coleta) : null,
                'cidade'   => $coleta?->city,
                // o portal mostra a coleta e desenha a rota com isto; quem grava a coleta no pedido é o servidor
                'coleta'   => $coleta ? [
                    'id'           => $coleta->public_id,
                    'uuid'         => $coleta->uuid,
                    'public_id'    => $coleta->public_id,
                    'name'         => $vendor->name,
                    'street1'      => $coleta->street1,
                    'street2'      => $coleta->street2,
                    'neighborhood' => $coleta->neighborhood,
                    'city'         => $coleta->city,
                    'latitude'     => $coleta->location?->getLat(),
                    'longitude'    => $coleta->location?->getLng(),
                ] : null,
            ],
        ]);
    }

    public function extrato(Request $request, CalculoEntregas $calculo)
    {
        $vendor = $this->lojaDaSessao();

        $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim'    => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
        ]);

        $companyUuid = session('company');
        $fuso        = Company::where('uuid', $companyUuid)->value('timezone') ?: 'America/Sao_Paulo';
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->utc();
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->utc();

        $pedidos = $calculo->pedidosConcluidos($companyUuid, $inicio, $fim, function ($query) use ($vendor) {
            $query->where('orders.customer_uuid', $vendor->uuid);
        });
        [$entregas, $pendentes] = $calculo->entregas($pedidos, $fuso, static::LIMITE_CALCULOS_PORTAL);

        // a loja não vê o valor pago ao motoboy nem quem levou
        $entregas = array_map(fn ($e) => [
            'pedido'       => $e['pedido'],
            'id_interno'   => $e['id_interno'],
            'concluido_em' => $e['concluido_em'],
            'destino'      => $e['destino'],
            'km'           => $e['km'],
            'faixa'        => $e['faixa'] ? ['de_km' => $e['faixa']['de_km'], 'ate_km' => $e['faixa']['ate_km'], 'acima' => $e['faixa']['acima'] ?? false] : null,
            'valor'        => $e['valor_loja'],
        ], $entregas);

        return response()->json([
            'inicio'    => $request->input('inicio'),
            'fim'       => $request->input('fim'),
            'pendentes' => $pendentes,
            'totais'    => [
                'entregas' => count($entregas),
                'km'       => round(collect($entregas)->sum(fn ($e) => $e['km'] ?? 0), 2),
                'valor'    => round(collect($entregas)->sum(fn ($e) => $e['valor'] ?? 0), 2),
            ],
            'entregas'  => $entregas,
        ]);
    }

    /** Motoboy do pedido em andamento (nome, foto e posição). Só pedido desta loja. */
    public function motoboy(string $id)
    {
        $vendor = $this->lojaDaSessao();

        $pedido = Order::where('company_uuid', session('company'))
            ->where('customer_uuid', $vendor->uuid)
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->with('driverAssigned')
            ->firstOrFail();

        $motoboy = $pedido->driverAssigned;
        if (!$motoboy || in_array($pedido->status, static::STATUS_ENCERRADOS, true)) {
            return response()->json(['motoboy' => null]);
        }

        return response()->json([
            'motoboy' => [
                'nome'          => $motoboy->name,
                'foto'          => $motoboy->photo_url,
                'aceitou'       => (bool) $pedido->started,
                'latitude'      => $motoboy->location?->getLat(),
                'longitude'     => $motoboy->location?->getLng(),
                'atualizado_em' => optional($motoboy->updated_at)->toIso8601String(),
            ],
        ]);
    }

    protected function lojaDaSessao(): Vendor
    {
        $vendor = LojaDoUsuario::vendor(session('user'));
        abort_if(!$vendor, 404, 'Usuário sem loja.');

        return $vendor;
    }
}
```

- [ ] **Step 2: rotas** — no mesmo grupo `int/v1/entregas`, e `use App\Http\Controllers\Entregas\PortalLojaController;`:

```php
                        // portal da loja (usuário type=customer; o ProtegerPortalLoja só libera estas)
                        Route::get('loja/minha-loja', [PortalLojaController::class, 'minhaLoja']);
                        Route::get('loja/extrato', [PortalLojaController::class, 'extrato']);
                        Route::get('loja/pedidos/{id}/motoboy', [PortalLojaController::class, 'motoboy']);
```

- [ ] **Step 3: lint** → ok.
- [ ] **Step 4: Commit** — `git add api/app && git commit -m "API: loja, extrato e motoboy do pedido para o portal da loja"`

**Revisões depois da implementação (aprovadas; o código no repo é a referência):** `with('driverAssigned.user')`; motoboy com posição (0,0) — o Fleetbase grava isso em motorista sem GPS — volta com `latitude`/`longitude` nulos; `STATUS_ENCERRADOS` inclui `expired`. Sem loja, os três endpoints dão 404 (o handler do core troca a mensagem por "There is nothing to see here."; o front reage ao status).

---

### Task 6A: Controle de acesso do usuário de loja (`ProtegerPortalLoja`)

**Files:** Create `api/app/Http/Middleware/ProtegerPortalLoja.php`; Modify `api/app/Http/Kernel.php`, `api/app/Support/Entregas/LojaDoUsuario.php`

Regras (só para usuário `type=customer` identificado pelo token; os demais passam direto):
1. Login (`POST customer-portal/int/v1/auth/login` e `POST int/v1/auth/login`) de usuário `customer` com `status != active` → 401 "Acesso desativado. Fale com a central."
2. Usuário de loja com `status != active` → 403 em tudo.
2b. Usuário ligado a **mais de uma loja** (vínculos ativos) → 403 nas rotas `customer-portal/int/v1/*` e `int/v1/entregas/loja/*` (o portal juntaria os pedidos de todas as contas do usuário). A tela Lojas já impede o cadastro repetido; isto é a segunda barreira.
3. `customer-portal/int/v1/*` → liberado, menos `NEGADAS_NO_PORTAL`.
4. `int/v1/*` → só `PERMITIDAS_INTERNAS`, `PUT|PATCH users/{ele mesmo}` (corpo reduzido a nome/contato/foto) e `POST files/upload` da foto do próprio perfil.
5. Qualquer outro caminho (inclusive `v1/*`, a API pública) → 403.

- [ ] **Step 1: criar o middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Support\Entregas\LojaDoUsuario;
use Closure;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Entregas RestaurantePro: o que um usuário de loja (User type=customer) pode chamar na API.
 *
 * Middleware global (roda antes das rotas, inclusive as que vêm do Composer), por isso identifica
 * o usuário direto pelo token Sanctum. Nega por padrão:
 * - customer-portal/int/v1/*: liberado, menos NEGADAS_NO_PORTAL (o portal filtra os dados pela
 *   conta da loja, mas tem rotas sem checagem de admin e telas fora do escopo);
 * - int/v1/*: só PERMITIDAS_INTERNAS, o próprio perfil (campos limitados) e a foto do perfil;
 * - todo o resto, inclusive a API pública v1/*, que aceita o mesmo token e devolveria os dados
 *   de todas as lojas.
 * Não confia nas permissões do Fleetbase: o papel "Fleet-Ops Customer" lista e apaga contatos de
 * todas as lojas e edita usuários (inclusive o papel).
 * Usuário de loja desativado não passa em nada, nem no login (o Fleetbase não confere o status).
 */
class ProtegerPortalLoja
{
    /** Onde o usuário de loja fica guardado no request (o RegrasPortalLoja lê daqui). */
    public const ATRIBUTO_USUARIO = 'entregas.usuario_loja';

    public const ROTAS_DE_LOGIN = ['customer-portal/int/v1/auth/login', 'int/v1/auth/login'];

    /** int/v1: "MÉTODO caminho" liberados para o usuário de loja. */
    public const PERMITIDAS_INTERNAS = [
        '#^GET auth/(session|organizations|validate-verification)$#',
        '#^POST auth/(login|logout|get-magic-reset-link|reset-password|confirm-email-change|create-verification-session|validate-verification-session|send-verification-email|verify-email)$#',
        '#^(GET|POST) two-fa/(check|validate|verify|resend|invalidate)$#',
        '#^GET lookup/[a-z-]+(/[A-Za-z]{2})?$#',
        '#^GET settings/branding$#',
        '#^GET users/me$#',
        '#^(GET|POST) users/locale$#',
        '#^GET geocoder/(reverse|query)$#',
        '#^[A-Z]+ entregas/loja/.+$#',
    ];

    /** customer-portal/int/v1: rotas do portal negadas ao usuário de loja. */
    public const NEGADAS_NO_PORTAL = [
        '#^[A-Z]+ settings(/.*)?$#',                          // configuração do portal: o portal não confere se é admin
        '#^[A-Z]+ service-quotes(/.*)?$#',                    // cotação: sobrescreve endereço salvo (até o da loja); sem tarifas no Entregas
        '#^[A-Z]+ (account|contacts/[^/]+)/convert-to-vendor$#',
        '#^GET account/personnel-candidates$#',              // lista os usuários de todas as lojas
        '#^(POST|DELETE) account/personnels(/[^/]+)?$#',     // usuários da loja: só a central cria (tela Lojas)
        '#^POST orders/[^/]+/reschedule$#',
        '#^[A-Z]+ orders/[^/]+/files(/[^/]+)?$#',
        '#^[A-Z]+ (issues|invoices)(/.*)?$#',                // suporte e faturas: fora do escopo
        '#^GET (documents|address-book|pending-actions)$#',
        '#^[A-Z]+ notification-preferences$#',
    ];

    /** Campos que o usuário de loja pode mudar no próprio perfil (sem papel, permissões, status, empresa). */
    public const CAMPOS_DO_PERFIL = ['name', 'email', 'phone', 'avatar_uuid', 'avatar_url', 'timezone'];

    public function handle(Request $request, Closure $next)
    {
        $caminho = trim($request->path(), '/');

        if ($request->isMethod('POST') && in_array($caminho, static::ROTAS_DE_LOGIN, true) && $this->loginDesativado($request)) {
            return response()->json(['errors' => ['Acesso desativado. Fale com a central.']], 401);
        }

        $usuario = $this->usuario($request);
        if (!$usuario || $usuario->type !== 'customer') {
            return $next($request);
        }

        if ($usuario->status !== 'active') {
            return $this->negar($caminho, 'login desativado');
        }

        $request->attributes->set(static::ATRIBUTO_USUARIO, $usuario);

        // o portal juntaria os pedidos de todas as lojas do usuário: um login, uma loja
        if ((str_starts_with($caminho, 'customer-portal/int/v1/') || str_starts_with($caminho, 'int/v1/entregas/loja/'))
            && LojaDoUsuario::totalDeLojas($usuario->uuid) > 1) {
            return $this->negar($caminho, 'usuário ligado a mais de uma loja');
        }

        if (str_starts_with($caminho, 'customer-portal/int/v1/')) {
            $rota = $request->method() . ' ' . substr($caminho, strlen('customer-portal/int/v1/'));

            return $this->casa($rota, static::NEGADAS_NO_PORTAL) ? $this->negar($caminho) : $next($request);
        }

        if (str_starts_with($caminho, 'int/v1/')) {
            $rota = $request->method() . ' ' . substr($caminho, strlen('int/v1/'));

            if ($this->casa($rota, static::PERMITIDAS_INTERNAS)) {
                return $next($request);
            }

            if ($this->ehOProprioPerfil($rota, $usuario)) {
                $this->limitarCamposDoPerfil($request);

                return $next($request);
            }

            if ($rota === 'POST files/upload' && $this->ehFotoDoProprioPerfil($request, $usuario)) {
                return $next($request);
            }
        }

        return $this->negar($caminho);
    }

    protected function usuario(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $usuario = PersonalAccessToken::findToken($token)?->tokenable;

        return $usuario instanceof User ? $usuario : null;
    }

    protected function loginDesativado(Request $request): bool
    {
        $identidade = trim((string) $request->input('identity'));
        if ($identidade === '') {
            return false;
        }

        $usuario = User::where(fn ($q) => $q->where('email', $identidade)->orWhere('phone', $identidade))->first();

        return $usuario && $usuario->type === 'customer' && $usuario->status !== 'active';
    }

    protected function idsDoUsuario(User $usuario): array
    {
        return array_values(array_filter([$usuario->uuid, $usuario->public_id, (string) $usuario->id]));
    }

    protected function ehOProprioPerfil(string $rota, User $usuario): bool
    {
        return preg_match('#^(PUT|PATCH) users/([^/]+)$#', $rota, $m) === 1 && in_array($m[2], $this->idsDoUsuario($usuario), true);
    }

    /** Só nome, contato e foto: sem papel, permissões, políticas, status ou empresa. */
    protected function limitarCamposDoPerfil(Request $request): void
    {
        $entrada = $request->isJson() ? $request->json() : $request->request;
        $dados   = $entrada->all();

        $entrada->replace(is_array($dados['user'] ?? null)
            ? ['user' => Arr::only($dados['user'], static::CAMPOS_DO_PERFIL)]
            : Arr::only($dados, static::CAMPOS_DO_PERFIL));
    }

    protected function ehFotoDoProprioPerfil(Request $request, User $usuario): bool
    {
        return $request->input('type') === 'user_avatar' && in_array((string) $request->input('subject_uuid'), $this->idsDoUsuario($usuario), true);
    }

    protected function casa(string $rota, array $padroes): bool
    {
        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $rota) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function negar(string $caminho, string $motivo = 'rota fora do portal da loja')
    {
        Log::info('[entregas] portal da loja: acesso negado', ['caminho' => $caminho, 'motivo' => $motivo]);

        return response()->json(['errors' => ['Acesso não permitido para usuários de loja.']], 403);
    }
}
```

Observações para o implementador:
- `TrimStrings`/`ConvertEmptyStringsToNull` rodam antes; `$request->json()` é o mesmo bag que o `$request->input()` dos controllers lê em JSON, então o corpo reduzido é o que o core vê.
- Requisições sem Bearer (login, preflight `OPTIONS`, arquivos) passam direto; token de API (`flb_live_…`) não acha `PersonalAccessToken` e passa direto.
- Se no teste do navegador (Task 14) alguma chamada necessária do portal voltar 403 deste middleware (log `[entregas] portal da loja: acesso negado`), liberar **só aquele caminho e método** em `PERMITIDAS_INTERNAS`.

- [ ] **Step 1b: `LojaDoUsuario::totalDeLojas`** — em `api/app/Support/Entregas/LojaDoUsuario.php`, a consulta de `vendor()` vira um método protegido reaproveitado:

```php
    public static function vendor(?string $userUuid): ?Vendor
    {
        return $userUuid ? static::lojas($userUuid)->first() : null;
    }

    /** Quantas lojas o usuário tem (o portal da loja só aceita uma: com mais, juntaria os pedidos delas). */
    public static function totalDeLojas(?string $userUuid): int
    {
        return $userUuid ? static::lojas($userUuid)->count() : 0;
    }

    /** Local de coleta da loja (o Place do Vendor); usa o `place` já carregado pelo `Vendor::$with`. */
    public static function coleta(?Vendor $vendor): ?Place
    {
        if (!$vendor || !$vendor->place_uuid) {
            return null;
        }

        return $vendor->relationLoaded('place') ? $vendor->place : Place::where('uuid', $vendor->place_uuid)->first();
    }

    /** Lojas com vínculo ativo do contato de cliente do usuário (mesma regra do PortalAccountResolver). */
    protected static function lojas(string $userUuid)
    {
        return Vendor::whereHas('vendorPersonnel', function ($query) use ($userUuid) {
            $query->where('status', 'active')->whereHas('contact', function ($contato) use ($userUuid) {
                $contato->where('user_uuid', $userUuid)->where('type', 'customer');
            });
        });
    }
```

- [ ] **Step 2: registrar como global** em `api/app/Http/Kernel.php`, no fim de `$middleware`:

```php
        // Entregas RestaurantePro: portal da loja (antes das rotas do Composer)
        \App\Http\Middleware\ProtegerPortalLoja::class,
```

- [ ] **Step 3: lint** → ok.
- [ ] **Step 4: Commit** — `git add api/app && git commit -m "API: controle de acesso do usuário de loja (nega por padrão, inclusive a API pública)"`

---

### Task 6B: Regras do portal da loja (`RegrasPortalLoja`)

**Files:** Create `api/app/Http/Middleware/RegrasPortalLoja.php`; Modify `api/app/Http/Kernel.php`

Regras (rotas `customer-portal/int/v1/*` do usuário de loja que o `ProtegerPortalLoja` guardou em `$request->attributes`):
1. `POST orders`: sem loja → 403; loja sem coleta válida (Place da loja, dono = o Vendor, com coordenadas) → 422; destino tem de ser um endereço salvo **da loja** com coordenadas e diferente da coleta → senão 422; descarta `payload`, `pickup`, `return`, `waypoints`, `files`, `meta`, `scheduled_at`; grava `pickup` = uuid da coleta e `dropoff` = uuid do destino. Depois que o portal cria o pedido (2xx): marca `adhoc=true` e despacha (`firstDispatchWithActivity`) → os motoboys próximos são avisados, como no fluxo da central. Falha no despacho só vai para o log (o pedido existe; a central despacha à mão).
2. `POST orders/{id}/cancel`: pedido da loja (empresa + `customer_uuid` = loja) encerrado → 422 "Este pedido já foi encerrado."; com `started` ou status fora de `created`/`dispatched` → 422 "O motoboy já aceitou este pedido. Para cancelar, fale com a central."; pedido de outra loja → segue (o portal responde 404).
3. `POST places`: copia `location.coordinates` (`[lng, lat]`) para `latitude`/`longitude`; sem coordenadas válidas → 422 "Marque no mapa o local do endereço (botão "Selecionar no mapa")."; já existe endereço da loja com a mesma chave do `firstOrNew` do portal (nome = `strtoupper(name ?: street1)`, rua = `strtoupper(street1 ?: address)`) → 422 "Já existe um endereço salvo com este nome e esta rua. Escolha-o na lista ou use outro nome." (senão o portal sobrescreveria o endereço salvo — inclusive o Local da loja — e mudaria o km de pedidos já feitos).
4. `PATCH|DELETE places/{id}`: **sempre 403** para usuário de loja — endereços salvos são usados por pedidos (inclusive antigos) e o km da cobrança sai deles; a loja cadastra um endereço novo em vez de editar.

- [ ] **Step 1: criar o middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Support\Entregas\LojaDoUsuario;
use Closure;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Entregas RestaurantePro: regras de negócio das rotas do portal da loja (customer-portal/int/v1/*)
 * para o usuário de loja que o ProtegerPortalLoja identificou (o PHP do portal vem do Composer).
 *
 * - Novo pedido: a coleta é sempre o Local da loja (o que vier na requisição é descartado); o destino
 *   tem de ser um endereço salvo da loja com coordenadas (o km da cobrança sai delas); criado o
 *   pedido, ele é despachado como pedido aberto (adhoc) aos motoboys próximos, como faz a central.
 * - Cancelamento: só antes de um motoboy aceitar.
 * - Endereços: gravados com as coordenadas marcadas no mapa (o servidor não geocodifica). Endereço
 *   salvo não muda pelo portal (nem por edição, nem sobrescrito por um cadastro com o mesmo nome e
 *   rua): pedidos já feitos usam esses endereços e o km da cobrança sai deles.
 */
class RegrasPortalLoja
{
    public const PREFIXO = 'customer-portal/int/v1/';

    /** O que a loja não define no pedido: coleta, paradas extras, arquivos, meta (cache do km) e agendamento. */
    public const CAMPOS_DESCARTADOS = ['payload', 'pickup', 'return', 'waypoints', 'files', 'meta', 'scheduled_at'];

    /** Status em que a loja ainda pode cancelar, desde que nenhum motoboy tenha aceitado. */
    public const STATUS_CANCELAVEIS = ['created', 'dispatched'];

    public const STATUS_ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'expired'];

    public function handle(Request $request, Closure $next)
    {
        $usuario = $request->attributes->get(ProtegerPortalLoja::ATRIBUTO_USUARIO);
        $caminho = trim($request->path(), '/');

        if (!$usuario instanceof User || !str_starts_with($caminho, static::PREFIXO)) {
            return $next($request);
        }

        $rota = $request->method() . ' ' . substr($caminho, strlen(static::PREFIXO));

        if ($rota === 'POST orders') {
            return $this->novoPedido($request, $next, $usuario);
        }

        if (preg_match('#^POST orders/([^/]+)/cancel$#', $rota, $m)) {
            return $this->cancelamento($request, $next, $usuario, $m[1]);
        }

        if ($rota === 'POST places') {
            return $this->novoEndereco($request, $next, $usuario);
        }

        if (preg_match('#^(PATCH|DELETE) places/[^/]+$#', $rota)) {
            return $this->erro(403, 'Endereços salvos não podem ser alterados pelo portal. Cadastre um endereço novo.');
        }

        return $next($request);
    }

    protected function novoPedido(Request $request, Closure $next, User $usuario)
    {
        $vendor = LojaDoUsuario::vendor($usuario->uuid);
        if (!$vendor) {
            return $this->erro(403, 'Este usuário não está ligado a nenhuma loja. Fale com a central.');
        }

        $coleta = LojaDoUsuario::coleta($vendor);
        if (!$coleta || !$this->ehDaLoja($coleta, $vendor) || !$this->temCoordenadas($coleta)) {
            Log::warning('[entregas] portal da loja: loja sem local de coleta válido', ['loja' => $vendor->public_id]);

            return $this->erro(422, 'A loja ainda não tem endereço de coleta cadastrado. Fale com a central.');
        }

        $entrada = $this->entrada($request);
        $destino = $this->lugarDaLoja($this->identificador($entrada->get('dropoff')), $vendor, $usuario->company_uuid);
        if (!$destino || !$this->temCoordenadas($destino)) {
            return $this->erro(422, 'Escolha um endereço de entrega salvo e marcado no mapa.');
        }
        if ($destino->uuid === $coleta->uuid) {
            return $this->erro(422, 'O endereço de entrega não pode ser o da própria loja.');
        }

        foreach (static::CAMPOS_DESCARTADOS as $campo) {
            $entrada->remove($campo);
        }
        $entrada->set('pickup', $coleta->uuid);
        $entrada->set('dropoff', $destino->uuid);

        $resposta = $next($request);

        if ($resposta->isSuccessful()) {
            $this->despacharParaMotoboys((string) $resposta->getContent(), $vendor, $usuario);
        }

        return $resposta;
    }

    /** Pedido aberto (adhoc) para os motoboys próximos da loja, como a central faz. */
    protected function despacharParaMotoboys(string $conteudo, Vendor $vendor, User $usuario): void
    {
        $dados = json_decode($conteudo, true);
        $ids   = array_values(array_filter(
            [data_get($dados, 'order.uuid'), data_get($dados, 'order.public_id'), data_get($dados, 'order.id')],
            fn ($id) => is_string($id) && $id !== ''
        ));

        $pedido = $ids ? Order::where('company_uuid', $usuario->company_uuid)
            ->where('customer_uuid', $vendor->uuid)
            ->where(fn ($q) => $q->whereIn('uuid', $ids)->orWhereIn('public_id', $ids))
            ->first() : null;

        if (!$pedido) {
            Log::warning('[entregas] portal da loja: pedido criado não encontrado para despachar', ['loja' => $vendor->public_id]);

            return;
        }

        try {
            session(['company' => $pedido->company_uuid, 'user' => $usuario->uuid]);
            $pedido->adhoc = true;
            $pedido->save();
            $pedido->firstDispatchWithActivity();
        } catch (\Throwable $e) {
            Log::error('[entregas] portal da loja: falha ao despachar o pedido aos motoboys', ['pedido' => $pedido->public_id, 'erro' => $e->getMessage()]);
        }
    }

    protected function cancelamento(Request $request, Closure $next, User $usuario, string $id)
    {
        $vendor = LojaDoUsuario::vendor($usuario->uuid);
        $pedido = $vendor ? Order::where('company_uuid', $usuario->company_uuid)
            ->where('customer_uuid', $vendor->uuid)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->first() : null;

        // pedido de outra loja (ou inexistente): o próprio portal responde 404
        if (!$pedido) {
            return $next($request);
        }

        if (in_array($pedido->status, static::STATUS_ENCERRADOS, true)) {
            return $this->erro(422, 'Este pedido já foi encerrado.');
        }

        if ($pedido->started || !in_array($pedido->status, static::STATUS_CANCELAVEIS, true)) {
            return $this->erro(422, 'O motoboy já aceitou este pedido. Para cancelar, fale com a central.');
        }

        return $next($request);
    }

    /** Endereço novo: com as coordenadas marcadas no mapa e sem sobrescrever um endereço salvo. */
    protected function novoEndereco(Request $request, Closure $next, User $usuario)
    {
        $entrada = $this->entrada($request);
        if (!$this->copiarCoordenadas($entrada)) {
            return $this->erro(422, 'Marque no mapa o local do endereço (botão "Selecionar no mapa").');
        }

        // o portal grava com firstOrNew(nome, rua) e sobrescreveria o endereço salvo (até o da loja)
        $vendor = LojaDoUsuario::vendor($usuario->uuid);
        $rua    = (string) ($entrada->get('street1') ?: $entrada->get('address'));
        $nome   = (string) ($entrada->get('name') ?: $rua);
        if ($vendor && $rua !== '' && Place::where('company_uuid', $usuario->company_uuid)
            ->where('owner_uuid', $vendor->uuid)
            ->where('owner_type', Utils::getMutationType($vendor))
            ->where('name', strtoupper($nome))
            ->where('street1', strtoupper($rua))
            ->exists()) {
            return $this->erro(422, 'Já existe um endereço salvo com este nome e esta rua. Escolha-o na lista ou use outro nome.');
        }

        return $next($request);
    }

    /**
     * O mapa do portal grava a posição em `location` (GeoJSON [lng, lat]), mas o portal só lê
     * `latitude`/`longitude`. Copia para esses campos. Retorna se ficou com coordenadas válidas.
     */
    protected function copiarCoordenadas(ParameterBag $entrada): bool
    {
        $lat = $entrada->get('latitude');
        $lng = $entrada->get('longitude');

        if (!$this->coordenadaValida($lat, $lng)) {
            $coordenadas = data_get($entrada->all(), 'location.coordinates');
            if (is_array($coordenadas) && count($coordenadas) === 2) {
                [$lng, $lat] = array_values($coordenadas);
            }
        }

        if (!$this->coordenadaValida($lat, $lng)) {
            return false;
        }

        $entrada->set('latitude', (float) $lat);
        $entrada->set('longitude', (float) $lng);

        return true;
    }

    /** uuid/public_id de um endereço vindo como string ou como objeto. */
    protected function identificador($lugar): ?string
    {
        if (is_string($lugar) && $lugar !== '') {
            return $lugar;
        }

        if (is_array($lugar)) {
            foreach (['uuid', 'public_id', 'id'] as $campo) {
                if (is_string($lugar[$campo] ?? null) && $lugar[$campo] !== '') {
                    return $lugar[$campo];
                }
            }
        }

        return null;
    }

    /** Endereço salvo da loja (dono = o Vendor), por uuid ou public_id. */
    protected function lugarDaLoja(?string $id, Vendor $vendor, string $companyUuid): ?Place
    {
        if (!$id) {
            return null;
        }

        return Place::where('company_uuid', $companyUuid)
            ->where('owner_uuid', $vendor->uuid)
            ->where('owner_type', Utils::getMutationType($vendor))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->first();
    }

    protected function ehDaLoja(Place $lugar, Vendor $vendor): bool
    {
        return $lugar->owner_uuid === $vendor->uuid && $lugar->owner_type === Utils::getMutationType($vendor);
    }

    protected function temCoordenadas(Place $lugar): bool
    {
        return $lugar->location && $this->coordenadaValida($lugar->location->getLat(), $lugar->location->getLng());
    }

    protected function coordenadaValida($lat, $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180
            && (abs((float) $lat) > 0.0001 || abs((float) $lng) > 0.0001);
    }

    protected function entrada(Request $request): ParameterBag
    {
        return $request->isJson() ? $request->json() : $request->request;
    }

    protected function erro(int $status, string $mensagem)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }
}
```

Conferir: `Order::firstDispatchWithActivity()`, `adhoc` no fillable/casts de `Order` (`packages/fleetops/server/src/Models/Order.php`); `Symfony\Component\HttpFoundation\InputBag` estende `ParameterBag`.

- [ ] **Step 2: registrar** em `api/app/Http/Kernel.php`, **logo depois** do `ProtegerPortalLoja` (a ordem importa: ele grava o usuário que este lê):

```php
        \App\Http\Middleware\RegrasPortalLoja::class,
```

- [ ] **Step 3: lint** → ok.
- [ ] **Step 4: Commit** — `git add api/app && git commit -m "API: regras do portal da loja (coleta fixa, destino no mapa, despacho aos motoboys, cancelamento antes do aceite)"`

---

### Task 7: Tela "Lojas" no Fleet-Ops (admin)

**Files:** Create `packages/fleetops/addon/routes/management/lojas.js`, `addon/controllers/management/lojas.js`, `addon/templates/management/lojas.hbs`; Modify `addon/routes.js`, `addon/components/layout/fleet-ops-sidebar.js`, `translations/en-us.yaml`, `translations/pt-br.yaml`. (O engine não tem re-exports em `app/` para `management/driver-payouts`; não criar para `lojas`.)

Copiar os padrões da tela **Pagamento e cobrança** (`routes/controllers/templates/management/driver-payouts.*` e o item dela no `fleet-ops-sidebar.js`).

- [ ] **Step 1: rota** — `routes.js`, logo após `this.route('driver-payouts');`: `this.route('lojas');`
- [ ] **Step 2: route file** — mesma guarda de admin do `driver-payouts.js` (aviso `fleet-ops.ui.lojas.admin-only` e volta para `console.fleet-ops`); `setupController` chama `controller.carregar.perform()`.
- [ ] **Step 3: controller** (`ENDPOINT = 'entregas/lojas'`, via `@service fetch`, que já usa `int/v1`):
  - estado: `lojas`, `editando` (loja em edição ou nova), `lojaDoUsuario` + `novoUsuario` (painel "novo usuário"), `senhaDe` (`{loja, usuario}`) + `novaSenha` (painel "trocar senha");
  - tasks: `carregar` (restartable; `GET`), `salvarLoja` (`POST`/`PUT`; corpo `{nome, telefone, endereco:{...}}`; lat/lng como número), `adicionarUsuario` (`POST {id}/usuarios`), `trocarSenha` (`PUT {id}/usuarios/{u}/senha`, depois limpa `novaSenha` e fecha), `alterarAcesso(loja, usuario, ativo)` (`PUT {id}/usuarios/{u}/ativo` → atualiza a lista com a `loja` devolvida);
  - erros via `notifications.serverError(error)`; sucesso com `notifications.success(t(...))`;
  - **sem `window.prompt`**: senha nova e usuário novo em painéis inline;
  - getter `portalUrl` = `${window.location.origin}/customer-portal`; getter/ação para o link "Conferir no mapa" = `https://www.google.com/maps?q=${lat},${lng}` (abre em nova aba, `rel="noopener noreferrer"`), só quando lat/lng preenchidos;
  - loja nova vem com `city: 'Ribeirão Preto'`, `province: 'SP'`, `country: 'BR'`.
- [ ] **Step 4: template** — layout igual ao `driver-payouts.hbs`:
  - topo: título, `portal-link` (com `portalUrl`) e botão "Nova loja";
  - painel de edição (quando `editando`): nome, telefone, rua e número, complemento, bairro, cidade, estado, CEP, latitude, longitude, ajuda `coordinates-help`, link "Conferir no mapa", salvar/cancelar;
  - para cada loja: nome, endereço (`street1, neighborhood, city`), botões editar e "adicionar usuário"; tabela de usuários (nome, e-mail, Ativo/Inativo, "Trocar senha", "Desativar acesso"/"Reativar acesso"); `no-users` quando vazio;
  - painel "novo usuário" (nome, e-mail, telefone, senha `type="password"`) e painel "trocar senha" (`type="password"`);
  - estado vazio `empty`; carregando com `this.carregar.isRunning`.
  - Nenhum texto fixo: tudo `{{t "fleet-ops.ui.lojas.*"}}`. A senha nunca aparece em notificação nem em log.
- [ ] **Step 5: menu** — em `fleet-ops-sidebar.js`, logo após o item de driver-payouts, um item igual a ele (mesma forma de `createItem`, mesmas permissões, `visible` só para admin) com título `fleet-ops.ui.lojas.title`, ícone `store`, rota `management.lojas` e termos de busca `['loja', 'restaurante', 'portal']`; prioridade em `defaultPriorityForRoute` logo depois da de driver-payouts.
- [ ] **Step 6: traduções** — bloco `lojas:` em `fleet-ops.ui` nos dois YAML (valores com `: `, `{` ou aspas entre aspas):

| chave | pt-br | en-us |
|---|---|---|
| title | Lojas | Stores |
| menu-description | Restaurantes atendidos e o acesso deles ao portal | Served restaurants and their portal access |
| admin-only | Somente administradores podem gerenciar as lojas. | Only administrators can manage stores. |
| portal-link | "Endereço do portal das lojas: {url}" | "Store portal address: {url}" |
| new | Nova loja | New store |
| edit | Editar | Edit |
| name | Nome da loja | Store name |
| phone | Telefone | Phone |
| street1 | Rua e número | Street and number |
| street2 | Complemento | Address line 2 |
| neighborhood | Bairro | Neighborhood |
| city | Cidade | City |
| province | Estado | State |
| postal-code | CEP | Postal code |
| latitude | Latitude | Latitude |
| longitude | Longitude | Longitude |
| coordinates-help | "Coordenadas da porta da loja: no Google Maps, clique com o botão direito no local e copie os números. O km de cada entrega sai daqui." | "Coordinates of the store entrance: right-click the spot on Google Maps and copy the numbers. Each delivery's km is measured from here." |
| check-on-map | Conferir no mapa | Check on map |
| save | Salvar loja | Save store |
| cancel | Cancelar | Cancel |
| saved | Loja salva. | Store saved. |
| users | Usuários do portal | Portal users |
| add-user | Adicionar usuário | Add user |
| new-user-of | "Novo usuário de {name}" | "New user for {name}" |
| user-name | Nome | Name |
| user-email | E-mail (login) | Email (login) |
| user-phone | Telefone | Phone |
| user-password | Senha inicial (mín. 8) | Initial password (min. 8) |
| user-added | Usuário criado. Passe o e-mail e a senha para a loja. | User created. Share the email and password with the store. |
| change-password | Trocar senha | Change password |
| change-password-of | "Nova senha de {name}" | "New password for {name}" |
| new-password | Nova senha (mín. 8) | New password (min. 8) |
| password-changed | Senha alterada. As sessões abertas foram encerradas. | Password changed. Open sessions were signed out. |
| deactivate | Desativar acesso | Deactivate access |
| reactivate | Reativar acesso | Reactivate access |
| user-deactivated | Acesso desativado. | Access deactivated. |
| user-reactivated | Acesso reativado. | Access reactivated. |
| active | Ativo | Active |
| inactive | Inativo | Inactive |
| no-users | Nenhum usuário ainda. | No users yet. |
| empty | Nenhuma loja cadastrada. | No stores yet. |

- [ ] **Step 7: validar** — `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine` → exit 0; parse dos JS novos/alterados com `@babel/parser` de `console/node_modules/.pnpm/@babel+parser@7*` (`sourceType: 'module'`, plugins `decorators-legacy` e `classProperties`).
- [ ] **Step 8: Commit** — `git add packages/fleetops && git commit -m "Fleet-Ops: tela Lojas (cadastro das lojas e dos usuários do portal)"`

---

### Task 8: Formulário do operador — escolher a loja trava a coleta

**Files:** Modify `packages/fleetops/addon/components/order/form/details.js`, `details.hbs`, `components/order/form/route.js`, `route.hbs`, `translations/*.yaml`

- [ ] **Step 1: `selectCustomer` preenche a coleta** (details.js; injetar `@service store` e `@service notifications` se faltarem):

```js
    @action async selectCustomer(model) {
        this.args.resource.set('customer', model);
        this.args.resource.set('customer_uuid', model?.uuid ?? model?.id ?? null);
        this.args.resource.set('customer_type', model?.customer_type ? `fleet-ops:${model.customer_type}` : null);

        // Entregas: loja (fornecedor) → a coleta é sempre o endereço da loja
        if (model?.customer_type === 'vendor') {
            try {
                const vendor = await this.store.findRecord('vendor', model.id);
                const place = await vendor.place;
                if (place) {
                    this.args.resource.payload.pickup = place;
                }
            } catch (error) {
                this.notifications.serverError(error);
            }
        }
    }
```

(Conferir como o resto do componente grava a coleta — se usa `payload.set('pickup', …)` ou outra ação do formulário, usar a mesma.)

- [ ] **Step 2: rótulo** — no `details.hbs`, o rótulo do seletor de cliente passa a `{{t "fleet-ops.ui.order-form.store-or-customer"}}` (pt-br "Loja ou cliente", en-us "Store or customer").
- [ ] **Step 3: coleta travada** — no componente `order/form/route` (JS + template):
  - getter `coletaTravada` = `customer` do pedido com `isVendor`, ou `customer_type` contendo `vendor` (cobre `fleet-ops:vendor`, `customer-vendor` e a classe PHP em pedidos existentes);
  - com `coletaTravada`: `@disabled={{true}}` no seletor da coleta (modo simples), esconder editar/lixeira da coleta, esconder o toggle de várias paradas, e mostrar abaixo `<p class="text-xs text-gray-500 mt-1">{{t "fleet-ops.ui.order-form.pickup-locked"}}</p>` (pt-br "Coleta fixa: endereço da loja.", en-us "Fixed pickup: the store address.").
- [ ] **Step 4: validar** (i18n-check exit 0 + babel parse) e **Commit** — `git add packages/fleetops && git commit -m "Fleet-Ops: pedido com loja usa o endereço da loja como coleta fixa"`

---

### Task 9: Portal — menu, telas ocultas, membros só leitura, widgets e mapa

**Files:** Modify `packages/customer-portal/addon/templates/portal.hbs`, `addon/routes.js`, `addon/extension.js`, `addon/routes/portal/{billing,support,documents,address-book,notifications}.js`, `addon/components/portal/settings/members.{js,hbs}`, `addon/controllers/portal/account.js` (+ template, se tiver a aba de membros), `addon/components/portal/order/workspace/map.js`, `translations/*.yaml`

- [ ] **Step 1: `portal.hbs`**
  - topo: remover os dois `<LinkTo>` de suporte e documentos;
  - menu: Início (`home`), Pedidos (`orders`), **Extrato** (`<Layout::Sidebar::Item @route="customer-portal.portal.extrato" @icon="file-invoice-dollar">{{t "customer-portal.ui.entregas.statement"}}</Layout::Sidebar::Item>` no lugar de faturas) e **Configurações** (`customer-portal.portal.settings.account`, ícone `gear`, chave existente `customer-portal.ui.nav.account-settings`); remover suporte, documentos, catálogo de endereços e notificações (o grupo "preferências" some se ficar vazio).
- [ ] **Step 2: rota** — `routes.js`, dentro de `portal`: `this.route('extrato');` (antes de `virtual`, que é catch-all `/:slug`).
- [ ] **Step 3: telas ocultas redirecionam** — nos 5 arquivos de rota pai, mantendo o resto do arquivo:

```js
    @service hostRouter;

    beforeModel() {
        // Entregas: tela fora do escopo do portal da loja
        return this.hostRouter.transitionTo('customer-portal.portal.orders');
    }
```

(importar `inject as service` se faltar; arquivo vazio → classe completa `extends Route`; se a rota já tem `beforeModel`, colocar o redirecionamento no começo dele.)
- [ ] **Step 4: membros só leitura** — no componente `portal/settings/members` (e na aba equivalente de `controllers/portal/account.js`, se ainda for usada): carregar **só** `account/personnels` (o `account/personnel-candidates` volta 403 e hoje está num `Promise.all` que derrubaria a lista inteira); esconder os botões de adicionar e remover membro; mostrar `customer-portal.ui.entregas.members-managed-by-central`.
- [ ] **Step 5: widgets** — `extension.js#registerWidgets`: remover os `new Widget` de `widget/unpaid-invoices`, `widget/open-tickets` e `widget/pending-actions` (ficam pedidos ativos, concluídos e recentes).
- [ ] **Step 6: mapa** — `workspace/map.js`: centro padrão de Singapura `(1.3521, 103.8198)` → Ribeirão Preto `(-21.1775, -47.8103)`.
- [ ] **Step 7: traduções** — bloco `entregas:` em `customer-portal.ui` nos dois YAML (chaves das Tasks 9–12; valores com `{`, `: ` ou aspas entre aspas):

| chave | pt-br | en-us |
|---|---|---|
| statement | Extrato | Statement |
| statement-title | Extrato de entregas | Delivery statement |
| period-start | Início | Start |
| period-end | Fim | End |
| order | Pedido | Order |
| deliveries | Entregas | Deliveries |
| total-km | Km total | Total km |
| amount-due | Valor a pagar | Amount due |
| band | Faixa | Band |
| band-range | "{from} a {to} km" | "{from} to {to} km" |
| band-above | "acima de {from} km" | "above {from} km" |
| destination | Destino | Destination |
| completed-at | Concluído em | Completed at |
| amount | Valor | Amount |
| export-csv | Baixar CSV | Download CSV |
| no-deliveries | Nenhuma entrega concluída no período. | No completed deliveries in this period. |
| pending-km | "{count} entrega(s) ainda sem km calculado; clique em Atualizar." | "{count} deliveries still missing km; click Refresh." |
| refresh | Atualizar | Refresh |
| pickup-fixed | Coleta | Pickup |
| pickup-fixed-help | Sempre no endereço da loja. | Always at the store address. |
| store-without-address | A loja ainda não tem endereço de coleta. Fale com a central. | The store has no pickup address yet. Contact the dispatcher. |
| customer-name-help | No endereço de entrega, use o nome e o telefone do cliente. | On the delivery address, use the customer's name and phone. |
| map-location-required | Marque no mapa o local da entrega (Selecionar no mapa). | Mark the delivery spot on the map (Select from map). |
| courier | Motoboy | Courier |
| courier-waiting | Aguardando um motoboy aceitar. | Waiting for a courier to accept. |
| courier-accepted | "{name} está com o pedido." | "{name} has the order." |
| courier-assigned | "{name} foi chamado e ainda não aceitou." | "{name} was assigned and hasn't accepted yet." |
| courier-position | Posição do motoboy | Courier position |
| cancel-locked | O motoboy já aceitou. Para cancelar, fale com a central. | The courier already accepted. To cancel, contact the dispatcher. |
| members-managed-by-central | Para adicionar ou remover usuários da loja, fale com a central. | To add or remove store users, contact the dispatcher. |

- [ ] **Step 8: validar** — `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` → exit 0; babel parse dos JS alterados.
- [ ] **Step 9: Commit** — `git add packages/customer-portal && git commit -m "Portal da loja: menu enxuto, telas fora do escopo ocultas, membros só leitura, mapa em Ribeirão Preto"`

---

### Task 10: Portal — novo pedido só com o destino (endereço marcado no mapa)

**Files:** Modify `packages/customer-portal/addon/services/customer-portal-order-validation.js`, `addon/components/portal/order/form.{js,hbs}`, `addon/components/portal/order/form/route.{js,hbs}`, `addon/components/modals/portal-order-place-form.hbs`, `addon/routes/portal/orders/new.js`; `packages/ember-ui/addon/components/coordinates-input.js`, `packages/ember-ui/addon/components/model-coordinates-input.hbs`

- [ ] **Step 1: validação sem coleta** (`customer-portal-order-validation.js#canQuote`):

```js
    canQuote(draft) {
        if (!draft?.orderConfig) {
            return false;
        }

        // Entregas: a coleta é sempre o endereço da loja (o servidor grava); a loja só informa o destino
        return Boolean(draft.payload?.dropoff);
    }
```

- [ ] **Step 2: sem cotação** — `form.js#refreshServiceQuotes` vira `return;` com o comentário `// Entregas: sem tarifas nem pagamento online; o valor sai das faixas de km no extrato`.
- [ ] **Step 3: painéis** — `form.hbs`: só `Portal::Order::Form::Route` (com `@loja={{@model.loja}}`) e `Portal::Order::Form::Notes`; remover Details, CustomFields, Payload, ServiceRate, Documents (o tipo é o primeiro habilitado — `start(orderConfigs)` já usa `orderConfigs[0]`).
- [ ] **Step 4: rota de criação** (`routes/portal/orders/new.js`, com `@service fetch`):

```js
    async model() {
        const [orderConfigs, places, minhaLoja] = await Promise.all([
            this.customerPortalOrderActions.loadOrderConfigs.perform(),
            this.customerPortalOrderActions.loadPlaces.perform(),
            this.fetch.get('entregas/loja/minha-loja').catch(() => null),
        ]);
        const loja = minhaLoja?.loja ?? null;
        const draft = this.customerPortalOrderCreation.start(orderConfigs);

        // só para mostrar a coleta e desenhar a rota; quem grava a coleta no pedido é o servidor
        if (loja?.coleta) {
            this.customerPortalOrderCreation.setPayloadField('pickup', loja.coleta);
        }

        return { draft: this.customerPortalOrderCreation.draft, orderConfigs, places, loja };
    }
```

- [ ] **Step 5: `form/route.{js,hbs}`**
  - remover o `Toggle` de várias paradas e todo o ramo `{{#if @draft.isMultipleDropoffOrder}}` (fica só o modo simples); remover o seletor de `return`;
  - a coleta vira bloco só leitura:

```hbs
            <InputGroup>
                <label class="text-xs font-semibold dark:text-gray-100 mb-1 block">{{t "customer-portal.ui.entregas.pickup-fixed"}}</label>
                {{#if @loja.coleta}}
                    <div class="form-input bg-gray-50 dark:bg-gray-800">{{@loja.nome}}{{if @loja.endereco (concat " — " @loja.endereco)}}</div>
                    <p class="text-xs text-gray-500 mt-1">{{t "customer-portal.ui.entregas.pickup-fixed-help"}}</p>
                {{else}}
                    <p class="text-xs text-red-500">{{t "customer-portal.ui.entregas.store-without-address"}}</p>
                {{/if}}
            </InputGroup>
```

  - abaixo do seletor do destino: `<p class="text-xs text-gray-500 mt-1">{{t "customer-portal.ui.entregas.customer-name-help"}}</p>`;
  - o botão "Novo endereço" (`actionButtons`) abre o formulário para `dropoff` (hoje abre para `pickup`); tirar o botão de waypoint;
  - **sem edição de endereço salvo**: remover o link "Editar" do destino e o fluxo `openEditPlaceForm`/modo `edit` (o servidor recusa `PATCH`/`DELETE` de endereço da loja; para corrigir, a loja cadastra um endereço novo);
  - `places` exclui o Local da loja (`uuid`/`public_id` igual a `@loja.coleta.uuid`/`public_id`), para a loja não escolher o próprio endereço como destino;
  - `openPlaceForm` passa `mapCenter: { latitude: @loja.coleta.latitude, longitude: @loja.coleta.longitude }` (quando houver) e `keepOpen: true` nas opções do modal, com `confirm: (modal, done) => this.savePlace(place, done)`;
  - `savePlace(place, done)`: antes de salvar, ler as coordenadas de `place.latitude/longitude` ou de `place.location.coordinates` (`[lng, lat]`); sem coordenadas válidas (números, não ambos ~0) → `notifications.warning(t('customer-portal.ui.entregas.map-location-required'))` e **não** chamar `done` (o modal fica aberto); com coordenadas → envia `latitude`/`longitude` junto (além de `location`) e, salvando com sucesso, chama `done()`; erro do servidor (ex.: endereço repetido, 422) → `notifications.serverError` e o modal fica aberto.
- [ ] **Step 6: mapa do endereço abre na loja** — ember-ui:
  - `coordinates-input.js`: `DEFAULT_LATITUDE/LONGITUDE` → Ribeirão Preto (`-21.1775`, `-47.8103`); em `setInitialMapCoordinates`, usar `this.args.mapCenter` (`{latitude, longitude}` numéricos válidos) antes do whois;
  - `model-coordinates-input.hbs`: repassar `@mapCenter={{@mapCenter}}` e `@zoom={{@zoom}}` ao `CoordinatesInput` (sem `@zoom`, o `CoordinatesInput` mantém o padrão 9 — conferir que `undefined` não sobrescreve o padrão do construtor);
  - `modals/portal-order-place-form.hbs`: `<ModelCoordinatesInput ... @mapCenter={{@options.mapCenter}} @zoom={{15}} />`.
- [ ] **Step 7: validar** — i18n-check (com `customer-portal`) exit 0 + babel parse.
- [ ] **Step 8: Commit** — `git add packages/customer-portal packages/ember-ui && git commit -m "Portal da loja: novo pedido só com o destino marcado no mapa; coleta fixa"`

---

### Task 11: Portal — acompanhar (polling, motoboy, cancelamento)

**Files:** Modify `addon/components/portal/order/details.{js,hbs}`, `addon/controllers/portal/orders.js`, `addon/routes/portal/orders.js`; Create `addon/components/portal/order/details/motoboy.{js,hbs}`, `app/components/portal/order/details/motoboy.js`

- [ ] **Step 1: detalhe recarrega a cada 20 s enquanto o pedido está aberto** (details.js):

```js
import { task, timeout } from 'ember-concurrency';
import { registerDestructor } from '@ember/destroyable';
// ...
const INTERVALO_MS = 20000;

    constructor() {
        super(...arguments);
        this.acompanhar.perform();
        registerDestructor(this, () => this.acompanhar.cancelAll());
    }

    @task *acompanhar() {
        while (this.order && !CLOSED_STATUSES.includes(valueFor(this.order, 'status'))) {
            yield timeout(INTERVALO_MS);
            try {
                this.order = yield this.customerPortalOrderActions.loadOrder.perform(this.customerPortalOrderActions.identifier(this.order));
            } catch {
                // rede instável: tenta de novo no próximo ciclo
            }
        }
    }
```

(Conferir de onde vem `this.order` no componente — `@tracked` próprio ou `@args` — e atualizar o mesmo estado que o template lê.)

- [ ] **Step 2: cancelamento só antes do aceite; sem reagendar e sem suporte** — em details.js:

```js
    get canCancel() {
        return Boolean(this.order) && !valueFor(this.order, 'started') && ['created', 'dispatched'].includes(valueFor(this.order, 'status'));
    }
```

`actionButtons` fica só com etiqueta (`view-label`) e cancelar (`disabled: !this.canCancel`); remover suporte e reagendamento. Quando não puder cancelar e o pedido estiver aberto, mostrar `customer-portal.ui.entregas.cancel-locked` perto das ações.

- [ ] **Step 3: componente do motoboy** (`details/motoboy.js`):

```js
import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';
import { registerDestructor } from '@ember/destroyable';

const INTERVALO_MS = 20000;
const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled'];

export default class PortalOrderDetailsMotoboyComponent extends Component {
    @service fetch;
    @tracked motoboy = null;

    constructor() {
        super(...arguments);
        this.acompanhar.perform();
        registerDestructor(this, () => this.acompanhar.cancelAll());
    }

    get pedidoId() {
        return this.args.resource?.public_id ?? this.args.resource?.id;
    }

    get temPosicao() {
        return Boolean(this.motoboy?.latitude && this.motoboy?.longitude);
    }

    @task *acompanhar() {
        while (this.pedidoId && !ENCERRADOS.includes(this.args.resource?.status)) {
            try {
                const resposta = yield this.fetch.get(`entregas/loja/pedidos/${this.pedidoId}/motoboy`);
                this.motoboy = resposta?.motoboy ?? null;
            } catch {
                this.motoboy = null;
            }
            yield timeout(INTERVALO_MS);
        }
    }
}
```

`motoboy.hbs` — copiar a forma do `LeafletMap`/`layers.tile`/`layers.marker` de `workspace/map.hbs` (`@lat`/`@lng` no marker, tile `light_all` do carto):

```hbs
<ContentPanel @title={{t "customer-portal.ui.entregas.courier"}} @open={{true}} @wrapperClass="bordered-top">
    {{#if this.motoboy}}
        <div class="flex items-center space-x-3 mb-3">
            {{#if this.motoboy.foto}}<img src={{this.motoboy.foto}} alt="" class="w-10 h-10 rounded-full object-cover" />{{/if}}
            <div class="text-sm">
                {{#if this.motoboy.aceitou}}
                    {{t "customer-portal.ui.entregas.courier-accepted" name=this.motoboy.nome}}
                {{else}}
                    {{t "customer-portal.ui.entregas.courier-assigned" name=this.motoboy.nome}}
                {{/if}}
            </div>
        </div>
        {{#if this.temPosicao}}
            <div class="h-56 rounded-md overflow-hidden">
                <LeafletMap @lat={{this.motoboy.latitude}} @lng={{this.motoboy.longitude}} @zoom={{15}} as |layers|>
                    <layers.tile @url="https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png" />
                    <layers.marker @lat={{this.motoboy.latitude}} @lng={{this.motoboy.longitude}} as |marker|>
                        <marker.tooltip @permanent={{true}}>{{t "customer-portal.ui.entregas.courier-position"}}</marker.tooltip>
                    </layers.marker>
                </LeafletMap>
            </div>
        {{/if}}
    {{else}}
        <p class="text-sm text-gray-500">{{t "customer-portal.ui.entregas.courier-waiting"}}</p>
    {{/if}}
</ContentPanel>
```

Re-export em `app/components/portal/order/details/motoboy.js` no padrão dos vizinhos (`export { default } from '@fleetbase/customer-portal-engine/components/portal/order/details/motoboy';` — conferir o nome do módulo usado nos outros re-exports).

- [ ] **Step 4: `details.hbs`** — inserir `<Portal::Order::Details::Motoboy @resource={{this.order}} />` logo após `Activity`; remover `ServiceRate`, `Payload`, `Documents`, `CustomFields`.
- [ ] **Step 5: lista atualiza a cada 30 s** — `controllers/portal/orders.js`: task `atualizarLista` (`while (true) { yield timeout(30000); <recarregar a lista com a ação/task que o controller já usa>; }`), iniciada no `setupController` da rota `routes/portal/orders.js` e cancelada no `resetController`.
- [ ] **Step 6: validar** (i18n + babel) e **Commit** — `git add packages/customer-portal && git commit -m "Portal da loja: acompanhamento com motoboy e posição, cancelamento só antes do aceite"`

---

### Task 12: Portal — Extrato

**Files:** Create `addon/routes/portal/extrato.js`, `addon/controllers/portal/extrato.js`, `addon/templates/portal/extrato.hbs`; re-exports `app/routes/portal/extrato.js`, `app/controllers/portal/extrato.js`, `app/templates/portal/extrato.js` (copiar o formato dos re-exports de `home` em `app/`).

- [ ] **Step 1: rota**

```js
import Route from '@ember/routing/route';

export default class PortalExtratoRoute extends Route {
    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }
}
```

- [ ] **Step 2: controller**

```js
import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const hoje = () => {
    const agora = new Date();
    return new Date(agora.getTime() - agora.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};
const inicioDoMes = () => `${hoje().slice(0, 8)}01`;

export default class PortalExtratoController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked inicio = inicioDoMes();
    @tracked fim = hoje();
    @tracked dados = null;

    @task({ restartable: true }) *carregar() {
        try {
            this.dados = yield this.fetch.get('entregas/loja/extrato', { inicio: this.inicio, fim: this.fim });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action setInicio(event) {
        this.inicio = event.target.value;
        this.carregar.perform();
    }

    @action setFim(event) {
        this.fim = event.target.value;
        this.carregar.perform();
    }

    @action textoFaixa(faixa) {
        if (!faixa) {
            return '—';
        }
        return faixa.acima
            ? this.intl.t('customer-portal.ui.entregas.band-above', { from: faixa.de_km })
            : this.intl.t('customer-portal.ui.entregas.band-range', { from: faixa.de_km, to: faixa.ate_km });
    }

    @action dinheiro(valor) {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor ?? 0);
    }

    @action baixarCsv() {
        const t = (chave) => this.intl.t(`customer-portal.ui.entregas.${chave}`);
        const linhas = [[t('completed-at'), t('order'), t('destination'), 'Km', t('band'), t('amount')]];
        for (const e of this.dados?.entregas ?? []) {
            linhas.push([e.concluido_em, e.id_interno ?? e.pedido, e.destino ?? '', e.km ?? '', this.textoFaixa(e.faixa), (e.valor ?? 0).toFixed(2).replace('.', ',')]);
        }
        const csv = linhas.map((linha) => linha.map((celula) => `"${String(celula).replace(/"/g, '""')}"`).join(';')).join('\n');
        const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `extrato_${this.inicio}_${this.fim}.csv`;
        link.click();
        URL.revokeObjectURL(link.href);
    }
}
```

- [ ] **Step 3: template** — mesma estrutura de página das outras telas do portal (copiar o wrapper de `templates/portal/home.hbs` ou `orders`): título `statement-title`; dois `<input type="date">` (`setInicio`/`setFim`, rótulos `period-start`/`period-end`); botões `refresh` (`perform this.carregar`) e `export-csv` (`this.baixarCsv`, desabilitado sem entregas); três cartões (entregas, `total-km`, `amount-due` com `this.dinheiro`); aviso `pending-km` se `dados.pendentes > 0`; tabela (concluído em — data/hora pt-BR —, pedido `id_interno ?? pedido`, destino, km, faixa via `this.textoFaixa`, valor via `this.dinheiro`); `no-deliveries` quando vazio; carregando com `this.carregar.isRunning`.
- [ ] **Step 4: validar** (i18n + babel) e **Commit** — `git add packages/customer-portal && git commit -m "Portal da loja: extrato de entregas com faixas e CSV"`

---

### Task 13: Build local do console

- [ ] **Step 1:** `cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production` (≈10 min, em background). Esperado: sem erro; `ls dist/engines-dist/@fleetbase/customer-portal-engine` existe.
- [ ] **Step 2:** se falhar, corrigir na task responsável e repetir; não seguir para o deploy com build quebrado. `console/dist` nunca vai para o git.

---

### Task 14: Teste de isolamento, deploy e configuração (com o usuário)

**Files:** Create `scripts/teste-isolamento-lojas.mjs`; Modify `.gitignore`

- [ ] **Step 1: script** (`node scripts/teste-isolamento-lojas.mjs`, Node 18+, sem dependências) — lê `deploy/teste-lojas.env` (`API=https://entregas-api.restaurantepro.com.br`, `LOJA_A_EMAIL`, `LOJA_A_SENHA`, `LOJA_B_EMAIL`, `LOJA_B_SENHA`); faz login de cada loja em `POST {API}/customer-portal/int/v1/auth/login` (`{identity, password}` → `token`); imprime `PASSOU`/`FALHOU` por item e sai com 1 se algo falhar. **Nunca imprime senha nem token.** Itens:
  1. A cria endereço sem coordenadas (`POST customer-portal/int/v1/places` `{name:'Teste isolamento', street1:'Rua Teste, 1'}`) → 422.
  2. A cria endereço com coordenadas (`{name:'Teste isolamento', street1:'Rua Teste, 1', city:'Ribeirão Preto', location:{type:'Point', coordinates:[-47.81, -21.18]}}`) → 200 e o place devolvido tem latitude ≈ -21.18. (Se o endereço já existir de uma rodada anterior, o 422 de "endereço repetido" também conta como esperado: o script busca o existente em `GET .../places/search?query=Teste isolamento` e segue.)
  2b. Endereço salvo não muda pelo portal: `PATCH .../places/{id}` desse endereço → 403; `DELETE` → 403; `POST .../places` com o mesmo nome e rua → 422; `POST .../places` com o nome e a rua do Local da loja A (de `GET int/v1/entregas/loja/minha-loja` → `loja.coleta`) e outras coordenadas → 422, e o `minha-loja` continua com as coordenadas originais.
  3. A cria pedido (`POST customer-portal/int/v1/orders`) com `dropoff` = uuid desse endereço **e** `pickup` falso `{street1:'Rua Falsa, 999', latitude:-23.5, longitude:-46.6}` e `meta:{entregas:{km_rota:{metros:1}}}` → 200; o pedido devolvido tem `payload.pickup` com o endereço da loja A (não "Rua Falsa") e sem `meta.entregas`.
  4. Depois de ~5 s, `GET customer-portal/int/v1/orders/{id}` (A) → `adhoc` verdadeiro e (`dispatched` verdadeiro ou status `dispatched`).
  5. A cria pedido com `dropoff` inline sem id (`{street1:'Rua X', latitude:-21.2, longitude:-47.8}`) → 422.
  6. B lista pedidos (`GET .../orders`) → o pedido de A não aparece.
  7. B abre o pedido de A (`GET .../orders/{id}`) → 404.
  8. B cancela o pedido de A (`POST .../orders/{id}/cancel`) → 404, e o pedido continua aberto para A.
  9. B pede o motoboy do pedido de A (`GET int/v1/entregas/loja/pedidos/{id}/motoboy`) → 404.
  10. Com o token de B → 403 em: `GET int/v1/orders`, `GET int/v1/contacts`, `GET int/v1/places`, `GET int/v1/drivers`, `GET v1/orders`, `GET int/v1/entregas/lojas`, `GET int/v1/entregas/pagamento-motoboys?inicio=2026-01-01&fim=2026-01-02`, `GET customer-portal/int/v1/settings/config`, `GET customer-portal/int/v1/account/personnel-candidates`, `POST customer-portal/int/v1/service-quotes/preliminary`.
  11. B tenta virar admin: `GET int/v1/users/me` (pega o próprio id), `PUT int/v1/users/{id}` com `{user:{name:<o mesmo nome>, role:'Administrator'}}` → depois `GET int/v1/users/me` não tem papel Administrator (comparar o campo de papel que vier na resposta).
  12. B extrato (`GET int/v1/entregas/loja/extrato?inicio=<hoje>&fim=<hoje>`) → 200 com `totais`.
  13. A cancela o próprio pedido (ainda sem aceite) → 200 e status `canceled`.
- [ ] **Step 2:** `.gitignore`: `deploy/teste-lojas.env`.
- [ ] **Step 3: Commit** — `git add scripts/teste-isolamento-lojas.mjs .gitignore && git commit -m "Teste de isolamento do portal da loja"`; merge na `main` e push **só com o aval do usuário**.
- [ ] **Step 4 (usuário, na VPS):** `cd ~/entregas && bash deploy/atualizar.sh` (API + console); depois `docker service update --force entregas_queue`.
- [ ] **Step 5 (usuário, console com Ctrl+Shift+R):** Admin → Customer Portal: habilitar só o tipo `transport`, pagamentos desligados, definir o endereço de acesso.
- [ ] **Step 6 (usuário):** Fleet-Ops → Recursos → Lojas: criar "Loja Teste A" e "Loja Teste B" (endereços diferentes, com coordenadas) com um usuário cada; gravar e-mails/senhas em `deploy/teste-lojas.env` (o usuário digita, não o Claude).
- [ ] **Step 7:** avisar que o item 3 dispara um aviso real de pedido aos motoboys online perto da Loja Teste A (o item 13 cancela); rodar `node scripts/teste-isolamento-lojas.mjs` → todos PASSOU.
- [ ] **Step 8: navegador (o usuário faz o login, numa janela anônima — o console guarda a lista de extensões no `localStorage` por 1 h, e um navegador que abriu o console antes do deploy não enxerga o engine do portal)** — logado como loja A em `/customer-portal`: Início, Pedidos, novo pedido (endereço novo marcado no mapa), detalhe, Extrato, Configurações (conta e membros). Conferir no log da API (`[entregas] portal da loja: acesso negado`) ou no console de rede que nenhuma chamada necessária volta 403; se voltar, liberar só aquele caminho e repetir 7–8. Aceitar o pedido de teste no Navigator: o motoboy aparece no portal em ≤ 20 s e o cancelamento fica bloqueado.
- [ ] **Step 9:** desativar as lojas de teste (ou apagá-las) conforme o usuário decidir.

---

### Task 15: Documentação

**Files:** Modify `CLAUDE.md`, memória `delivery-fleetbase-repo.md`

- [ ] **Step 1: CLAUDE.md** — em "Escopo enxuto": customer-portal volta ao console (portal da loja). Nova seção "Portal da loja": loja = Vendor type customer + Place dono = Vendor; usuários = Contact customer + VendorPersonnel criados na tela Lojas (senha inicial, e-mail verificado; desativar/trocar senha derrubam tokens); `ProtegerPortalLoja` (global, token Sanctum, nega por padrão, inclusive `v1/*`; listas `PERMITIDAS_INTERNAS`/`NEGADAS_NO_PORTAL`) e `RegrasPortalLoja` (coleta fixa, destino salvo com coordenadas, despacho adhoc, cancelamento antes do aceite, coordenadas do mapa); endpoints `int/v1/entregas/lojas*` e `int/v1/entregas/loja/*`; cobrança agrupada pelo Vendor dono do pedido com fallback no nome da coleta; o servidor não tem geocodificação (endereço do portal precisa do mapa); o canal de socket `company.<uuid>` não é usado no portal (transmite todas as lojas); teste `scripts/teste-isolamento-lojas.mjs`. Histórico item 10.
- [ ] **Step 2: Commit** — `git commit -am "CLAUDE.md: portal da loja"`

---

## Riscos conhecidos (fora deste plano)

- **Socket:** o SocketCluster não autentica a inscrição em canais; quem souber o uuid da empresa ouve `company.<uuid>` (eventos de pedidos de todas as lojas). O portal não usa. Correção futura: exigir token no `docker/socket/server.js` para canais `company.*`.
- **Chave de API por loja (iFood):** próxima etapa.
- **Permissões do Fleetbase:** o papel "Fleet-Ops Customer" é largo demais (contatos, usuários). O `ProtegerPortalLoja` cobre os usuários de loja; clientes finais com login criados pelo Fleet-Ops (Clientes) também são `type=customer` e passam pelas mesmas regras.
