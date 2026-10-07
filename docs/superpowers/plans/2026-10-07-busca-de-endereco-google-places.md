# Busca de endereço pelo Google Places Autocomplete: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a busca de endereço do console e do portal passa a sugerir endereços pelo Google Places Autocomplete (Brasil, pt-BR, perto de quem digita) e grava o local no formato brasileiro ("Rua Olinda, 45").

**Architecture:** três rotas novas em `api/app` (`int/v1/entregas/enderecos/{sugestoes,detalhes,busca}`) falam com a Places API (New) por uma porta HTTP única; a montagem do endereço brasileiro é uma classe de funções puras. No front, um componente novo do ember-ui (`EnderecoGoogleInput`) substitui a busca da "Rua 1" do cadastro de local e do portal; o campo do pedido (`ModelSelect`) passa a usar a rota `busca`, que junta locais salvos e sugestões, e resolve a sugestão escolhida antes de pô-la no pedido.

**Tech Stack:** Laravel 10 / PHP 8.2 (testes no php-wasm), Ember (ember-ui, fleetops, customer-portal), Node `node:test`.

**Spec:** `docs/superpowers/specs/2026-10-07-busca-de-endereco-google-places-design.md`.

**Antes de começar:** crie o ramo `busca-de-endereco` a partir da `main` (`git switch -c busca-de-endereco`). Confirme `git rev-parse --show-toplevel` = a pasta `Delivery`. O php-wasm está instalado em `C:\tmp\php-wasm` (`PHP_WASM_DIR=/c/tmp/php-wasm`).

---

## Arquivos

| Arquivo | Papel |
|---|---|
| `api/app/Support/Entregas/Enderecos/EnderecoBrasileiro.php` (novo) | funções puras: detalhes do Google → atributos do Place no formato brasileiro |
| `api/app/Support/Entregas/Enderecos/ErroGooglePlaces.php` (novo) | erro tipado da Places API (status 0 = rede ou sem chave) |
| `api/app/Support/Entregas/Enderecos/ClienteGooglePlaces.php` (novo) | única porta HTTP para a Places API (New) |
| `api/app/Support/Entregas/Enderecos/BuscaDeEnderecos.php` (novo) | regras: referência de posição, mínimo de caracteres, falhas e log, sugestões como Place |
| `api/app/Http/Controllers/Entregas/EnderecosController.php` (novo) | as três rotas |
| `api/app/Providers/RouteServiceProvider.php` | rotas e limitador `entregas-enderecos` |
| `api/app/Http/Middleware/ProtegerPortalLoja.php` | libera `sugestoes` e `detalhes` ao usuário de loja |
| `scripts/teste-php/enderecos.php` (novo) | testes do servidor |
| `packages/ember-ui/addon/utils/endereco-brasileiro.js` (novo) | funções puras do front |
| `packages/ember-ui/addon/components/endereco-google-input.{js,hbs}` (novo) + `packages/ember-ui/app/components/endereco-google-input.js` | campo com sugestões |
| `packages/ember-ui/addon/utils/place-address.js`, `packages/fleetops/addon/utils/place-address-html.js` | exibição: linha "bairro, cidade - UF, CEP" |
| `packages/fleetops/addon/components/place/form.{hbs,js}` | "Rua 1" do cadastro de local |
| `packages/customer-portal/addon/components/modals/portal-order-place-form.{hbs,js}` | "Rua 1" do novo endereço do portal |
| `packages/fleetops/addon/components/order/form/route.{hbs,js}` | campo Entrega/Coleta/Retorno/paradas do pedido |
| `console/translations/{en-us,pt-br}.yaml`, `packages/fleetops/translations/{en-us,pt-br}.yaml` | textos novos |
| `scripts/teste-portal/endereco-brasileiro.test.mjs` (novo) | testes do front |
| `CLAUDE.md` | documentação |

---

### Task 1: Endereço brasileiro a partir dos detalhes do Google (servidor)

**Files:**
- Create: `api/app/Support/Entregas/Enderecos/EnderecoBrasileiro.php`
- Test: `scripts/teste-php/enderecos.php`

- [ ] **Step 1: Escrever o teste (falha)**

Crie `scripts/teste-php/enderecos.php`:

```php
<?php

// Busca de endereço pelo Google Places (New): o endereço no formato brasileiro (EnderecoBrasileiro), a porta HTTP
// (ClienteGooglePlaces), as regras da busca (BuscaDeEnderecos) e a liberação no portal da loja (ProtegerPortalLoja).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php

require __DIR__ . '/stubs-ifood.php';
require '/repo/api/app/Support/Entregas/Enderecos/EnderecoBrasileiro.php';

use App\Support\Entregas\Enderecos\EnderecoBrasileiro;

function componente(string $longo, array $tipos, ?string $curto = null): array
{
    return ['longText' => $longo, 'shortText' => $curto ?? $longo, 'types' => $tipos];
}

/** Detalhes da Places API (New) para a Rua Olinda, 45 em Ribeirão Preto, sem os componentes em $sem. */
function detalhesOlinda(array $sem = [], array $extra = []): array
{
    $componentes = [
        'numero' => componente('45', ['street_number']),
        'rua'    => componente('Rua Olinda', ['route']),
        'bairro' => componente('Jardim Paulista', ['sublocality_level_1', 'sublocality', 'political']),
        'cidade' => componente('Ribeirão Preto', ['administrative_area_level_2', 'political']),
        'uf'     => componente('São Paulo', ['administrative_area_level_1', 'political'], 'SP'),
        'pais'   => componente('Brasil', ['country', 'political'], 'BR'),
        'cep'    => componente('14025-150', ['postal_code']),
    ];
    foreach ($sem as $chave) {
        unset($componentes[$chave]);
    }

    return [
        'id'                => 'ChIJolinda45',
        'formattedAddress'  => 'R. Olinda, 45 - Jardim Paulista, Ribeirão Preto - SP, 14025-150, Brasil',
        'addressComponents' => array_values(array_merge($componentes, $extra)),
        'location'          => ['latitude' => -21.1901, 'longitude' => -47.7925],
    ];
}

echo '== EnderecoBrasileiro' . PHP_EOL;
$endereco = EnderecoBrasileiro::deDetalhes(detalhesOlinda());
confere($endereco['street1'] === 'Rua Olinda, 45', 'rua e número no padrão brasileiro: "Rua Olinda, 45"');
confere($endereco['neighborhood'] === 'Jardim Paulista', 'bairro pelo sublocality_level_1');
confere($endereco['city'] === 'Ribeirão Preto', 'cidade pela administrative_area_level_2');
confere($endereco['province'] === 'SP', 'UF curta');
confere($endereco['postal_code'] === '14025-150', 'CEP');
confere($endereco['country'] === 'BR', 'país curto');
confere($endereco['location'] === ['type' => 'Point', 'coordinates' => [-47.7925, -21.1901]], 'coordenadas em GeoJSON (longitude, latitude)');
confere(array_keys($endereco) === ['street1', 'neighborhood', 'city', 'province', 'postal_code', 'country', 'location'], 'só atributos do modelo Place');

$semNumero = EnderecoBrasileiro::deDetalhes(detalhesOlinda(['numero']), 'rua olinda 45');
confere($semNumero['street1'] === 'Rua Olinda, 45', 'sem número no Google: usa o número digitado');
confere(EnderecoBrasileiro::deDetalhes(detalhesOlinda(['numero']), 'rua olinda')['street1'] === 'Rua Olinda', 'sem número no Google nem no texto: só a rua');
confere(EnderecoBrasileiro::deDetalhes(detalhesOlinda(['numero']))['street1'] === 'Rua Olinda', 'sem número e sem texto: só a rua');

$porLocality = EnderecoBrasileiro::deDetalhes(detalhesOlinda(['cidade'], [componente('Ribeirão Preto', ['locality', 'political'])]));
confere($porLocality['city'] === 'Ribeirão Preto', 'sem administrative_area_level_2: cidade pela locality');

$semRua = detalhesOlinda(['numero', 'rua']);
$semRua['formattedAddress'] = 'Shopping Santa Úrsula - Jardim Sumaré, Ribeirão Preto - SP, Brasil';
confere(EnderecoBrasileiro::deDetalhes($semRua)['street1'] === 'Shopping Santa Úrsula', 'sem rua: a primeira parte do endereço formatado');

$semLocal = detalhesOlinda();
unset($semLocal['location']);
confere(EnderecoBrasileiro::deDetalhes($semLocal)['location'] === null, 'sem coordenadas: location nulo');

confere(EnderecoBrasileiro::numeroDigitado('Rua 7 de Setembro, 100', 'Rua 7 de Setembro') === '100', 'número digitado: ignora o número do nome da rua');
confere(EnderecoBrasileiro::numeroDigitado('rua 7 de setembro', 'Rua 7 de Setembro') === null, 'número digitado: só o número do nome da rua = nenhum');
confere(EnderecoBrasileiro::numeroDigitado('rua olinda 45 apto 12', 'Rua Olinda') === '45', 'número digitado: o primeiro, não o do apartamento');
confere(EnderecoBrasileiro::numeroDigitado('rua olinda, 45, 14025-150', 'Rua Olinda') === '45', 'número digitado: ignora o CEP');
confere(EnderecoBrasileiro::numeroDigitado('av brasil 1200a', 'Avenida Brasil') === '1200A', 'número digitado com letra');
confere(EnderecoBrasileiro::numeroDigitado('rua olinda', 'Rua Olinda') === null, 'sem número digitado');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php`
Expected: erro fatal "Failed opening required '/repo/api/app/Support/Entregas/Enderecos/EnderecoBrasileiro.php'".

