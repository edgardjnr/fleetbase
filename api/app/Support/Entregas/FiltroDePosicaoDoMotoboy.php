<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: decide se uma posição que o app do motoboy mandou ao POST v1/drivers/{id}/track entra no
 * cadastro dele (drivers.location, socket, distribuição, mapas) ou é descartada. Funções puras: o estado (a última
 * posição aceita) fica no cache, pelo DriverControllerSemGeocodificacao.
 *
 * O track() do Fleet-Ops aceita qualquer posição, na ordem em que chega, e ignora o `timestamp` que o plugin de GPS do
 * app manda. O plugin pode mandar uma posição velha depois de uma nova (o batimento de 1 min reaproveita a posição em
 * cache de até 5 min; a fila do plugin reenvia o que ficou sem sinal) e, sem GPS, uma posição grosseira da rede de
 * celular, a 1 ou 2 km. Cada uma delas movia o motoboy no mapa e voltava na seguinte (vai e volta no mapa do console).
 *
 * Regras:
 *  - posição com `timestamp` anterior ao da última aceita é descartada (`antiga`). O mesmo instante passa: é o
 *    batimento repetindo a última posição, e ele mantém o motoboy na distribuição (GPS de menos de 30 min);
 *  - posição com precisão pior que 500 m (`accuracy`, em metros) é descartada (`imprecisa`) enquanto houver uma
 *    posição boa aceita há menos de 5 min. Sem posição boa recente, ela passa: é melhor que nada;
 *  - sem `timestamp` (o SDK do app, APK antigo) ou sem `accuracy`, a regra correspondente não se aplica.
 *
 * O instante guardado nunca passa da hora do servidor: um celular com o relógio adiantado não trava as posições
 * seguintes quando o relógio é corrigido.
 */
final class FiltroDePosicaoDoMotoboy
{
    /** Precisão (m) acima da qual a posição é grosseira (rede de celular, não GPS). */
    public const PRECISAO_RUIM_M = 500;

    /** Por quanto tempo (ms) uma posição boa aceita impede a troca por uma grosseira. */
    public const VALIDADE_DA_BOA_MS = 5 * 60 * 1000;

    /** Chave do cache da última posição aceita, + id do motoboy como veio na rota. */
    public const CHAVE = 'entregas:motoboy-ultima-posicao:';

    /** Por quanto tempo (s) o cache guarda a última posição aceita. */
    public const GUARDA_S = 24 * 60 * 60;

    /** Instante (ms) do `timestamp` do plugin (ISO, ex. "2026-10-07T11:27:01.123Z"); null se faltar ou for ilegível. */
    public static function instante($texto): ?int
    {
        if (!is_string($texto) || trim($texto) === '' || is_numeric($texto)) {
            return null;
        }

        try {
            return (int) (new \DateTimeImmutable($texto))->format('Uv');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Precisão (m) da posição; null se faltar, não for número ou não for positiva (o Android manda 0 sem dado). */
    public static function precisao($valor): ?float
    {
        if (!is_numeric($valor) || (float) $valor <= 0 || !is_finite((float) $valor)) {
            return null;
        }

        return (float) $valor;
    }

    /**
     * @param array|null $ultima    a última posição aceita: ['instante' => ?int, 'precisao' => ?float, 'em' => int]
     * @param int|null   $instante  ms do `timestamp` da posição nova
     * @param float|null $precisao  `accuracy` (m) da posição nova
     * @param int        $agora     ms da hora do servidor
     *
     * @return array{aceita: bool, motivo: ?string, ultima: ?array} `ultima` = o que gravar no cache se aceita
     */
    public static function avaliar(?array $ultima, ?int $instante, ?float $precisao, int $agora): array
    {
        $instanteDaUltima = is_array($ultima) && is_int($ultima['instante'] ?? null) ? $ultima['instante'] : null;

        if ($instante !== null && $instanteDaUltima !== null && $instante < $instanteDaUltima) {
            return ['aceita' => false, 'motivo' => 'antiga', 'ultima' => null];
        }

        $precisaoDaUltima = is_array($ultima) ? self::precisao($ultima['precisao'] ?? null) : null;
        $ultimaEra        = is_array($ultima) && is_int($ultima['em'] ?? null) ? $ultima['em'] : null;
        $boaRecente       = $precisaoDaUltima !== null && $precisaoDaUltima <= self::PRECISAO_RUIM_M && $ultimaEra !== null && $agora - $ultimaEra < self::VALIDADE_DA_BOA_MS;

        if ($precisao !== null && $precisao > self::PRECISAO_RUIM_M && $boaRecente) {
            return ['aceita' => false, 'motivo' => 'imprecisa', 'ultima' => null];
        }

        return [
            'aceita' => true,
            'motivo' => null,
            'ultima' => [
                'instante' => $instante !== null ? min($instante, $agora) : $instanteDaUltima,
                'precisao' => $precisao,
                'em'       => $agora,
            ],
        ];
    }
}
