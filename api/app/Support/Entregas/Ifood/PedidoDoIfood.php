<?php

namespace App\Support\Entregas\Ifood;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: tradução do pedido do módulo Logistics do iFood (GET /logistics/v1.0/orders/{id}, campos
 * vistos na sonda de 2026-10-05) nos dados do pedido do Fleetbase e da linha de entregas_ifood_pedidos. Função pura,
 * fora os avisos no log ("agendado sem janela" e "pagamento inconsistente", só com ids e valores, sem dado do cliente).
 *
 * - Número: `displayId` (ex.: 4821) vira o internal_id e o "iFood #4821" das notas.
 * - Entrega: coordenadas, rua + número (sem `streetName`, o `formattedAddress`), bairro, complemento e referência (no
 *   street2, para o motoboy ler) e o nome do cliente (nome do Local). Sem geocodificação: as coordenadas do iFood
 *   valem.
 *   CEP só de zeros e estado fora das 27 siglas do Brasil ("XX" do teste, nome por extenso) ficam nulos.
 * - Pedido de teste (`isTest` true, "true", 1 ou "1"; entrega em 0,0): entrega na coleta deslocada ~1 km para o norte
 *   (o km não fica absurdo) e "[TESTE]" nas notas. Vai aos motoboys como o real (imediato na hora; agendado com janela,
 *   40 min antes): quem está online perto da loja recebe o alarme, com o endereço falso.
 * - Pedido real sem coordenadas válidas (ausentes, não numéricas, latitude ou longitude 0, fora de ±90/±180 ou a mais
 *   de 50 km da coleta): o mesmo deslocamento, `sem_coordenadas`, "[SEM LOCALIZAÇÃO]" nas notas e sem despacho: a
 *   central confere.
 * - Agendado (`orderTiming = SCHEDULED`, sem diferença de caixa ou espaços): vai aos motoboys 40 min antes do início
 *   da janela (`schedule.deliveryDateTimeStart`, data ISO). A sonda só viu pedidos imediatos: o formato do `schedule`
 *   é o da documentação (a conferir no primeiro agendado). Se faltar menos de 40 min, despacha na hora. Sem janela
 *   legível, não despacha (o `delivery.deliveryDateTime` é só a estimativa, perto do createdAt): `sem_janela`,
 *   "[AGENDADO SEM HORÁRIO]" nas notas e o log `[entregas] ifood: agendado sem janela`, para a central conferir.
 * - `agendado` diz só que o pedido é para depois (SCHEDULED com janela a mais de 40 min ou sem janela).
 *   `scheduled_at` e `despachar_em` só existem quando o pedido vai aos motoboys: o `fleetops:dispatch-orders` do
 *   Fleet-Ops despacha sozinho quem tem `scheduled_at`, então o sem coordenadas e o sem janela ficam com
 *   os dois nulos (o de teste segue a regra do real).
 * - Cobrança: sem `payments` (pedido pago online, visto na sonda), nada a cobrar; com `pending`, o valor; sem
 *   `pending`, a soma dos métodos não pagos (`prepaid: false`, ou `type: OFFLINE` sem o `prepaid`). O `pending`
 *   explícito sempre vale (0 = nada a cobrar, mesmo com método não pago). A forma é a do método não pago (dois métodos
 *   diferentes: "CASH+CREDIT"; não cabendo em 30 caracteres, "MISTO") e o troco (`methods[].cash.changeFor`) só vale
 *   para dinheiro. Formato da documentação, a conferir na homologação: o gerador de pedidos de teste só cria pedido
 *   pago online. Valor acima de R$ 100 mil (CENTAVOS_MAXIMO) é implausível: a cobrança vira 0 (com o aviso de
 *   pagamento inconsistente) e o troco, nulo, para o insert não estourar a coluna.
 * - 0800 e localizador do cliente (`customer.phone`) com a expiração do localizador.
 *
 * Datas em texto 'Y-m-d H:i:s' no fuso do app (o mesmo da sessão do MySQL: horário de Brasília; ver CLAUDE.md, "Fuso
 * (horário de Brasília)"). As contas são feitas em UTC e só a saída é convertida (paraOBanco). Data que não é ISO com
 * hora, ou com ano fora de 2000 a 2037 (ANO_MINIMO, ANO_MAXIMO), vale como ausente.
 */
final class PedidoDoIfood
{
    /** O agendado vai aos motoboys este tanto antes do início da janela de entrega. */
    public const ANTECEDENCIA_AGENDADO_MINUTOS = 40;

    /** Deslocamento da entrega do pedido de teste: ~1 km para o norte. */
    public const DESLOCAMENTO_TESTE_GRAUS = 0.009;

    /** Entrega mais longe que isto da coleta é coordenada errada (vale como sem coordenadas). */
    public const DISTANCIA_MAXIMA_METROS = 50000;

    /** Tamanho da coluna forma_pagamento. */
    public const FORMA_MAXIMO = 30;

    /** Valor em centavos acima do qual é implausível (R$ 100 mil): cobrança e troco não vão ao banco. */
    public const CENTAVOS_MAXIMO = 10000000;

    /** Datas fora deste intervalo de anos valem como ausentes (não cabem no DATETIME de quem grava). */
    public const ANO_MINIMO = 2000;
    public const ANO_MAXIMO = 2037;

    public const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];

    public static function mapear(array $pedido, float $latitudeColeta, float $longitudeColeta, DateTimeInterface $agora): array
    {
        $utc      = new DateTimeZone('UTC');
        $agora    = DateTimeImmutable::createFromInterface($agora)->setTimezone($utc);
        $numero   = static::texto($pedido['displayId'] ?? null, 20) ?? substr(static::texto($pedido['id'] ?? null, 64) ?? '', 0, 8);
        $teste    = static::booleano($pedido['isTest'] ?? null) === true;
        $entrega  = is_array($pedido['delivery'] ?? null) ? $pedido['delivery'] : [];
        $endereco = is_array($entrega['deliveryAddress'] ?? null) ? $entrega['deliveryAddress'] : [];
        $cliente  = is_array($pedido['customer'] ?? null) ? $pedido['customer'] : [];
        $telefone = is_array($cliente['phone'] ?? null) ? $cliente['phone'] : [];
        $ids      = ['pedido_ifood' => static::texto($pedido['id'] ?? null, 64), 'numero' => $numero];

        $coordenadas    = static::coordenadas(is_array($endereco['coordinates'] ?? null) ? $endereco['coordinates'] : [], $latitudeColeta, $longitudeColeta);
        $semCoordenadas = !$teste && $coordenadas === null;
        [$latitude, $longitude] = $coordenadas ?? [0.0, 0.0];
        if ($teste || $semCoordenadas) {
            $latitude  = $latitudeColeta + static::DESLOCAMENTO_TESTE_GRAUS;
            $longitude = $longitudeColeta;
        }

        $programado = is_string($pedido['orderTiming'] ?? null) && strtoupper(trim($pedido['orderTiming'])) === 'SCHEDULED';
        $janela     = is_array($pedido['schedule'] ?? null) ? $pedido['schedule'] : [];
        $inicio     = $programado ? static::data($janela['deliveryDateTimeStart'] ?? null) : null;
        $semJanela  = $programado && $inicio === null;
        if ($semJanela) {
            Log::warning('[entregas] ifood: agendado sem janela', $ids);
        }
        $despacharEm = $inicio ? $inicio->modify('-' . static::ANTECEDENCIA_AGENDADO_MINUTOS . ' minutes') : $agora;
        $agendado    = $semJanela || $despacharEm > $agora;
        if ($despacharEm < $agora) {
            $despacharEm = $agora;
        }
        $semDespacho = $semCoordenadas || $semJanela;

        [$cobrar, $forma, $troco] = static::cobranca($pedido['payments'] ?? null, $ids);

        $complemento = static::texto($endereco['complement'] ?? null, 190);
        $referencia  = static::texto($endereco['reference'] ?? null, 190);
        $nomeDaRua   = static::texto($endereco['streetName'] ?? null, 190);
        $numeroDaRua = static::texto($endereco['streetNumber'] ?? null, 30);
        $rua         = $nomeDaRua !== null
            ? static::texto($nomeDaRua . ($numeroDaRua !== null ? ', ' . $numeroDaRua : ''), 190)
            : static::texto($endereco['formattedAddress'] ?? null, 190);
        $pais   = strtoupper(static::texto($endereco['country'] ?? null, 10) ?? '');
        $estado = strtoupper(static::texto($endereco['state'] ?? null, 10) ?? '');
        $cep    = static::texto($endereco['postalCode'] ?? null, 20);

        $marcas = ($teste ? ' [TESTE]' : '') . ($semCoordenadas ? ' [SEM LOCALIZAÇÃO]' : '') . ($semJanela ? ' [AGENDADO SEM HORÁRIO]' : '');

        return [
            'numero'          => $numero,
            'teste'           => $teste,
            'agendado'        => $agendado,
            'sem_coordenadas' => $semCoordenadas,
            'sem_janela'      => $semJanela,
            'despachar_agora' => !$semDespacho && !$agendado,
            'scheduled_at'    => !$semDespacho && $agendado ? static::paraOBanco($despacharEm) : null,
            'notas'           => 'iFood #' . $numero . $marcas,
            'entrega'         => [
                'nome'         => static::texto($cliente['name'] ?? null, 120) ?? 'Cliente iFood',
                'street1'      => $rua ?? 'Endereço do iFood',
                'street2'      => static::texto(implode(' · ', array_filter([$complemento, $referencia ? 'Ref.: ' . $referencia : null])), 190),
                'neighborhood' => static::texto($endereco['neighborhood'] ?? null, 120),
                'city'         => static::texto($endereco['city'] ?? null, 120),
                'province'     => in_array($estado, static::UFS, true) ? $estado : null,
                'postal_code'  => $cep !== null && preg_match('/^[0\s.\-]+$/', $cep) ? null : $cep,
                'country'      => preg_match('/^[A-Z]{2}$/', $pais) && $pais !== 'XX' ? $pais : 'BR',
                'latitude'     => $latitude,
                'longitude'    => $longitude,
            ],
            'linha'           => [
                'numero'              => $numero,
                'telefone_0800'       => static::texto($telefone['number'] ?? null, 30),
                'localizador'         => static::texto($telefone['localizer'] ?? null, 20),
                'telefone_expira_em'  => static::paraOBanco(static::data($telefone['localizerExpiration'] ?? null)),
                'cobrar_centavos'     => $cobrar,
                'forma_pagamento'     => $forma,
                'troco_para_centavos' => $troco,
                'observacoes'         => static::texto($entrega['observations'] ?? null, 1000),
                'complemento'         => $complemento,
                'referencia'          => $referencia,
                'exige_codigo'        => false,
                'teste'               => $teste,
                'agendado'            => $agendado,
                'despachar_em'        => $semDespacho ? null : static::paraOBanco($despacharEm),
            ],
        ];
    }

    /**
     * [centavos a cobrar, forma (CASH, CREDIT…, "CASH+CREDIT" ou "MISTO"), troco para (centavos, só dinheiro) ou null].
     * O `pending` explícito é a fonte de verdade; só sem ele vale a soma dos métodos não pagos. É formato divergente da
     * documentação, avisado em `[entregas] ifood: pagamento inconsistente` (com $contexto, os ids, e valores):
     * - `pending` > 0 sem método não pago (cobra o pending, forma desconhecida);
     * - `pending` = 0 com método não pago (nada a cobrar: cobrar de quem já pagou é pior que o motoboy conferir na
     *   porta);
     * - sem `pending`, método não pago e soma 0 (o `value` veio ausente ou ilegível: nada a cobrar, o motoboy confere);
     * - valor implausível (acima de CENTAVOS_MAXIMO, ou infinito): a cobrança vira 0. O troco implausível vira nulo.
     */
    public static function cobranca($pagamentos, array $contexto = []): array
    {
        if (!is_array($pagamentos)) {
            return [0, null, null];
        }

        $metodos     = is_array($pagamentos['methods'] ?? null) ? array_values(array_filter($pagamentos['methods'], 'is_array')) : [];
        $naPorta     = array_values(array_filter($metodos, fn (array $metodo) => static::naoPago($metodo)));
        $somaNaPorta = static::centavos(array_sum(array_map(fn (array $metodo) => is_numeric($metodo['value'] ?? null) ? max(0.0, (float) $metodo['value']) : 0.0, $naPorta)));
        $temPending  = is_numeric($pagamentos['pending'] ?? null);
        $pendente    = $temPending ? static::centavos((float) $pagamentos['pending']) : $somaNaPorta;
        $implausivel = $pendente > static::CENTAVOS_MAXIMO;

        $inconsistente = $implausivel
            || ($temPending && (($pendente > 0 && !$naPorta) || ($pendente === 0 && $naPorta)))
            || (!$temPending && $naPorta && $somaNaPorta === 0);
        if ($inconsistente) {
            Log::warning('[entregas] ifood: pagamento inconsistente', $contexto + [
                'pendente_centavos' => $implausivel ? null : $pendente,
                'na_porta_centavos' => $somaNaPorta > static::CENTAVOS_MAXIMO ? null : $somaNaPorta,
                'implausivel'       => $implausivel,
                'metodos'           => count($metodos),
                'metodos_na_porta'  => count($naPorta),
            ]);
        }

        if ($pendente <= 0 || $implausivel) {
            return [0, null, null];
        }

        $formas = array_values(array_unique(array_filter(array_map(fn (array $metodo) => static::forma($metodo), $naPorta))));
        $forma  = match (count($formas)) {
            0       => null,
            1       => $formas[0],
            default => strlen(implode('+', $formas)) <= static::FORMA_MAXIMO ? implode('+', $formas) : 'MISTO',
        };

        $troco = null;
        foreach ($naPorta as $metodo) {
            $para = $metodo['cash']['changeFor'] ?? null;
            if (static::forma($metodo) === 'CASH' && is_numeric($para) && (float) $para > 0) {
                $troco = static::centavos((float) $para);
                if ($troco > static::CENTAVOS_MAXIMO) {
                    Log::warning('[entregas] ifood: pagamento inconsistente', $contexto + ['troco_implausivel' => true]);
                    $troco = null;
                }
                break;
            }
        }

        return [$pendente, $forma, $troco];
    }

    /**
     * Reais em centavos inteiros, sem negativo. Acima de CENTAVOS_MAXIMO (ou infinito) devolve CENTAVOS_MAXIMO + 1: o
     * chamador só precisa saber que passou do limite, e o (int) de um float enorme não fica indefinido.
     */
    protected static function centavos(float $reais): int
    {
        if (!is_finite($reais) || $reais * 100 > static::CENTAVOS_MAXIMO) {
            return static::CENTAVOS_MAXIMO + 1;
        }

        return max(0, (int) round($reais * 100));
    }

    /** Distância em linha reta, em metros (haversine). */
    public static function metrosEntre(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $dLatitude  = deg2rad($latitude2 - $latitude1);
        $dLongitude = deg2rad($longitude2 - $longitude1);
        $a          = sin($dLatitude / 2) ** 2 + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($dLongitude / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }

    /**
     * [latitude, longitude] ou null se a coordenada não serve: ausente, não numérica, latitude ou longitude 0, fora de
     * ±90/±180 ou a mais de 50 km da coleta (a distância só é conferida se a coleta tem coordenada).
     */
    protected static function coordenadas(array $coordenadas, float $latitudeColeta, float $longitudeColeta): ?array
    {
        $latitude  = $coordenadas['latitude'] ?? null;
        $longitude = $coordenadas['longitude'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $latitude  = (float) $latitude;
        $longitude = (float) $longitude;
        if (!is_finite($latitude) || !is_finite($longitude) || $latitude == 0.0 || $longitude == 0.0 || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        $coletaConhecida = $latitudeColeta != 0.0 && $longitudeColeta != 0.0;
        if ($coletaConhecida && static::metrosEntre($latitudeColeta, $longitudeColeta, $latitude, $longitude) > static::DISTANCIA_MAXIMA_METROS) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /** Método ainda não pago: `prepaid` falso; sem `prepaid` legível, `type` OFFLINE. */
    protected static function naoPago(array $metodo): bool
    {
        $pago = static::booleano($metodo['prepaid'] ?? null);
        if ($pago !== null) {
            return !$pago;
        }

        return is_string($metodo['type'] ?? null) && strtoupper(trim($metodo['type'])) === 'OFFLINE';
    }

    protected static function forma(array $metodo): ?string
    {
        $forma = static::texto($metodo['method'] ?? null, static::FORMA_MAXIMO);

        return $forma === null ? null : strtoupper($forma);
    }

    /** true para true, 1, "1" e "true"; false para false, 0, "0" e "false"; null para o resto. */
    protected static function booleano($valor): ?bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        if (is_int($valor) || is_float($valor)) {
            return $valor == 1 ? true : ($valor == 0 ? false : null);
        }
        if (is_string($valor)) {
            return match (strtolower(trim($valor))) {
                'true', '1'  => true,
                'false', '0' => false,
                default      => null,
            };
        }

        return null;
    }

    protected static function texto($valor, int $maximo): ?string
    {
        $texto = is_scalar($valor) && !is_bool($valor) ? trim((string) $valor) : '';

        return $texto === '' ? null : mb_substr($texto, 0, $maximo);
    }

    /**
     * Texto 'Y-m-d H:i:s' no fuso do app (date_default_timezone_get(), que o Laravel define pelo app.timezone), o mesmo
     * da sessão do MySQL: vai para o banco e para o scheduled_at do Order (texto sem fuso, que o Eloquent lê no fuso do
     * app). Em UTC, o agendado era despachado 3 h depois e o despachar_em ficava 3 h depois do now() do agendador.
     */
    public static function paraOBanco(?DateTimeInterface $data): ?string
    {
        return $data ? DateTimeImmutable::createFromInterface($data)->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s') : null;
    }

    /** Data ISO (com hora) em UTC, ano de 2000 a 2037; null para o resto ("tomorrow", texto, lista, ano absurdo). */
    protected static function data($valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || !preg_match('/^\s*\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $valor)) {
            return null;
        }

        try {
            $data = (new DateTimeImmutable(trim($valor), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        $ano = (int) $data->format('Y');

        return $ano >= static::ANO_MINIMO && $ano <= static::ANO_MAXIMO ? $data : null;
    }
}
