<?php

namespace App\Support\Entregas\Ifood;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Entregas RestaurantePro: os cenários de erro da homologação do iFood (comando entregas:ifood-homologacao), para
 * mostrar ao analista, com a loja de teste, o tratamento que o ClienteIfood, o VinculosIfood e os jobs já fazem:
 *
 * - token vencido: a próxima chamada renova antes de usar (log "token renovado", motivo "vencimento");
 * - token inválido: o iFood responde 401, o token é renovado e a chamada repete (motivo "401");
 * - erro simulado: a próxima chamada da operação sobe com o status escolhido, sem ir ao iFood (ClienteIfood::erroSimulado).
 *
 * Tudo só com o modo homologação ligado (ENTREGAS_IFOOD_HOMOLOGACAO=1). Nunca mexe no refresh token: um refresh
 * recusado derrubaria o vínculo.
 */
class HomologacaoIfood
{
    /** Operações que aceitam erro simulado (os nomes do ClienteIfood). O token e o vínculo ficam de fora. */
    public const OPERACOES = ['polling', 'ack', 'pedido', 'assignDriver', 'goingToOrigin', 'arrivedAtOrigin', 'dispatch', 'arrivedAtDestination', 'verifyDeliveryCode'];

    public const STATUS = [400, 404, 409, 429, 500, 503];

    /** O erro simulado vale por 10 min se nenhuma chamada da operação o consumir. */
    public const VALIDADE_DA_SIMULACAO_SEGUNDOS = 600;

    /** Texto do token inválido gravado (cifrado) no lugar do access token. */
    public const TOKEN_INVALIDO = 'homologacao-token-invalido';

    /** O vínculo da loja: --loja = public_id do Fornecedor (vendor_…) ou merchant id; sem ela, a única loja vinculada. */
    public static function vinculo(?string $loja): object
    {
        $vinculos = DB::table(VinculosIfood::TABELA)->where('situacao', VinculosIfood::VINCULADA)->orderBy('id')->get()->all();
        if ($loja !== null && $loja !== '') {
            $vendorUuid = DB::table('vendors')->where('public_id', $loja)->value('uuid') ?? $loja;
            $vinculos   = array_values(array_filter($vinculos, fn ($v) => $v->merchant_id === $loja || $v->vendor_uuid === $vendorUuid));
        }

        if (count($vinculos) !== 1) {
            throw new InvalidArgumentException(count($vinculos) === 0
                ? 'Nenhuma loja vinculada com esse filtro.'
                : 'Há mais de uma loja vinculada: informe --loja=<vendor_… ou merchant id>.');
        }

        return $vinculos[0];
    }

    /** Situação das lojas e dos erros simulados pendentes, sem tokens. */
    public static function situacao(): array
    {
        $lojas = [];
        foreach (DB::table(VinculosIfood::TABELA)->where('situacao', '!=', VinculosIfood::DESVINCULADA)->orderBy('id')->get()->all() as $vinculo) {
            $vendor  = DB::table('vendors')->where('uuid', $vinculo->vendor_uuid)->first();
            $lojas[] = [
                'loja'        => $vendor->name ?? $vinculo->vendor_uuid,
                'fornecedor'  => $vendor->public_id ?? null,
                'merchant'    => $vinculo->merchant_id,
                'no_ifood'    => $vinculo->nome_ifood,
                'situacao'    => $vinculo->situacao,
                'expira_em'   => $vinculo->expira_em,
                'renovado_em' => $vinculo->renovado_em,
            ];
        }

        $simulados = [];
        foreach (static::OPERACOES as $operacao) {
            $simulado = Cache::get(ClienteIfood::chaveDaSimulacao($operacao));
            if (is_array($simulado)) {
                $simulados[$operacao] = $simulado;
            }
        }

        return ['homologacao' => ClienteIfood::emHomologacao(), 'ligada' => ClienteIfood::ligada(), 'lojas' => $lojas, 'simulados' => $simulados];
    }

    /** Token vencido: a próxima chamada renova antes de usar (VinculosIfood::tokenValido). */
    public static function vencerToken(object $vinculo): void
    {
        static::exigirHomologacao();
        DB::table(VinculosIfood::TABELA)->where('id', $vinculo->id)->update(['expira_em' => now()->subMinute()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()]);
    }

    /**
     * Token inválido, ainda dentro da validade: a próxima chamada leva 401 do iFood de verdade, renova pelo refresh e
     * repete (VinculosIfood::comToken e o chamarComToken do polling).
     */
    public static function invalidarToken(object $vinculo): void
    {
        static::exigirHomologacao();
        DB::table(VinculosIfood::TABELA)->where('id', $vinculo->id)->update([
            'access_token' => encrypt(static::TOKEN_INVALIDO),
            'expira_em'    => now()->addSeconds(2 * 3600)->toDateTimeString(),
            'updated_at'   => now()->toDateTimeString(),
        ]);
    }

    /** Erro simulado na próxima chamada da operação (uma vez; vence em 10 min). */
    public static function simular(string $operacao, int $status, ?int $espera = null): void
    {
        static::exigirHomologacao();
        if (!in_array($operacao, static::OPERACOES, true)) {
            throw new InvalidArgumentException('Operação inválida. Use uma de: ' . implode(', ', static::OPERACOES) . '.');
        }
        if (!in_array($status, static::STATUS, true)) {
            throw new InvalidArgumentException('Status inválido. Use um de: ' . implode(', ', static::STATUS) . '.');
        }

        Cache::put(ClienteIfood::chaveDaSimulacao($operacao), ['status' => $status, 'espera' => $status === 429 ? ($espera ?? ClienteIfood::ESPERA_PADRAO_429) : null], static::VALIDADE_DA_SIMULACAO_SEGUNDOS);
    }

    /** Tira os erros simulados que ninguém consumiu. */
    public static function limpar(): void
    {
        foreach (static::OPERACOES as $operacao) {
            Cache::forget(ClienteIfood::chaveDaSimulacao($operacao));
        }
    }

    protected static function exigirHomologacao(): void
    {
        if (!ClienteIfood::emHomologacao()) {
            throw new InvalidArgumentException('Modo homologação desligado: ponha ENTREGAS_IFOOD_HOMOLOGACAO=1 no stack.env e faça o Update the stack.');
        }
    }
}
