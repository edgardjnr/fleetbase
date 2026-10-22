<?php

namespace App\Support\Entregas\Sistema;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: preço fixo por corrida que o dono do sistema cobra de cada central (organização). Tabela
 * entregas_precos_centrais, uma linha por central e data "vale a partir de" (dia em Brasília). O preço de uma corrida é o
 * da linha da central com a maior data ≤ dia da finalização; antes do primeiro, a corrida fica sem preço. Calculado na
 * hora do relatório: um preço novo não muda o que veio antes da data dele, e um preço com data antiga precifica o
 * histórico. Não se edita: cadastra-se outro com outra data (apagar existe para corrigir digitação).
 */
final class PrecosDasCentrais
{
    public const TABELA = 'entregas_precos_centrais';

    public const MAXIMO = 1000.0;

    /**
     * Todas as organizações não excluídas, mais as excluídas que têm preço (para poder apagá-lo), por nome:
     * [{company, nome, precos: [{id, valor, vale_a_partir_de}]}], os preços do mais recente para o mais antigo.
     */
    public static function lista(): array
    {
        $ativas = DB::table('companies')->whereNull('deleted_at')->pluck('uuid')->all();
        $precos = DB::table(static::TABELA)->get()->all();
        $uuids  = CorridasFinalizadas::distintos(array_merge($ativas, array_map(fn ($preco) => $preco->company_uuid, $precos)));
        $nomes  = static::nomes($uuids);

        $porCentral = [];
        foreach ($precos as $preco) {
            $porCentral[(string) $preco->company_uuid][] = static::formatar($preco);
        }

        $centrais = [];
        foreach ($uuids as $uuid) {
            $lista = $porCentral[$uuid] ?? [];
            usort($lista, fn ($a, $b) => strcmp($b['vale_a_partir_de'], $a['vale_a_partir_de']));
            $centrais[] = ['company' => $uuid, 'nome' => $nomes[$uuid], 'precos' => $lista];
        }
        usort($centrais, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));

        return $centrais;
    }

    /** {id, valor, vale_a_partir_de}. */
    public static function formatar(object $preco): array
    {
        return [
            'id'               => (int) $preco->id,
            'valor'            => round((float) $preco->valor, 2),
            'vale_a_partir_de' => substr((string) $preco->vale_a_partir_de, 0, 10),
        ];
    }

    /** Cadastra {company, valor, vale_a_partir_de}. Devolve {company, id, valor, vale_a_partir_de} ou a mensagem de erro. */
    public static function criar(array $dados, ?string $usuario, string $agora): array|string
    {
        $company = trim((string) ($dados['company'] ?? ''));
        if ($company === '' || !DB::table('companies')->where('uuid', $company)->whereNull('deleted_at')->exists()) {
            return 'Organização não encontrada.';
        }

        $valor = static::valor($dados['valor'] ?? null);
        if ($valor === null) {
            return 'O preço por corrida vai de R$ 0,00 a R$ 1.000,00, com até duas casas.';
        }

        $data = static::data($dados['vale_a_partir_de'] ?? null);
        if ($data === null) {
            return 'Informe a data a partir da qual o preço vale.';
        }

        if (DB::table(static::TABELA)->where('company_uuid', $company)->where('vale_a_partir_de', $data)->exists()) {
            return sprintf('Esta central já tem um preço a partir de %s. Apague o antigo para trocar.', implode('/', array_reverse(explode('-', $data))));
        }

        $id = DB::table(static::TABELA)->insertGetId([
            'company_uuid'     => $company,
            'valor'            => number_format($valor, 2, '.', ''),
            'vale_a_partir_de' => $data,
            'criado_por'       => $usuario,
            'created_at'       => $agora,
        ]);

        return ['company' => $company] + static::formatar((object) ['id' => $id, 'valor' => $valor, 'vale_a_partir_de' => $data]);
    }

    /** Apaga o preço pelo id. true se apagou. */
    public static function apagar(int $id): bool
    {
        return $id > 0 && DB::table(static::TABELA)->where('id', $id)->delete() > 0;
    }

    /** Valor de 0,00 a 1000,00 com até duas casas ("2,50", "2.5", 2.5), ou null. O console manda "1000.00". */
    public static function valor($valor): ?float
    {
        if (is_string($valor)) {
            $valor = str_replace(',', '.', trim($valor));
        }
        if (!is_numeric($valor)) {
            return null;
        }

        $numero = (float) $valor;
        if (!is_finite($numero) || abs($numero * 100 - round($numero * 100)) > 0.0001) {
            return null;
        }
        // + 0.0: "-0" vira 0.0 (sem isso, o number_format gravaria "-0.00")
        $numero = round($numero, 2) + 0.0;

        return $numero >= 0 && $numero <= static::MAXIMO ? $numero : null;
    }

    /** 'Y-m-d' de um dia que existe, ou null. */
    public static function data($valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($valor))) {
            return null;
        }

        $texto = trim($valor);
        $data  = \DateTime::createFromFormat('!Y-m-d', $texto);

        return $data && $data->format('Y-m-d') === $texto ? $texto : null;
    }

    /** [company_uuid => [['vale' => 'Y-m-d', 'valor' => float], ...]] das centrais pedidas, em ordem de data. */
    public static function porCentral(array $companies): array
    {
        $companies = CorridasFinalizadas::distintos($companies);
        if ($companies === []) {
            return [];
        }

        $saida = [];
        foreach (DB::table(static::TABELA)->whereIn('company_uuid', $companies)->orderBy('vale_a_partir_de')->get() as $preco) {
            $saida[(string) $preco->company_uuid][] = ['vale' => substr((string) $preco->vale_a_partir_de, 0, 10), 'valor' => round((float) $preco->valor, 2)];
        }

        return $saida;
    }

    /** O preço que vale no dia ('Y-m-d'): o de maior "vale a partir de" ≤ dia, ou null. $precos em ordem de data. */
    public static function precoNoDia(array $precos, string $dia): ?float
    {
        $preco = null;
        foreach ($precos as $item) {
            if ($item['vale'] > $dia) {
                break;
            }
            $preco = (float) $item['valor'];
        }

        return $preco;
    }

    /** [company_uuid => nome] das organizações, inclusive excluídas; a que não existe mais (ou sem nome) vira o uuid curto. */
    public static function nomes(array $uuids): array
    {
        $uuids   = CorridasFinalizadas::distintos($uuids);
        $achados = $uuids === [] ? [] : DB::table('companies')->whereIn('uuid', $uuids)->pluck('name', 'uuid')->all();

        $nomes = [];
        foreach ($uuids as $uuid) {
            $nome         = trim((string) ($achados[$uuid] ?? ''));
            $nomes[$uuid] = $nome !== '' ? $nome : substr($uuid, 0, 8);
        }

        return $nomes;
    }
}