- [ ] **Step 3: Implementar**

Crie `api/app/Support/Entregas/Enderecos/EnderecoBrasileiro.php`:

```php
<?php

namespace App\Support\Entregas\Enderecos;

/**
 * Entregas RestaurantePro: o endereço do Google (detalhes da Places API New) nos atributos do Place, no formato
 * brasileiro. O Fleet-Ops grava a rua no padrão dos EUA ("45 Rua Olinda") e a cidade pela `locality`, que o Google
 * quase nunca devolve no Brasil; aqui a rua vem antes do número e a cidade vem da `administrative_area_level_2`.
 *
 * Funções puras (testadas em scripts/teste-php/enderecos.php).
 */
class EnderecoBrasileiro
{
    /**
     * Atributos do Place a partir dos detalhes (addressComponents, location, formattedAddress). Sem `street_number` no
     * Google, usa o número que a pessoa digitou na busca ($textoDigitado).
     */
    public static function deDetalhes(array $detalhes, ?string $textoDigitado = null): array
    {
        $partes    = static::componentes((array) ($detalhes['addressComponents'] ?? []));
        $rua       = $partes['route'] ?? null;
        $numero    = $partes['street_number'] ?? ($textoDigitado ? static::numeroDigitado($textoDigitado, $rua) : null);
        $latitude  = $detalhes['location']['latitude'] ?? null;
        $longitude = $detalhes['location']['longitude'] ?? null;

        return [
            'street1'      => $rua ? ($numero ? "{$rua}, {$numero}" : $rua) : static::primeiraParte((string) ($detalhes['formattedAddress'] ?? '')),
            'neighborhood' => $partes['sublocality_level_1'] ?? $partes['sublocality'] ?? $partes['neighborhood'] ?? null,
            'city'         => $partes['administrative_area_level_2'] ?? $partes['locality'] ?? null,
            'province'     => $partes['administrative_area_level_1_curto'] ?? null,
            'postal_code'  => $partes['postal_code'] ?? null,
            'country'      => $partes['country_curto'] ?? 'BR',
            'location'     => is_numeric($latitude) && is_numeric($longitude)
                ? ['type' => 'Point', 'coordinates' => [(float) $longitude, (float) $latitude]]
                : null,
        ];
    }

    /**
     * O número da casa no texto digitado: o primeiro número que não faz parte do nome da rua ("Rua 7 de Setembro"),
     * ignorando o CEP. "45a" vira "45A".
     */
    public static function numeroDigitado(string $texto, ?string $rua = null): ?string
    {
        $semCep = (string) preg_replace('/\b\d{5}-?\d{3}\b/', ' ', $texto);
        preg_match_all('/(?<![\w-])(\d{1,5}[A-Za-z]?)(?![\w-])/u', $semCep, $achados);
        $numerosDaRua = $rua && preg_match_all('/\d+/', $rua, $daRua) ? $daRua[0] : [];

        foreach ($achados[1] as $numero) {
            if (!in_array((string) preg_replace('/\D/', '', $numero), $numerosDaRua, true)) {
                return strtoupper($numero);
            }
        }

        return null;
    }

    /** tipo => texto longo; o texto curto fica em "<tipo>_curto" (UF e país). O primeiro componente de cada tipo vale. */
    protected static function componentes(array $componentes): array
    {
        $partes = [];
        foreach ($componentes as $componente) {
            foreach ((array) ($componente['types'] ?? []) as $tipo) {
                if (!array_key_exists($tipo, $partes)) {
                    $partes[$tipo]            = $componente['longText'] ?? null;
                    $partes[$tipo . '_curto'] = $componente['shortText'] ?? null;
                }
            }
        }

        return $partes;
    }

    /** "Shopping Santa Úrsula - Jardim Sumaré, Ribeirão Preto - SP" → "Shopping Santa Úrsula". */
    protected static function primeiraParte(string $formatado): ?string
    {
        $parte = trim(explode(' - ', $formatado)[0]);

        return $parte !== '' ? $parte : null;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php`
Expected: todas as linhas `PASSA` e `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Enderecos/EnderecoBrasileiro.php scripts/teste-php/enderecos.php
git commit -m "Endereços: endereço brasileiro a partir dos detalhes do Google Places"
```

---

### Task 2: Porta HTTP da Places API (New)

**Files:**
- Create: `api/app/Support/Entregas/Enderecos/ErroGooglePlaces.php`, `api/app/Support/Entregas/Enderecos/ClienteGooglePlaces.php`
- Test: `scripts/teste-php/enderecos.php`

- [ ] **Step 1: Escrever o teste (falha)**

Em `scripts/teste-php/enderecos.php`, acrescente os `require` logo depois do `require` do `EnderecoBrasileiro.php`:

```php
require '/repo/api/app/Support/Entregas/Enderecos/ErroGooglePlaces.php';
require '/repo/api/app/Support/Entregas/Enderecos/ClienteGooglePlaces.php';
```

acrescente aos `use`:

```php
use App\Support\Entregas\Enderecos\ClienteGooglePlaces;
use App\Support\Entregas\Enderecos\ErroGooglePlaces;
use Teste\Config;
use Teste\Http;
```

e, antes do `resumo();`, o bloco:

```php
/** Estado limpo, com a chave do Google em Admin → Serviços ($chave vazia = sem chave). */
function reiniciarEnderecos(string $chave = 'chave-teste'): void
{
    reiniciarIfood();
    Config::$valores['services.google_maps.api_key'] = $chave;
}

function previsao(string $id, string $principal, string $secundario): array
{
    return ['placePrediction' => [
        'placeId'          => $id,
        'text'             => ['text' => "{$principal} - {$secundario}"],
        'structuredFormat' => ['mainText' => ['text' => $principal], 'secondaryText' => ['text' => $secundario]],
    ]];
}

echo '== ClienteGooglePlaces' . PHP_EOL;
reiniciarEnderecos('');
confere(!ClienteGooglePlaces::configurado(), 'sem chave em Admin → Serviços: não configurado');
$erro = excecao(fn () => (new ClienteGooglePlaces())->sugestoes('rua olinda', -21.19, -47.79, 'sessao-1'));
confere($erro instanceof ErroGooglePlaces && $erro->status === 0 && Http::$chamadas === [], 'sem chave: erro com status 0, sem chamar o Google');

reiniciarEnderecos();
confere(ClienteGooglePlaces::configurado(), 'com chave: configurado');
Http::responder(200, ['suggestions' => [
    previsao('ChIJolinda45', 'Rua Olinda, 45', 'Jardim Paulista, Ribeirão Preto - SP, Brasil'),
    ['queryPrediction' => ['text' => ['text' => 'rua olinda']]],
]]);
$sugestoes = (new ClienteGooglePlaces())->sugestoes('rua olinda 45', -21.19, -47.79, 'sessao-1');
confere($sugestoes === [['place_id' => 'ChIJolinda45', 'principal' => 'Rua Olinda, 45', 'secundario' => 'Jardim Paulista, Ribeirão Preto - SP, Brasil']], 'sugestões: só as previsões de lugar, com id, linha principal e secundária');
$chamada = Http::$chamadas[0];
confere($chamada['metodo'] === 'POST' && $chamada['url'] === 'https://places.googleapis.com/v1/places:autocomplete', 'sugestões: POST places:autocomplete');
confere(($chamada['headers']['X-Goog-Api-Key'] ?? null) === 'chave-teste', 'sugestões: chave no cabeçalho X-Goog-Api-Key');
confere($chamada['dados']['input'] === 'rua olinda 45' && $chamada['dados']['includedRegionCodes'] === ['br'] && $chamada['dados']['languageCode'] === 'pt-BR', 'sugestões: texto, só Brasil e pt-BR');
confere($chamada['dados']['locationBias'] === ['circle' => ['center' => ['latitude' => -21.19, 'longitude' => -47.79], 'radius' => 30000.0]], 'sugestões: preferência num raio de 30 km da referência');
confere($chamada['dados']['sessionToken'] === 'sessao-1' && $chamada['timeout'] === ClienteGooglePlaces::TEMPO_LIMITE, 'sugestões: token de sessão e tempo limite');

reiniciarEnderecos();
Http::responder(200, detalhesOlinda());
$detalhes = (new ClienteGooglePlaces())->detalhes('ChIJolinda45', 'sessao-1');
confere(($detalhes['id'] ?? null) === 'ChIJolinda45', 'detalhes: devolve o corpo da resposta');
$chamada = Http::$chamadas[0];
confere($chamada['metodo'] === 'GET' && $chamada['url'] === 'https://places.googleapis.com/v1/places/ChIJolinda45', 'detalhes: GET places/{id}');
confere(($chamada['headers']['X-Goog-FieldMask'] ?? null) === 'id,formattedAddress,addressComponents,location', 'detalhes: só os campos usados (FieldMask)');
confere($chamada['dados'] === ['languageCode' => 'pt-BR', 'regionCode' => 'br', 'sessionToken' => 'sessao-1'], 'detalhes: pt-BR, Brasil e o token da sessão');

reiniciarEnderecos();
Http::responder(403, ['error' => ['status' => 'PERMISSION_DENIED']]);
$erro = excecao(fn () => (new ClienteGooglePlaces())->detalhes('ChIJolinda45', 'sessao-1'));
confere($erro instanceof ErroGooglePlaces && $erro->status === 403, 'resposta fora de 2xx: erro com o status');
reiniciarEnderecos();
Http::falharConexao();
$erro = excecao(fn () => (new ClienteGooglePlaces())->sugestoes('rua olinda', -21.19, -47.79, 'sessao-1'));
confere($erro instanceof ErroGooglePlaces && $erro->status === 0, 'falha de rede: erro com status 0');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php`
Expected: erro fatal "Failed opening required '/repo/api/app/Support/Entregas/Enderecos/ErroGooglePlaces.php'".

