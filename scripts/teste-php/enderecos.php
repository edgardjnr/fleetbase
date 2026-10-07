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
