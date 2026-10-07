<?php

namespace App\Support\Entregas\Distribuicao;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Matriz de durações (segundos) entre pontos [lat, lng]: uma chamada ao serviço `table` do OSRM (o mesmo OSRM_HOST do
 * km do pagamento). Quando o OSRM falha, não responde em TIMEOUT_S, devolve algo sem `durations` ou há pontos demais
 * (MAX_PONTOS_DA_MATRIZ), a matriz inteira sai em linha reta (Pontos::segundos) e marcada `aproximado`. Uma célula
 * null (sem rota entre dois pontos) sai em linha reta sem marcar.
 */
class EstimadorDeTempo
{
    public const TIMEOUT_S = 3;

    /** @return array{durations: array<int, array<int, float>>, aproximado: bool} */
    public function matriz(array $pontos): array
    {
        $n = count($pontos);
        if ($n > Distribuicao::MAX_PONTOS_DA_MATRIZ) {
            Log::warning('[entregas] distribuição: pontos demais para a matriz do OSRM; estimativa em linha reta', ['pontos' => $n]);

            return ['durations' => $this->linhaReta($pontos), 'aproximado' => true];
        }

        $durations = $this->peloOsrm($pontos);
        if ($durations === null) {
            return ['durations' => $this->linhaReta($pontos), 'aproximado' => true];
        }

        // matriz nova: célula ausente, null, não numérica ou não finita sai em linha reta (o Encaixe exige número finito)
        $matriz = [];
        foreach ($pontos as $i => $a) {
            foreach ($pontos as $j => $b) {
                $valor          = is_array($durations[$i] ?? null) ? ($durations[$i][$j] ?? null) : null;
                $matriz[$i][$j] = (is_int($valor) || is_float($valor)) && is_finite($valor) && $valor >= 0 ? (float) $valor : ($i === $j ? 0.0 : (float) Pontos::segundos($a, $b));
            }
        }

        return ['durations' => $matriz, 'aproximado' => false];
    }

    protected function peloOsrm(array $pontos): ?array
    {
        $coordenadas = implode(';', array_map(fn ($p) => $p[1] . ',' . $p[0], $pontos));
        $url         = rtrim((string) config('fleetops.osrm.host', 'https://router.project-osrm.org'), '/') . "/table/v1/driving/{$coordenadas}";

        try {
            $resposta  = Http::timeout(static::TIMEOUT_S)->get($url, ['annotations' => 'duration']);
            $durations = $resposta->json('durations');
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: OSRM indisponível; estimativa em linha reta', ['erro' => get_class($e), 'pontos' => count($pontos)]);

            return null;
        }

        if (!is_array($durations) || count($durations) !== count($pontos)) {
            Log::warning('[entregas] distribuição: OSRM indisponível; estimativa em linha reta', ['motivo' => 'resposta sem durations', 'pontos' => count($pontos)]);

            return null;
        }

        return $durations;
    }

    protected function linhaReta(array $pontos): array
    {
        $matriz = [];
        foreach ($pontos as $i => $a) {
            foreach ($pontos as $j => $b) {
                $matriz[$i][$j] = $i === $j ? 0.0 : (float) Pontos::segundos($a, $b);
            }
        }

        return $matriz;
    }
}