- [ ] **Step 3: Implementar**

Crie `api/app/Support/Entregas/Enderecos/ErroGooglePlaces.php`:

```php
<?php

namespace App\Support\Entregas\Enderecos;

use RuntimeException;

/** Entregas RestaurantePro: falha na Places API (New) do Google. Status 0 = rede ou chave não configurada. */
class ErroGooglePlaces extends RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem = '')
    {
        parent::__construct($mensagem !== '' ? $mensagem : "Places API respondeu {$status}");
    }
}
```

Crie `api/app/Support/Entregas/Enderecos/ClienteGooglePlaces.php`:

```php
<?php

namespace App\Support\Entregas\Enderecos;

use Closure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Entregas RestaurantePro: única porta HTTP para a Places API (New) do Google (busca de endereço do console e do portal).
 *
 * A chave é a de Admin → Serviços → Google Maps, lida a cada chamada em `services.google_maps.api_key` (o core mescla as
 * configurações do banco a cada requisição; não depende do config:cache). Ela fica no servidor, restrita ao IP da VPS.
 * Sugestões: POST places:autocomplete, só Brasil, pt-BR, com preferência num raio em volta da referência. Detalhes: GET
 * places/{id} só com os campos usados (FieldMask). As duas levam o token de sessão: o Google cobra as sugestões de uma
 * sessão que termina em detalhes como uma busca só.
 *
 * Toda resposta fora de 2xx vira ErroGooglePlaces com o status; falha de rede ou sem chave, status 0. Não registra nada
 * no log (quem chama registra só o status, nunca o texto digitado nem a chave).
 */
class ClienteGooglePlaces
{
    public const BASE = 'https://places.googleapis.com/v1';

    /** Segundos de espera por resposta (a pessoa está digitando). */
    public const TEMPO_LIMITE = 8;

    /** Raio da preferência em volta de quem digita, em metros (o Google aceita até 50 km). */
    public const RAIO_EM_METROS = 30000.0;

    public const CAMPOS_DOS_DETALHES = 'id,formattedAddress,addressComponents,location';

    public static function chave(): string
    {
        return (string) config('services.google_maps.api_key');
    }

    public static function configurado(): bool
    {
        return static::chave() !== '';
    }

    /** @return array<int, array{place_id: string, principal: string, secundario: string}> */
    public function sugestoes(string $texto, float $latitude, float $longitude, string $sessao): array
    {
        $corpo = [
            'input'               => $texto,
            'includedRegionCodes' => ['br'],
            'languageCode'        => 'pt-BR',
            'regionCode'          => 'br',
            'locationBias'        => ['circle' => ['center' => ['latitude' => $latitude, 'longitude' => $longitude], 'radius' => static::RAIO_EM_METROS]],
            'sessionToken'        => $sessao,
        ];

        $resposta = $this->enviar(fn () => Http::withHeaders(['X-Goog-Api-Key' => static::chave()])
            ->acceptJson()
            ->timeout(static::TEMPO_LIMITE)
            ->post(static::BASE . '/places:autocomplete', $corpo));

        $sugestoes = [];
        foreach ((array) $resposta->json('suggestions', []) as $item) {
            $previsao = $item['placePrediction'] ?? null;
            if (!is_array($previsao) || empty($previsao['placeId'])) {
                continue;
            }

            $sugestoes[] = [
                'place_id'   => (string) $previsao['placeId'],
                'principal'  => (string) ($previsao['structuredFormat']['mainText']['text'] ?? $previsao['text']['text'] ?? ''),
                'secundario' => (string) ($previsao['structuredFormat']['secondaryText']['text'] ?? ''),
            ];
        }

        return $sugestoes;
    }

    public function detalhes(string $placeId, string $sessao): array
    {
        $resposta = $this->enviar(fn () => Http::withHeaders([
            'X-Goog-Api-Key'   => static::chave(),
            'X-Goog-FieldMask' => static::CAMPOS_DOS_DETALHES,
        ])
            ->acceptJson()
            ->timeout(static::TEMPO_LIMITE)
            ->get(static::BASE . '/places/' . rawurlencode($placeId), ['languageCode' => 'pt-BR', 'regionCode' => 'br', 'sessionToken' => $sessao]));

        return (array) $resposta->json();
    }

    protected function enviar(Closure $chamada): Response
    {
        if (!static::configurado()) {
            throw new ErroGooglePlaces(0, 'chave do Google não configurada');
        }

        try {
            $resposta = $chamada();
        } catch (ConnectionException|TransferException) {
            throw new ErroGooglePlaces(0, 'falha de rede');
        }

        if (!$resposta->successful()) {
            throw new ErroGooglePlaces($resposta->status());
        }

        return $resposta;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Enderecos/ErroGooglePlaces.php api/app/Support/Entregas/Enderecos/ClienteGooglePlaces.php scripts/teste-php/enderecos.php
git commit -m "Endereços: porta HTTP da Places API (New)"
```

---

### Task 3: Regras da busca (referência, mínimo, falhas, sugestões como Place)

**Files:**
- Create: `api/app/Support/Entregas/Enderecos/BuscaDeEnderecos.php`
- Test: `scripts/teste-php/enderecos.php`

- [ ] **Step 1: Escrever o teste (falha)**

Em `scripts/teste-php/enderecos.php`, acrescente o `require` depois do `ClienteGooglePlaces.php`:

```php
require '/repo/api/app/Support/Entregas/Enderecos/BuscaDeEnderecos.php';
```

o `use`:

```php
use App\Support\Entregas\Enderecos\BuscaDeEnderecos;
```

e, antes do `resumo();`:

```php
echo '== BuscaDeEnderecos' . PHP_EOL;
confere(BuscaDeEnderecos::referencia('-21.2', '-47.8') === [-21.2, -47.8], 'referência: a posição de quem digita');
confere(BuscaDeEnderecos::referencia(null, null, [-21.17, -47.81]) === [-21.17, -47.81], 'referência: sem posição, a loja');
confere(BuscaDeEnderecos::referencia(null, null) === BuscaDeEnderecos::CENTRO_PADRAO, 'referência: sem nada, o centro padrão (Ribeirão Preto)');
confere(BuscaDeEnderecos::referencia('0', '0') === BuscaDeEnderecos::CENTRO_PADRAO, 'referência: 0,0 não vale');
confere(BuscaDeEnderecos::referencia('abc', '1') === BuscaDeEnderecos::CENTRO_PADRAO, 'referência: texto não vale');
confere(BuscaDeEnderecos::referencia('95', '10') === BuscaDeEnderecos::CENTRO_PADRAO, 'referência: latitude fora da faixa não vale');

reiniciarEnderecos();
$busca = new BuscaDeEnderecos(new ClienteGooglePlaces());
confere($busca->sugestoes('ru', [-21.19, -47.79], 'sessao-1') === [] && Http::$chamadas === [], 'menos de 3 caracteres: sem chamar o Google');
reiniciarEnderecos('');
confere((new BuscaDeEnderecos(new ClienteGooglePlaces()))->sugestoes('rua olinda', [-21.19, -47.79], 'sessao-1') === [] && Http::$chamadas === [], 'sem chave: lista vazia, sem chamar o Google');

reiniciarEnderecos();
Http::responder(200, ['suggestions' => array_map(fn ($i) => previsao("ChIJ{$i}", "Rua {$i}", 'Ribeirão Preto - SP'), range(1, 7))]);
$lista = (new BuscaDeEnderecos(new ClienteGooglePlaces()))->sugestoes('  rua olinda 45  ', [-21.19, -47.79], 'sessao-1');
confere(count($lista) === BuscaDeEnderecos::LIMITE_DE_SUGESTOES, 'no máximo 5 sugestões');
confere(Http::$chamadas[0]['dados']['input'] === 'rua olinda 45', 'o texto vai sem os espaços das pontas');

reiniciarEnderecos();
Http::responder(500, 'erro');
confere((new BuscaDeEnderecos(new ClienteGooglePlaces()))->sugestoes('rua olinda 45', [-21.19, -47.79], 'sessao-1') === [], 'Google fora: lista vazia');
confere(logou('[entregas] endereços: sugestões falharam (500)', 'warning'), 'Google fora: log com o status');
confere(logsSem(['rua olinda', 'chave-teste']), 'o log não leva o texto digitado nem a chave');

reiniciarEnderecos();
Http::responder(200, detalhesOlinda(['numero']));
$detalhes = (new BuscaDeEnderecos(new ClienteGooglePlaces()))->detalhes('ChIJolinda45', 'sessao-1', 'rua olinda 45');
confere(($detalhes['street1'] ?? null) === 'Rua Olinda, 45' && ($detalhes['city'] ?? null) === 'Ribeirão Preto', 'detalhes: endereço brasileiro, com o número digitado');

reiniciarEnderecos();
$semLocal = detalhesOlinda();
unset($semLocal['location']);
Http::responder(200, $semLocal);
confere((new BuscaDeEnderecos(new ClienteGooglePlaces()))->detalhes('ChIJolinda45', 'sessao-1', null) === null, 'detalhes sem coordenadas: falha (null)');
confere(logou('[entregas] endereços: detalhes sem coordenadas', 'warning'), 'detalhes sem coordenadas: log');

reiniciarEnderecos();
Http::responder(404, ['error' => ['status' => 'NOT_FOUND']]);
confere((new BuscaDeEnderecos(new ClienteGooglePlaces()))->detalhes('ChIJolinda45', 'sessao-1', null) === null, 'detalhes com erro do Google: null');
confere(logou('[entregas] endereços: detalhes falharam (404)', 'warning'), 'detalhes com erro do Google: log com o status');

$locais = BuscaDeEnderecos::comoLocais([['place_id' => 'ChIJolinda45', 'principal' => 'Rua Olinda, 45', 'secundario' => 'Jardim Paulista, Ribeirão Preto - SP, Brasil']], 'sessao-1', 'rua olinda 45');
confere($locais === [[
    'street1' => 'Rua Olinda, 45',
    'street2' => 'Jardim Paulista, Ribeirão Preto - SP, Brasil',
    'meta'    => ['entregas_sugestao' => ['place_id' => 'ChIJolinda45', 'sessao' => 'sessao-1', 'texto' => 'rua olinda 45']],
]], 'sugestões como Place para o campo do pedido, marcadas em meta.entregas_sugestao');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php`
Expected: erro fatal "Failed opening required '/repo/api/app/Support/Entregas/Enderecos/BuscaDeEnderecos.php'".

