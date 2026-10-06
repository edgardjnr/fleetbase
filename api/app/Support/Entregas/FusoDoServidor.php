<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: o servidor inteiro no horário de Brasília (America/Sao_Paulo, sem horário de verão desde
 * 2019). Ver CLAUDE.md, "Fuso (horário de Brasília)".
 *
 * - O PHP usa o `app.timezone` (config/app.php).
 * - A sessão do MySQL usa FUSO_DO_BANCO (ou a variável DB_TIMEZONE) nas conexões de CONEXOES. As conexões vêm do
 *   core-api (config/database.connections.php, mesclado com mergeConfigFrom, que é raso): por isso o fuso entra pelo
 *   AppServiceProvider e não pelo config/database.php, onde uma chave 'mysql' substituiria a conexão inteira.
 * - Toda data criada pela fachada Date (o Eloquent ao gravar e ler atributos de data, now(), $request->date()) é
 *   convertida para o fuso do app (paraOFusoDoApp). Sem isso, um texto ISO com "Z" (o console manda o scheduled_at
 *   assim) era gravado com a hora UTC, porque o Eloquent formata no fuso do próprio objeto.
 *
 * O que essa conversão NÃO cobre: Carbon em outro fuso passado direto para o query builder (where, whereBetween,
 * whereDate, insert e update do DB::table formatam no fuso do objeto) e texto já formatado em UTC sem fuso. Para gravar
 * ou consultar, use sempre o fuso do app.
 */
class FusoDoServidor
{
    /** Fuso da sessão do MySQL. Fixo: Brasília não tem horário de verão desde 2019. */
    public const FUSO_DO_BANCO = '-03:00';

    /** Conexões MySQL do Fleetbase: o banco principal, o sandbox (modo de teste) e o do storefront (se instalado). */
    public const CONEXOES = ['mysql', 'sandbox', 'storefront'];

    /** Fuso da sessão do MySQL: a variável DB_TIMEZONE (getenv: sobrevive ao config:cache) ou FUSO_DO_BANCO. */
    public static function fusoDoBanco(): string
    {
        $variavel = getenv('DB_TIMEZONE');

        return is_string($variavel) && trim($variavel) !== '' ? trim($variavel) : static::FUSO_DO_BANCO;
    }

    /**
     * As configurações de 'database.connections.<nome>.timezone' a gravar: só para as conexões de CONEXOES que existem.
     *
     * @param array<string, mixed> $conexoes o config('database.connections')
     *
     * @return array<string, string>
     */
    public static function configuracoesDoBanco(array $conexoes, string $fuso): array
    {
        $configuracoes = [];
        foreach (static::CONEXOES as $nome) {
            if (isset($conexoes[$nome]) && is_array($conexoes[$nome])) {
                $configuracoes['database.connections.' . $nome . '.timezone'] = $fuso;
            }
        }

        return $configuracoes;
    }

    /**
     * A data no fuso do app (o mesmo instante). Usado no Date::useCallable: recebe cada data criada pela fachada Date.
     * O que não é data (o Carbon pode devolver false fora do modo estrito) passa como veio.
     */
    public static function paraOFusoDoApp($data, string $fuso)
    {
        // DateTimeZone (não o texto): o Carbon aceita os dois, o DateTime do PHP só o objeto
        return $data instanceof \DateTimeInterface && method_exists($data, 'setTimezone') ? $data->setTimezone(new \DateTimeZone($fuso)) : $data;
    }
}
