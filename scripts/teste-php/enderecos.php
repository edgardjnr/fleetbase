<?php

// Busca de endereço pelo Google Places (New): o endereço no formato brasileiro (EnderecoBrasileiro), a porta HTTP
// (ClienteGooglePlaces), as regras da busca (BuscaDeEnderecos) e a liberação no portal da loja (ProtegerPortalLoja).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/enderecos.php

require __DIR__ . '/stubs-ifood.php';
require '/repo/api/app/Support/Entregas/Enderecos/EnderecoBrasileiro.php';
require '/repo/api/app/Support/Entregas/Enderecos/ErroGooglePlaces.php';
require '/repo/api/app/Support/Entregas/Enderecos/ClienteGooglePlaces.php';
require '/repo/api/app/Support/Entregas/Enderecos/BuscaDeEnderecos.php';
require '/repo/api/app/Http/Middleware/ProtegerPortalLoja.php';

use App\Http\Middleware\ProtegerPortalLoja;
use App\Support\Entregas\Enderecos\BuscaDeEnderecos;
use App\Support\Entregas\Enderecos\ClienteGooglePlaces;
use App\Support\Entregas\Enderecos\EnderecoBrasileiro;
use App\Support\Entregas\Enderecos\ErroGooglePlaces;
use Teste\Config;
use Teste\Http;

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

echo '== Entrada (texto e sessão)' . PHP_EOL;
[$t, $s] = BuscaDeEnderecos::entrada('  rua olinda  ', 'sessao_123-ABC');
confere($t === 'rua olinda' && $s === 'sessao_123-ABC', 'entrada: texto sem espaços e sessão válida passam');
[$t, $s] = BuscaDeEnderecos::entrada(['a'], ['b']);
confere($t === '' && preg_match(BuscaDeEnderecos::FORMATO_DA_SESSAO, $s) === 1, 'entrada: array vira texto vazio e sessão nova');
[$t, $s] = BuscaDeEnderecos::entrada(null, null);
confere($t === '' && strlen($s) >= 8, 'entrada: nulos');
[, $s] = BuscaDeEnderecos::entrada('x', str_repeat('a', 37));
confere($s !== str_repeat('a', 37) && strlen($s) <= 36, 'entrada: sessão com mais de 36 caracteres é trocada');
[, $s] = BuscaDeEnderecos::entrada('x', str_repeat('a', 36));
confere($s === str_repeat('a', 36), 'entrada: sessão de 36 caracteres vale');
[$t] = BuscaDeEnderecos::entrada(str_repeat('é', 500), 'sessao-1');
confere(mb_strlen($t) === BuscaDeEnderecos::MAXIMO_DE_CARACTERES, 'entrada: texto cortado em 200 caracteres');

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

resumo();