- [ ] **Step 3: Implementar**

Crie `api/app/Support/Entregas/Enderecos/BuscaDeEnderecos.php`:

```php
<?php

namespace App\Support\Entregas\Enderecos;

use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: regras da busca de endereço pelo Google Places (EnderecosController).
 *
 * - referência (centro da preferência): a posição de quem digita; no portal, o Local da loja; senão, o centro padrão
 *   do mapa (Ribeirão Preto, o mesmo fallback do console);
 * - menos de MINIMO_DE_CARACTERES ou sem chave em Admin → Serviços: lista vazia, sem chamar o Google;
 * - falha do Google: lista vazia (sugestões) ou null (detalhes), com log só do status; a tela segue com o mapa;
 * - detalhes sem coordenadas valem como falha (o local precisa do ponto).
 */
class BuscaDeEnderecos
{
    public const MINIMO_DE_CARACTERES = 3;

    public const MAXIMO_DE_CARACTERES = 200;

    public const LIMITE_DE_SUGESTOES = 5;

    /** Centro padrão do mapa no Entregas (Ribeirão Preto). */
    public const CENTRO_PADRAO = [-21.1775, -47.8103];

    public function __construct(protected ClienteGooglePlaces $google)
    {
    }

    /** [latitude, longitude] da preferência: a posição enviada, a da loja ou o centro padrão. */
    public static function referencia($latitude, $longitude, ?array $daLoja = null): array
    {
        if (static::coordenadaValida($latitude, $longitude)) {
            return [(float) $latitude, (float) $longitude];
        }

        if ($daLoja && static::coordenadaValida($daLoja[0] ?? null, $daLoja[1] ?? null)) {
            return [(float) $daLoja[0], (float) $daLoja[1]];
        }

        return static::CENTRO_PADRAO;
    }

    /** @return array<int, array{place_id: string, principal: string, secundario: string}> */
    public function sugestoes(string $texto, array $referencia, string $sessao): array
    {
        $texto = trim($texto);
        if (mb_strlen($texto) < static::MINIMO_DE_CARACTERES || !ClienteGooglePlaces::configurado()) {
            return [];
        }

        try {
            $sugestoes = $this->google->sugestoes(mb_substr($texto, 0, static::MAXIMO_DE_CARACTERES), $referencia[0], $referencia[1], $sessao);
        } catch (ErroGooglePlaces $erro) {
            Log::warning("[entregas] endereços: sugestões falharam ({$erro->status})");

            return [];
        }

        return array_slice($sugestoes, 0, static::LIMITE_DE_SUGESTOES);
    }

    /** Atributos do Place (EnderecoBrasileiro) ou null quando o Google falha ou não traz o ponto. */
    public function detalhes(string $placeId, string $sessao, ?string $textoDigitado): ?array
    {
        try {
            $endereco = EnderecoBrasileiro::deDetalhes($this->google->detalhes($placeId, $sessao), $textoDigitado);
        } catch (ErroGooglePlaces $erro) {
            Log::warning("[entregas] endereços: detalhes falharam ({$erro->status})");

            return null;
        }

        if ($endereco['location'] === null) {
            Log::warning('[entregas] endereços: detalhes sem coordenadas');

            return null;
        }

        return $endereco;
    }

    /**
     * As sugestões no formato de Place para o ModelSelect do pedido (rota busca): rua na primeira linha, o resto na
     * segunda, e a marca meta.entregas_sugestao, que o console troca pelos detalhes quando a central escolhe.
     */
    public static function comoLocais(array $sugestoes, string $sessao, string $texto): array
    {
        return array_map(fn (array $sugestao) => [
            'street1' => $sugestao['principal'],
            'street2' => $sugestao['secundario'],
            'meta'    => ['entregas_sugestao' => ['place_id' => $sugestao['place_id'], 'sessao' => $sessao, 'texto' => $texto]],
        ], $sugestoes);
    }

    protected static function coordenadaValida($latitude, $longitude): bool
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $latitude  = (float) $latitude;
        $longitude = (float) $longitude;

        return abs($latitude) <= 90 && abs($longitude) <= 180 && !($latitude == 0.0 && $longitude == 0.0);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Enderecos/BuscaDeEnderecos.php scripts/teste-php/enderecos.php
git commit -m "Endereços: regras da busca (referência, mínimo, falhas, sugestões como Place)"
```

---

### Task 4: Rotas, limitador e liberação no portal

**Files:**
- Create: `api/app/Http/Controllers/Entregas/EnderecosController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php` (limitadores, perto da linha 96; grupo `int/v1/entregas`, depois das rotas do chat da loja)
- Modify: `api/app/Http/Middleware/ProtegerPortalLoja.php:51-64` (`PERMITIDAS_INTERNAS`)
- Test: `scripts/teste-php/enderecos.php`

- [ ] **Step 1: Escrever o teste (falha)**

Em `scripts/teste-php/enderecos.php`, acrescente o `require` depois do `BuscaDeEnderecos.php`:

```php
require '/repo/api/app/Http/Middleware/ProtegerPortalLoja.php';
```

o `use`:

```php
use App\Http\Middleware\ProtegerPortalLoja;
```

e, antes do `resumo();`:

```php
echo '== Portal da loja e rotas' . PHP_EOL;
function liberadaNoPortal(string $metodoECaminho): bool
{
    foreach (ProtegerPortalLoja::PERMITIDAS_INTERNAS as $padrao) {
        if (preg_match($padrao, $metodoECaminho)) {
            return true;
        }
    }

    return false;
}
confere(liberadaNoPortal('GET entregas/enderecos/sugestoes'), 'portal: sugestões liberadas');
confere(liberadaNoPortal('GET entregas/enderecos/detalhes/ChIJolinda45'), 'portal: detalhes liberados');
confere(!liberadaNoPortal('GET entregas/enderecos/busca'), 'portal: a busca com os locais salvos da empresa é só da central');
confere(!liberadaNoPortal('POST entregas/enderecos/sugestoes'), 'portal: só GET');
confere(!liberadaNoPortal('GET entregas/enderecos/detalhes/a/b'), 'portal: detalhes com um id só');

$rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
confere(str_contains($rotas, "RateLimiter::for('entregas-enderecos'"), 'limitador entregas-enderecos');
foreach (["'enderecos/sugestoes'", "'enderecos/detalhes/{placeId}'", "'enderecos/busca'"] as $rota) {
    confere(str_contains($rotas, $rota), "rota {$rota}");
}
confere(str_contains($rotas, "->middleware('throttle:entregas-enderecos')"), 'rotas com o limitador');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php`
Expected: `FALHA portal: sugestões liberadas`, `FALHA portal: detalhes liberados`, `FALHA limitador entregas-enderecos` e as três rotas em FALHA.

- [ ] **Step 3: Implementar o controller**

Crie `api/app/Http/Controllers/Entregas/EnderecosController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ProtegerPortalLoja;
use App\Support\Entregas\Enderecos\BuscaDeEnderecos;
use App\Support\Entregas\LojaDoUsuario;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\PlaceSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: busca de endereço pelo Google Places (New), no console e no portal da loja.
 *
 * - GET int/v1/entregas/enderecos/sugestoes?texto=&latitude=&longitude=&sessao= → [{place_id, principal, secundario}]
 * - GET int/v1/entregas/enderecos/detalhes/{placeId}?sessao=&texto= → atributos do Place no formato brasileiro (502 se o
 *   Google falhar ou não trouxer o ponto)
 * - GET int/v1/entregas/enderecos/busca?query=&latitude=&longitude=&sessao= → campo do pedido: locais salvos da empresa
 *   (PlaceSearch do Fleet-Ops, sem o Geocoding) seguidos das sugestões marcadas em meta.entregas_sugestao. Só da central:
 *   o ProtegerPortalLoja não a libera ao usuário de loja.
 *
 * Usuário de loja: a referência é sempre o Local da loja (a posição enviada não vale).
 */
class EnderecosController extends Controller
{
    /** Id de lugar do Google (letras, números, - e _). */
    public const FORMATO_DO_PLACE_ID = '/^[A-Za-z0-9_-]{10,300}$/';

    /** Token de sessão vindo da tela; fora do formato, o servidor gera um. */
    public const FORMATO_DA_SESSAO = '/^[A-Za-z0-9_-]{8,64}$/';

    public const LOCAIS_SALVOS_NA_BUSCA = 10;

    public function sugestoes(Request $request, BuscaDeEnderecos $busca)
    {
        [$texto, $sessao] = $this->textoESessao($request, 'texto');

        return response()->json($busca->sugestoes($texto, $this->referencia($request), $sessao));
    }

    public function detalhes(Request $request, string $placeId, BuscaDeEnderecos $busca)
    {
        if (!preg_match(static::FORMATO_DO_PLACE_ID, $placeId)) {
            return response()->json(['errors' => ['Endereço inválido.']], 422);
        }

        [$texto, $sessao] = $this->textoESessao($request, 'texto');
        $endereco         = $busca->detalhes($placeId, $sessao, $texto !== '' ? $texto : null);

        if ($endereco === null) {
            return response()->json(['errors' => ['Não foi possível carregar o endereço. Marque o ponto no mapa.']], 502);
        }

        return response()->json($endereco);
    }

    public function busca(Request $request, BuscaDeEnderecos $busca)
    {
        [$texto, $sessao] = $this->textoESessao($request, 'query');

        $consulta = Place::where('company_uuid', session('company'))->whereNull('deleted_at');
        $salvos   = PlaceSearch::search($consulta, $texto !== '' ? $texto : null, [
            'geo'            => false,
            'limit'          => static::LOCAIS_SALVOS_NA_BUSCA,
            'latitude'       => $request->input('latitude'),
            'longitude'      => $request->input('longitude'),
            'no_query_order' => 'name_desc',
        ]);

        $locais    = array_values(PlaceResource::collection($salvos)->resolve($request));
        $sugestoes = BuscaDeEnderecos::comoLocais($busca->sugestoes($texto, $this->referencia($request), $sessao), $sessao, $texto);

        return response()->json(array_merge($locais, $sugestoes));
    }

    /** [texto sem espaços nas pontas, token de sessão válido]. */
    protected function textoESessao(Request $request, string $campo): array
    {
        $texto  = trim((string) $request->input($campo, ''));
        $sessao = (string) $request->input('sessao', '');
        if (!preg_match(static::FORMATO_DA_SESSAO, $sessao)) {
            $sessao = (string) Str::uuid();
        }

        return [$texto, $sessao];
    }

    protected function referencia(Request $request): array
    {
        if ($request->attributes->get(ProtegerPortalLoja::ATRIBUTO_USUARIO)) {
            $coleta = LojaDoUsuario::coleta(LojaDoUsuario::vendor(session('user')));
            $ponto  = $coleta?->location;

            return BuscaDeEnderecos::referencia(null, null, $ponto ? [$ponto->getLat(), $ponto->getLng()] : null);
        }

        return BuscaDeEnderecos::referencia($request->input('latitude'), $request->input('longitude'));
    }
}
```

- [ ] **Step 4: Rotas e limitador**

Em `api/app/Providers/RouteServiceProvider.php`:

1. no topo, junto dos outros `use App\Http\Controllers\Entregas\...`, acrescente `use App\Http\Controllers\Entregas\EnderecosController;` (em ordem alfabética);
2. depois da linha do limitador `entregas-lider` (`RateLimiter::for('entregas-lider', ...)`), acrescente:

```php
        // busca de endereço pelo Google Places (console e portal): até 120 por minuto por usuário (a tela já espera 300 ms
        // entre teclas; o teto segura um script)
        RateLimiter::for('entregas-enderecos', fn (Request $request) => Limit::perMinute(120)->by('entregas-enderecos:' . (session('user') ?: $request->ip())));
```

3. dentro do grupo `Route::prefix('int/v1/entregas')`, depois do bloco do chat da loja (`Route::middleware('throttle:entregas-loja-conversas')->group(...)`), acrescente:

```php
                        // busca de endereço pelo Google Places (EnderecosController): sugestões e detalhes valem para a central e
                        // para a loja (ProtegerPortalLoja); a busca com os locais salvos é só da central
                        Route::get('enderecos/sugestoes', [EnderecosController::class, 'sugestoes'])->middleware('throttle:entregas-enderecos');
                        Route::get('enderecos/detalhes/{placeId}', [EnderecosController::class, 'detalhes'])->middleware('throttle:entregas-enderecos');
                        Route::get('enderecos/busca', [EnderecosController::class, 'busca'])->middleware('throttle:entregas-enderecos');
```

- [ ] **Step 5: Liberar no portal**

Em `api/app/Http/Middleware/ProtegerPortalLoja.php`, no array `PERMITIDAS_INTERNAS`, depois da linha `'#^[A-Z]+ entregas/loja/.+$#',`, acrescente:

```php
        // busca de endereço pelo Google (EnderecosController); a rota busca lista locais salvos da empresa toda e fica fora
        '#^GET entregas/enderecos/(sugestoes|detalhes/[A-Za-z0-9_-]+)$#',
```

- [ ] **Step 6: Rodar os testes e a sintaxe**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/EnderecosController.php api/app/Providers/RouteServiceProvider.php api/app/Http/Middleware/ProtegerPortalLoja.php api/app/Support/Entregas/Enderecos/*.php`
Expected: `OK` em todos.

- [ ] **Step 7: Commit**

```bash
git add api/app/Http/Controllers/Entregas/EnderecosController.php api/app/Providers/RouteServiceProvider.php api/app/Http/Middleware/ProtegerPortalLoja.php scripts/teste-php/enderecos.php
git commit -m "Endereços: rotas sugestões, detalhes e busca; limitador e liberação no portal"
```

---

### Task 5: Funções do front e exibição no formato brasileiro

**Files:**
- Create: `packages/ember-ui/addon/utils/endereco-brasileiro.js`, `scripts/teste-portal/endereco-brasileiro.test.mjs`
- Modify: `packages/ember-ui/addon/utils/place-address.js`, `packages/fleetops/addon/utils/place-address-html.js`

- [ ] **Step 1: Escrever o teste (falha)**

Crie `scripts/teste-portal/endereco-brasileiro.test.mjs`:

```js
// Busca de endereço pelo Google (packages/ember-ui/addon/utils/endereco-brasileiro.js): exibição no formato brasileiro e
// a marca das sugestões no campo do pedido.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MINIMO_DE_CARACTERES, dadosDaSugestao, ehSugestaoDoGoogle, linhaDoBairroECidade, novaSessaoDeBusca, temTextoParaBuscar } from '../../packages/ember-ui/addon/utils/endereco-brasileiro.js';

test('linha do bairro e da cidade', () => {
    assert.equal(linhaDoBairroECidade({ neighborhood: 'Jardim Paulista', city: 'Ribeirão Preto', province: 'SP', postal_code: '14025-150' }), 'Jardim Paulista, Ribeirão Preto - SP, 14025-150');
    assert.equal(linhaDoBairroECidade({ city: 'Ribeirão Preto', postal_code: '14025-150' }), 'Ribeirão Preto, 14025-150');
    assert.equal(linhaDoBairroECidade({ neighborhood: ' ', city: 'Ribeirão Preto', province: 'SP' }), 'Ribeirão Preto - SP');
    assert.equal(linhaDoBairroECidade({ province: 'SP' }), 'SP');
    assert.equal(linhaDoBairroECidade({}), '');
    assert.equal(linhaDoBairroECidade(null), '');
});

test('texto para buscar: mínimo de caracteres sem os espaços', () => {
    assert.equal(MINIMO_DE_CARACTERES, 3);
    assert.equal(temTextoParaBuscar('rua'), true);
    assert.equal(temTextoParaBuscar('  ru  '), false);
    assert.equal(temTextoParaBuscar(undefined), false);
});

test('sugestão do Google marcada em meta.entregas_sugestao', () => {
    const sugestao = { street1: 'Rua Olinda, 45', meta: { entregas_sugestao: { place_id: 'ChIJolinda45', sessao: 's-1', texto: 'rua olinda 45' } } };
    assert.deepEqual(dadosDaSugestao(sugestao), { place_id: 'ChIJolinda45', sessao: 's-1', texto: 'rua olinda 45' });
    assert.equal(ehSugestaoDoGoogle(sugestao), true);
    assert.equal(ehSugestaoDoGoogle({ street1: 'Rua Olinda, 45', meta: {} }), false);
    assert.equal(ehSugestaoDoGoogle({ meta: { entregas_sugestao: { place_id: '' } } }), false);
    assert.equal(ehSugestaoDoGoogle(null), false);
});

test('sessão de busca: texto aceito pelo servidor e nova a cada chamada', () => {
    const primeira = novaSessaoDeBusca();
    assert.match(primeira, /^[A-Za-z0-9_-]{8,64}$/);
    assert.notEqual(novaSessaoDeBusca(), primeira);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/endereco-brasileiro.test.mjs`
Expected: falha com "Cannot find module ... endereco-brasileiro.js".

- [ ] **Step 3: Implementar o util**

Crie `packages/ember-ui/addon/utils/endereco-brasileiro.js`:

```js
// Entregas RestaurantePro: endereço no formato brasileiro e as sugestões do Google na busca de endereço
// (EnderecoGoogleInput e o campo do pedido). Funções puras, sem import do Ember (testadas no Node:
// scripts/teste-portal/endereco-brasileiro.test.mjs).

/** O servidor só consulta o Google a partir de 3 caracteres (BuscaDeEnderecos::MINIMO_DE_CARACTERES). */
export const MINIMO_DE_CARACTERES = 3;

function preenchido(valor) {
    return valor !== null && valor !== undefined && String(valor).trim() !== '';
}

/** "Jardim Paulista, Ribeirão Preto - SP, 14025-150": bairro, cidade com a UF e CEP, o que existir. */
export function linhaDoBairroECidade(place) {
    if (!place) {
        return '';
    }

    const cidade = [place.city, place.province]
        .filter(preenchido)
        .map((valor) => String(valor).trim())
        .join(' - ');

    return [place.neighborhood, cidade, place.postal_code]
        .filter(preenchido)
        .map((valor) => String(valor).trim())
        .join(', ');
}

export function temTextoParaBuscar(texto) {
    return typeof texto === 'string' && texto.trim().length >= MINIMO_DE_CARACTERES;
}

/** {place_id, sessao, texto} da sugestão do Google no campo do pedido (EnderecosController@busca), ou null. */
export function dadosDaSugestao(place) {
    const dados = place?.meta?.entregas_sugestao;

    return dados && typeof dados.place_id === 'string' && dados.place_id !== '' ? dados : null;
}

export function ehSugestaoDoGoogle(place) {
    return dadosDaSugestao(place) !== null;
}

/** Token de sessão do Google: uma busca (várias teclas) até a escolha de um endereço. */
export function novaSessaoDeBusca() {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    let texto = '';
    for (let i = 0; i < 32; i++) {
        texto += Math.floor(Math.random() * 16).toString(16);
    }

    return texto;
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/endereco-brasileiro.test.mjs`
Expected: `# pass 4`, `# fail 0`.

- [ ] **Step 5: Exibição nos dois formatadores**

Em `packages/ember-ui/addon/utils/place-address.js`:
- acrescente o import depois do `import { isBlank } from '@ember/utils';`:

```js
import { linhaDoBairroECidade } from './endereco-brasileiro';
```

- troque a linha

```js
    const cityStatePostalCode = [address.city, address.province, address.postal_code].filter((value) => !isBlank(value)).join(', ');
```

por

```js
    // Entregas: "Jardim Paulista, Ribeirão Preto - SP, 14025-150" (bairro, cidade - UF, CEP)
    const cityStatePostalCode = linhaDoBairroECidade(address);
```

- se o `isBlank` deixar de ser usado no arquivo, remova o import dele.

Em `packages/fleetops/addon/utils/place-address-html.js`:
- acrescente o import depois do `import { isBlank } from '@ember/utils';`:

```js
import { linhaDoBairroECidade } from '@fleetbase/ember-ui/utils/endereco-brasileiro';
```

- troque a linha

```js
    const cityStatePostalCode = [place.city, place.province, place.postal_code].filter((value) => !isBlank(value)).join(', ');
```

por

```js
    // Entregas: "Jardim Paulista, Ribeirão Preto - SP, 14025-150" (bairro, cidade - UF, CEP)
    const cityStatePostalCode = linhaDoBairroECidade(place);
```

- se o `isBlank` deixar de ser usado no arquivo, remova o import dele.

- [ ] **Step 6: Conferir o parse dos JS alterados**

Run:

```bash
node -e "const fs=require('fs');const d=fs.readdirSync('console/node_modules/.pnpm').find(n=>n.startsWith('@babel+parser@7'));const p=require('./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser');for(const f of process.argv.slice(1)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('OK',f)}" packages/ember-ui/addon/utils/endereco-brasileiro.js packages/ember-ui/addon/utils/place-address.js packages/fleetops/addon/utils/place-address-html.js
```

Expected: `OK` nos três.

- [ ] **Step 7: Commit**

```bash
git add packages/ember-ui/addon/utils/endereco-brasileiro.js packages/ember-ui/addon/utils/place-address.js packages/fleetops/addon/utils/place-address-html.js scripts/teste-portal/endereco-brasileiro.test.mjs
git commit -m "Endereços: funções do front e linha bairro, cidade - UF, CEP na exibição"
```

---

### Task 6: Componente `EnderecoGoogleInput` e textos

**Files:**
- Create: `packages/ember-ui/addon/components/endereco-google-input.js`, `packages/ember-ui/addon/components/endereco-google-input.hbs`, `packages/ember-ui/app/components/endereco-google-input.js`
- Modify: `console/translations/en-us.yaml`, `console/translations/pt-br.yaml` (bloco `ember-ui:`)

- [ ] **Step 1: Componente (JS)**

Crie `packages/ember-ui/addon/components/endereco-google-input.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { later } from '@ember/runloop';
import { dropTask, restartableTask, timeout } from 'ember-concurrency';
import { novaSessaoDeBusca, temTextoParaBuscar } from '../utils/endereco-brasileiro';

/**
 * Entregas RestaurantePro: campo de rua com as sugestões do Google Places (GET int/v1/entregas/enderecos/sugestoes).
 * Ao escolher, busca os detalhes (rua e número, bairro, cidade, UF, CEP e o ponto em GeoJSON) e os entrega ao @onSelect.
 *
 * Argumentos: @value (o texto da rua, ligado ao campo), @onSelect(endereco), @latitude/@longitude (posição de quem
 * digita; sem elas o servidor usa a loja ou o centro padrão), @placeholder, @disabled, @wrapperClass.
 */
export default class EnderecoGoogleInputComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked sugestoes = [];
    @tracked aberto = false;

    sessao = novaSessaoDeBusca();
    ultimoTexto = '';

    get carregando() {
        return this.buscar.isRunning || this.escolher.isRunning;
    }

    @action aoDigitar(event) {
        this.buscar.perform(event.target.value);
    }

    @action aoEntrar() {
        this.aberto = this.sugestoes.length > 0;
    }

    @action aoSair() {
        // o mousedown da sugestão vem antes do blur; a espera só fecha a lista sem perder o clique
        later(this, () => (this.aberto = false), 200);
    }

    @action selecionar(sugestao, event) {
        event?.preventDefault();
        this.escolher.perform(sugestao);
    }

    @restartableTask *buscar(texto) {
        this.ultimoTexto = texto ?? '';
        if (!temTextoParaBuscar(texto)) {
            this.sugestoes = [];
            this.aberto = false;
            return;
        }

        yield timeout(300);

        const parametros = { texto: texto.trim(), sessao: this.sessao };
        if (this.args.latitude && this.args.longitude) {
            parametros.latitude = this.args.latitude;
            parametros.longitude = this.args.longitude;
        }

        try {
            const resultado = yield this.fetch.get('entregas/enderecos/sugestoes', parametros);
            this.sugestoes = Array.isArray(resultado) ? resultado : [];
        } catch {
            this.sugestoes = [];
        }
        this.aberto = this.sugestoes.length > 0;
    }

    @dropTask *escolher(sugestao) {
        this.aberto = false;

        try {
            const endereco = yield this.fetch.get(`entregas/enderecos/detalhes/${encodeURIComponent(sugestao.place_id)}`, {
                sessao: this.sessao,
                texto: this.ultimoTexto.trim(),
            });
            this.sessao = novaSessaoDeBusca();
            this.sugestoes = [];

            if (typeof this.args.onSelect === 'function') {
                this.args.onSelect(endereco);
            }
        } catch (error) {
            this.notifications.serverError(error, this.intl.t('ember-ui.endereco-google-input.erro'));
        }
    }
}
```

- [ ] **Step 2: Componente (template)**

Crie `packages/ember-ui/addon/components/endereco-google-input.hbs`:

```hbs
<div class="entregas-endereco-google relative {{@wrapperClass}}">
    <div class="relative">
        <Input
            @type="text"
            @value={{@value}}
            class="form-input w-full"
            placeholder={{@placeholder}}
            disabled={{@disabled}}
            autocomplete="off"
            {{on "input" this.aoDigitar}}
            {{on "focus" this.aoEntrar}}
            {{on "blur" this.aoSair}}
        />
        {{#if this.carregando}}
            <div class="absolute inset-y-0 right-0 h-full w-10 pr-1 flex items-center justify-center">
                <Spinner @iconClass="text-sky-500 fa-spin-800ms" />
            </div>
        {{/if}}
    </div>

    {{#if this.aberto}}
        <div class="absolute z-50 w-full mt-1 rounded-md bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm">
            {{#each this.sugestoes as |sugestao|}}
                <a
                    href="javascript:;"
                    class="flex flex-col px-3 py-2 border-b border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700"
                    {{on "mousedown" (fn this.selecionar sugestao)}}
                >
                    <span class="font-semibold truncate">{{sugestao.principal}}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400 truncate">{{sugestao.secundario}}</span>
                </a>
            {{/each}}
            <div class="px-3 py-1 text-right text-[10px] text-gray-400">{{t "ember-ui.endereco-google-input.atribuicao"}}</div>
        </div>
    {{/if}}
</div>
```

- [ ] **Step 3: Exportar para o app**

Crie `packages/ember-ui/app/components/endereco-google-input.js`:

```js
export { default } from '@fleetbase/ember-ui/components/endereco-google-input';
```

- [ ] **Step 4: Textos**

Em `console/translations/en-us.yaml`, dentro do bloco `ember-ui:` (2 espaços de recuo), logo antes da chave `  coordinates-input:`, acrescente:

```yaml
  endereco-google-input:
    atribuicao: Powered by Google
    erro: Could not load the address. Mark the point on the map.
```

Em `console/translations/pt-br.yaml`, no mesmo lugar (antes de `  coordinates-input:` do bloco `ember-ui:`):

```yaml
  endereco-google-input:
    atribuicao: Sugestões do Google
    erro: Não foi possível carregar o endereço. Marque o ponto no mapa.
```

- [ ] **Step 5: Conferir parse e traduções**

Run (parse):

```bash
node -e "const fs=require('fs');const d=fs.readdirSync('console/node_modules/.pnpm').find(n=>n.startsWith('@babel+parser@7'));const p=require('./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser');for(const f of process.argv.slice(1)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('OK',f)}" packages/ember-ui/addon/components/endereco-google-input.js packages/ember-ui/app/components/endereco-google-input.js
```

Expected: `OK` nos dois.

Run (traduções): `node scripts/i18n-check.cjs console ember-ui`
Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add packages/ember-ui/addon/components/endereco-google-input.js packages/ember-ui/addon/components/endereco-google-input.hbs packages/ember-ui/app/components/endereco-google-input.js console/translations/en-us.yaml console/translations/pt-br.yaml
git commit -m "Endereços: campo EnderecoGoogleInput com as sugestões do Google"
```

---

### Task 7: "Rua 1" do cadastro de local (console)

**Files:**
- Modify: `packages/fleetops/addon/components/place/form.hbs:7-19`, `packages/fleetops/addon/components/place/form.js`

- [ ] **Step 1: Template**

Em `packages/fleetops/addon/components/place/form.hbs`, troque o bloco da "Rua 1":

```hbs
            <InputGroup @name={{t "place.fields.street-1"}} @value={{@resource.street1}} @wrapperClass="col-span-3">
                <AutocompleteInput
                    @value={{@resource.street1}}
                    @fetchUrl="places/lookup"
                    @onSelect={{this.onAutocomplete}}
                    disabled={{cannot-write @resource}}
                    placeholder={{t "place.fields.street-1"}}
                    class="w-full"
                    as |result|
                >
                    {{result.address}}
                </AutocompleteInput>
            </InputGroup>
```

por:

```hbs
            <InputGroup @name={{t "place.fields.street-1"}} @wrapperClass="col-span-3">
                {{! Entregas: sugestões do Google Places (perto de quem digita) e o endereço no formato brasileiro }}
                <EnderecoGoogleInput
                    @value={{@resource.street1}}
                    @latitude={{this.posicao.latitude}}
                    @longitude={{this.posicao.longitude}}
                    @onSelect={{this.onAutocomplete}}
                    @disabled={{cannot-write @resource}}
                    @placeholder={{t "place.fields.street-1"}}
                />
            </InputGroup>
```

- [ ] **Step 2: JS**

Substitua `packages/fleetops/addon/components/place/form.js` por:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { getOwner } from '@ember/application';

export default class PlaceFormComponent extends Component {
    @tracked coordinatesInput;

    // Entregas: posição de quem digita para a busca do Google. O formulário também abre em modais fora do engine, onde
    // o serviço location pode não existir: sem ele, o servidor usa o centro padrão.
    get posicao() {
        const location = getOwner(this)?.lookup('service:location');

        return location ? { latitude: location.latitude, longitude: location.longitude } : null;
    }

    @action onAutocomplete(selected) {
        this.args.resource.setProperties({ ...selected });

        if (this.coordinatesInput && selected.location) {
            this.coordinatesInput.updateCoordinates(selected.location);
        }
    }
}
```

- [ ] **Step 3: Conferir o parse**

Run:

```bash
node -e "const fs=require('fs');const d=fs.readdirSync('console/node_modules/.pnpm').find(n=>n.startsWith('@babel+parser@7'));const p=require('./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser');for(const f of process.argv.slice(1)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('OK',f)}" packages/fleetops/addon/components/place/form.js
```

Expected: `OK`.

- [ ] **Step 4: Commit**

```bash
git add packages/fleetops/addon/components/place/form.hbs packages/fleetops/addon/components/place/form.js
git commit -m "Endereços: Rua 1 do cadastro de local com as sugestões do Google"
```

---

### Task 8: "Rua 1" do novo endereço do portal

**Files:**
- Modify: `packages/customer-portal/addon/components/modals/portal-order-place-form.hbs:8-33`, `packages/customer-portal/addon/components/modals/portal-order-place-form.js`

- [ ] **Step 1: Template**

Em `packages/customer-portal/addon/components/modals/portal-order-place-form.hbs`, troque o `InputGroup` da rua (de `<InputGroup @name={{t "customer-portal.ui.place.street1"}}` até o `</InputGroup>` que fecha o bloco `portal-place-lookup`) por:

```hbs
            <InputGroup @name={{t "customer-portal.ui.place.street1"}} @wrapperClass="col-span-3">
                {{! Entregas: sugestões do Google Places perto da loja (o servidor usa o Local da loja como referência) }}
                <EnderecoGoogleInput @value={{this.place.street1}} @onSelect={{this.onAutocomplete}} @placeholder={{t "customer-portal.ui.place.street1"}} />
            </InputGroup>
```

- [ ] **Step 2: JS**

Substitua `packages/customer-portal/addon/components/modals/portal-order-place-form.js` por:

```js
import Component from '@glimmer/component';
import { action, setProperties } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class ModalsPortalOrderPlaceFormComponent extends Component {
    @tracked coordinatesInput;

    get place() {
        const place = this.args.options.place;

        if (place && typeof place.setProperties !== 'function') {
            place.setProperties = (properties = {}) => setProperties(place, properties);
        }

        return place;
    }

    // Entregas: endereço escolhido nas sugestões do Google (EnderecoGoogleInput): rua e número, bairro, cidade, UF, CEP
    // e o ponto no mapa
    @action onAutocomplete(selected) {
        this.place.setProperties({ ...selected });

        if (this.coordinatesInput && selected.location) {
            this.coordinatesInput.updateCoordinates(selected.location);
        }
    }
}
```

- [ ] **Step 3: Conferir que não sobrou uso do que saiu**

Run: `grep -rn "lookupResults\|lookupPlaces\|searchStreet\|hasLookupResults" packages/customer-portal/addon`
Expected: nenhuma linha.

- [ ] **Step 4: Conferir o parse e as traduções**

Run:

```bash
node -e "const fs=require('fs');const d=fs.readdirSync('console/node_modules/.pnpm').find(n=>n.startsWith('@babel+parser@7'));const p=require('./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser');for(const f of process.argv.slice(1)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('OK',f)}" packages/customer-portal/addon/components/modals/portal-order-place-form.js
```

Expected: `OK`.

Run: `node scripts/i18n-check.cjs customer-portal`
Expected: exit 0. Se o `customer-portal.ui.place.searching` passar a ser chave sem uso e o check reclamar, remova-a dos dois YAML do customer-portal.

- [ ] **Step 5: Commit**

```bash
git add packages/customer-portal/addon/components/modals/portal-order-place-form.hbs packages/customer-portal/addon/components/modals/portal-order-place-form.js
git commit -m "Endereços: novo endereço do portal com as sugestões do Google"
```

---

### Task 9: Campo Entrega/Coleta/Retorno/paradas do novo pedido

**Files:**
- Modify: `packages/fleetops/addon/components/order/form/route.hbs` (os 4 `ModelSelect` com `@customSearchEndpoint="places/search"`)
- Modify: `packages/fleetops/addon/components/order/form/route.js`
- Modify: `packages/fleetops/translations/en-us.yaml`, `packages/fleetops/translations/pt-br.yaml` (bloco `fleet-ops.ui.order-form`)

- [ ] **Step 1: Template**

Em `packages/fleetops/addon/components/order/form/route.hbs`, nos **quatro** `ModelSelect` de local (parada, coleta, entrega e retorno), troque as duas linhas

```hbs
@customSearchEndpoint="places/search"
@query={{hash geo=true latitude=this.location.latitude longitude=this.location.longitude}}
```

por

```hbs
@customSearchEndpoint="entregas/enderecos/busca"
@query={{hash latitude=this.location.latitude longitude=this.location.longitude sessao=this.sessaoDaBusca}}
```

(mantendo o recuo de cada um) e troque os `@onChange`:

| antes | depois |
|---|---|
| `@onChange={{fn this.setWaypointPlace index}}` | `@onChange={{fn this.escolherLocalDaParada index}}` |
| `@onChange={{fn this.setPayloadPlace "pickup"}}` (no `ModelSelect` editável, fora do `@disabled={{true}}`) | `@onChange={{fn this.escolherLocal "pickup"}}` |
| `@onChange={{fn this.setPayloadPlace "dropoff"}}` | `@onChange={{fn this.escolherLocal "dropoff"}}` |
| `@onChange={{fn this.setPayloadPlace "return"}}` | `@onChange={{fn this.escolherLocal "return"}}` |

Depois rode `grep -n 'places/search\|geo=true' packages/fleetops/addon/components/order/form/route.hbs` e confira que não sobrou nenhuma linha.

- [ ] **Step 2: JS**

Em `packages/fleetops/addon/components/order/form/route.js`:

1. depois do `import preparePlaceForSave from '../../../utils/prepare-place-for-save';`, acrescente:

```js
import { Point } from '@fleetbase/fleetops-data/utils/geojson';
import { dadosDaSugestao, novaSessaoDeBusca } from '@fleetbase/ember-ui/utils/endereco-brasileiro';
```

2. junto dos outros `@service`, acrescente `@service fetch;`;

3. depois dos `@service`, acrescente:

```js
    /** Entregas: token de sessão do Google na busca do campo de local (renovado a cada endereço escolhido). */
    @tracked sessaoDaBusca = novaSessaoDeBusca();
```

4. logo antes de `@action setPayloadPlace(prop, place) {`, acrescente:

```js
    // Entregas: sugestão do Google escolhida no campo (EnderecosController@busca, marcada em meta.entregas_sugestao):
    // busca os detalhes (rua e número, bairro, cidade, UF, CEP e o ponto) antes de pôr o local no pedido. Local salvo
    // passa direto. Devolve false se os detalhes falharem: o pedido fica como estava.
    async resolverSugestao(place) {
        const sugestao = dadosDaSugestao(place);
        if (!sugestao) {
            return place;
        }

        try {
            const endereco = await this.fetch.get(`entregas/enderecos/detalhes/${encodeURIComponent(sugestao.place_id)}`, {
                sessao: sugestao.sessao,
                texto: sugestao.texto,
            });
            place.setProperties({ ...endereco, street2: null, meta: null, location: new Point(endereco.location) });
            this.sessaoDaBusca = novaSessaoDeBusca();

            return place;
        } catch (error) {
            this.notifications.serverError(error, this.intl.t('fleet-ops.ui.order-form.endereco-do-google-falhou'));

            return false;
        }
    }

    @action async escolherLocal(prop, place) {
        const local = await this.resolverSugestao(place);
        if (local !== false) {
            this.setPayloadPlace(prop, local);
        }
    }

    @action async escolherLocalDaParada(index, place) {
        const local = await this.resolverSugestao(place);
        if (local !== false) {
            this.setWaypointPlace(index, local);
        }
    }
```

O `setPayloadPlace` e o `setWaypointPlace` continuam síncronos: outras partes do arquivo (a coleta fixa da loja) dependem disso.

- [ ] **Step 3: Texto**

Em `packages/fleetops/translations/en-us.yaml`, no bloco `fleet-ops:` → `ui:` → `order-form:` (o que tem a chave `pickup-locked`), acrescente ao lado de `pickup-locked`, no mesmo recuo:

```yaml
endereco-do-google-falhou: Could not load the address from Google. Mark the point on the map or choose a saved place.
```

Em `packages/fleetops/translations/pt-br.yaml`, no mesmo bloco:

```yaml
endereco-do-google-falhou: Não foi possível carregar o endereço do Google. Marque o ponto no mapa ou escolha um local salvo.
```

- [ ] **Step 4: Conferir parse e traduções**

Run:

```bash
node -e "const fs=require('fs');const d=fs.readdirSync('console/node_modules/.pnpm').find(n=>n.startsWith('@babel+parser@7'));const p=require('./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser');for(const f of process.argv.slice(1)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('OK',f)}" packages/fleetops/addon/components/order/form/route.js
```

Expected: `OK`.

Run: `node scripts/i18n-check.cjs fleetops`
Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
git add packages/fleetops/addon/components/order/form/route.hbs packages/fleetops/addon/components/order/form/route.js packages/fleetops/translations/en-us.yaml packages/fleetops/translations/pt-br.yaml
git commit -m "Endereços: sugestões do Google no campo de local do novo pedido"
```

---

### Task 10: Validação geral e documentação

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Todos os testes PHP**

Run:

```bash
for f in scripts/teste-php/*.php; do case "$f" in *stubs*|*fixtures*|*stub-banco*) continue;; esac; r=$(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs "$f" 2>&1 | tail -1); echo "$(basename $f): $r"; done | grep -v "FALHAS: 0$"; echo fim
```

Expected: só `fim` (nenhum teste com falha).

- [ ] **Step 2: Todos os testes Node e o i18n**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`
Expected: `# fail 0`.

Run: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`
Expected: exit 0.

- [ ] **Step 3: Documentar no CLAUDE.md**

No `CLAUDE.md`, na seção "Produção" → item "**Sem chave do Google no servidor**", troque o subitem que começa com "Sem a chave, as buscas de endereço" e o que começa com "**Se a chave voltar**" por:

```markdown
  - A chave voltou em 2026-10-07 como **chave própria do Entregas** ("Entregas servidor - Geocoding", projeto `entregas-restaurantepro`, restrita ao IP da VPS e às APIs Geocoding e Places New, com limite diário), só para a busca de endereço (ver "Busca de endereço (Google Places)"). O stack traz `TRACKING_PROVIDER=osrm` (padrão no `deploy/docker-stack.yml`); sem ele, o rastreio do pedido (`tracker`) tentaria a Routes API do Google, que também é paga.
```

E acrescente, logo depois do item "**Sem chave do Google no servidor**" (e de todos os subitens dele), um item novo:

```markdown
- **Busca de endereço (Google Places)** (decisão de 2026-10-07; desenho: `docs/superpowers/specs/2026-10-07-busca-de-endereco-google-places-design.md`).
  - Servidor (`api/app/Support/Entregas/Enderecos/`): `ClienteGooglePlaces` (única porta para a Places API New; chave de Admin → Serviços lida a cada chamada), `EnderecoBrasileiro` (rua antes do número, cidade pela `administrative_area_level_2`, UF curta; sem número no Google, o digitado) e `BuscaDeEnderecos` (referência = posição de quem digita, a loja no portal ou o centro de Ribeirão Preto; mínimo de 3 caracteres; até 5 sugestões; falha do Google = lista vazia, log `[entregas] endereços:` só com o status).
  - Rotas (`Entregas/EnderecosController`, limitador `entregas-enderecos`, 120 por minuto por usuário): `GET int/v1/entregas/enderecos/sugestoes`, `.../detalhes/{placeId}` (502 sem o ponto) e `.../busca` (campo do pedido: locais salvos + sugestões marcadas em `meta.entregas_sugestao`; só da central, o `ProtegerPortalLoja` libera só `sugestoes` e `detalhes` à loja).
  - Telas: `EnderecoGoogleInput` (ember-ui) na "Rua 1" do cadastro de local (Locais, novo endereço do cliente, Criar Novo Local do pedido) e no novo endereço do portal; no novo pedido, as sugestões aparecem no próprio campo de coleta, entrega, retorno e paradas, e o console busca os detalhes ao escolher (`resolverSugestao` no `order/form/route.js`). A linha "bairro, cidade - UF, CEP" da exibição vem do `utils/endereco-brasileiro.js` do ember-ui.
  - Os locais antigos gravados como "45 RUA OLINDA" ficam como estão. O "Localizar" do mapa e o texto do endereço montado pelo servidor do Fleet-Ops (API v1, app) não mudaram.
  - Testes: `scripts/teste-php/enderecos.php` e `scripts/teste-portal/endereco-brasileiro.test.mjs`.
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "Documentação: busca de endereço pelo Google Places"
```

---

### Task 11: Implantação e Google Cloud (com o Edgard)

Sem código. Cada mudança na conta do Google exige o "sim" do Edgard naquela ação.

- [ ] **Step 1: Conferir o preço** na página de preços da Plataforma Google Maps (Places API New): Autocomplete com sessão que termina em detalhes, e o SKU dos detalhes com `id,formattedAddress,addressComponents,location`. Relatar ao Edgard antes de ativar.
- [ ] **Step 2: Ativar a Places API (New)** no projeto `entregas-restaurantepro` (APIs e serviços → Biblioteca → "Places API (New)" → Ativar).
- [ ] **Step 3: Liberar a Places API (New) na chave** "Entregas servidor - Geocoding" (Credenciais → a chave → Restrições de API → marcar também "Places API (New)" → Salvar). A restrição de IP (163.176.162.141) continua.
- [ ] **Step 4: Limite diário** (APIs e serviços → Places API (New) e Geocoding API → Cotas): sugestões e detalhes com teto diário combinado com o Edgard.
- [ ] **Step 5: Juntar na `main` e publicar** (com a autorização do Edgard): `git switch main && git merge --ff-only busca-de-endereco && git push origin main`.
- [ ] **Step 6: Deploy** (o Edgard roda na VPS): `cd ~/entregas && bash deploy/atualizar.sh api` e depois `bash deploy/atualizar.sh console`. Abrir o console com Ctrl+Shift+R.
- [ ] **Step 7: Teste real**: "rua olinda 45" no campo Entrega do novo pedido, no novo endereço do cliente e no portal da loja (janela anônima) traz a Rua Olinda de Ribeirão Preto entre as sugestões; escolhida, grava "Rua Olinda, 45" com bairro, cidade, UF, CEP e o ponto no mapa. Conferir no Google Cloud que as chamadas aparecem na Places API (New).
