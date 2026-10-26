<?php

namespace App\Support\Entregas\Saques;

use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Financeiro\Financeiro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: a conta Asaas da central (entregas_asaas_conexoes, uma por empresa). Conectar confere a chave
 * no Asaas (dados comerciais e wallet) e grava só a chave cifrada (Cofre) e a wallet, que fica fixada: trocar a chave só
 * pela de uma conta com a mesma wallet. O token da validação de saque é guardado só como hash e aparece uma vez. O teste
 * da validação manda R$ 1,00 para uma chave Pix do admin, que a nossa validação recusa (ValidacaoDeSaque); recebido o
 * pedido com o token certo, o saque pode ser ligado.
 *
 * Erros de entrada: \InvalidArgumentException (422, mensagem pt-BR). Asaas fora do ar: ErroDoAsaas (502). Sem o secret:
 * CofreIndisponivel (503).
 */
final class ConexaoAsaas
{
    public const TAMANHO_MINIMO_DA_CHAVE = 20;

    public const VALOR_DO_TESTE = 1.0;

    /** O menor "mínimo por saque" que a central pode configurar: acima do R$ 1,00 do teste, para não se confundirem. */
    public const MINIMO_POR_SAQUE = 2.0;

    /** Testes da validação por central por dia (de Brasília). */
    public const TESTES_POR_DIA = 5;

    public const CAMINHO_DA_VALIDACAO = '/v1/entregas/asaas/validar-saque';

    public static function daEmpresa(string $companyUuid): ?object
    {
        return DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->first();
    }

    /** A URL que a central cola no Asaas (Integrações → Mecanismos de segurança). */
    public static function urlDaValidacao(): string
    {
        return rtrim((string) config('app.url'), '/') . static::CAMINHO_DA_VALIDACAO;
    }

    /** Os limites da conexão (ou os padrões), já como números. */
    public static function limites(?object $conexao): array
    {
        if (!$conexao) {
            return Saque::PADROES;
        }

        return [
            'minimo'     => round((float) $conexao->minimo, 2),
            'maximo_dia' => round((float) $conexao->maximo_dia, 2),
            'saques_dia' => (int) $conexao->saques_dia,
            'taxa'       => round((float) $conexao->taxa, 2),
        ];
    }

    /** A conexão no formato do console: nada da chave nem do token. */
    public static function publico(?object $conexao, string $companyUuid): array
    {
        $base = ['conectada' => $conexao !== null, 'ligada' => Saque::ligada(), 'cofre' => Cofre::disponivel()];
        if (!$conexao) {
            return $base;
        }

        return $base + [
            'conta'                   => [
                'nome'      => $conexao->conta_nome,
                'documento' => static::documentoEscondido($conexao->conta_documento),
                'wallet'    => (string) $conexao->wallet_id,
            ],
            'conectada_em'            => Saque::iso($conexao->conectada_em, $companyUuid),
            'token_gerado'            => $conexao->token_hash !== null,
            'url_da_validacao'        => static::urlDaValidacao(),
            'validacao_confirmada_em' => Saque::iso($conexao->validacao_confirmada_em, $companyUuid),
            'teste_pedido_em'         => Saque::iso($conexao->teste_pedido_em, $companyUuid),
            'saque_ligado'            => (bool) $conexao->saque_ligado,
            'limites'                 => static::limites($conexao),
        ];
    }

    /** CPF "***.456.789-**", CNPJ "**.345.678/0001-**"; outro tamanho, só os 4 últimos. */
    public static function documentoEscondido(?string $documento): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $documento);
        if ($digitos === '') {
            return null;
        }
        if (strlen($digitos) === 11) {
            return '***.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3) . '-**';
        }
        if (strlen($digitos) === 14) {
            return '**.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3) . '/' . substr($digitos, 8, 4) . '-**';
        }

        return Saque::chaveEscondida($digitos, 'cpf');
    }

    /** Conecta (ou troca a chave pela de outra chave da MESMA conta). Devolve a linha gravada. */
    public static function conectar(string $companyUuid, $chaveApi, ?string $usuarioUuid): object
    {
        $chave = is_string($chaveApi) ? trim($chaveApi) : '';
        if (mb_strlen($chave) < static::TAMANHO_MINIMO_DA_CHAVE || preg_match('/\s/', $chave)) {
            throw new \InvalidArgumentException('Chave de API inválida.');
        }
        if (!Cofre::disponivel()) {
            throw new CofreIndisponivel();
        }

        $asaas    = new AsaasCliente($chave);
        $dados    = static::exigir($asaas->dadosComerciais())->dados ?? [];
        $carteira = AsaasCliente::walletDa(static::exigir($asaas->carteiras()));
        if ($carteira === null) {
            throw new \InvalidArgumentException('O Asaas não informou a carteira desta conta.');
        }

        $atual = static::daEmpresa($companyUuid);
        if ($atual && (string) $atual->wallet_id !== $carteira) {
            throw new \InvalidArgumentException('Esta chave é de outra conta Asaas. Para mudar de conta, desconecte e conecte de novo.');
        }

        $agora  = now()->format('Y-m-d H:i:s');
        $nome   = trim((string) ($dados['name'] ?? $dados['companyName'] ?? ''));
        $campos = [
            'chave_cifrada'   => Cofre::cifrar($chave),
            'conta_nome'      => $nome !== '' ? mb_substr($nome, 0, 255) : null,
            'conta_documento' => preg_replace('/\D/', '', (string) ($dados['cpfCnpj'] ?? '')) ?: null,
            'wallet_id'       => $carteira,
            'conectada_por'   => $usuarioUuid,
            'conectada_em'    => $agora,
            'updated_at'      => $agora,
        ];
        if ($atual) {
            DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->update($campos);
        } else {
            DB::table(Saque::CONEXOES)->insert($campos + ['company_uuid' => $companyUuid, 'created_at' => $agora]);
        }
        Log::info('[entregas] saque: conta Asaas conectada', ['empresa' => $companyUuid, 'wallet' => $carteira, 'por' => $usuarioUuid, 'troca' => (bool) $atual]);

        return static::daEmpresa($companyUuid);
    }

    /** Apaga a conexão inteira (chave, token, limites). Com saque em andamento, recusa: sem chave ninguém o acompanha. */
    public static function desconectar(string $companyUuid, ?string $usuarioUuid): void
    {
        if (!static::daEmpresa($companyUuid)) {
            throw new \InvalidArgumentException('A conta Asaas não está conectada.');
        }
        $emAndamento = DB::table(Saque::TABELA)->where('company_uuid', $companyUuid)->whereIn('situacao', Saque::EM_ANDAMENTO)->exists();
        if ($emAndamento) {
            throw new \InvalidArgumentException('Há saque em andamento; espere ele terminar.');
        }
        DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->delete();
        Log::info('[entregas] saque: conta Asaas desconectada', ['empresa' => $companyUuid, 'por' => $usuarioUuid]);
    }

    /** Gera o token da validação (devolvido só aqui). Token novo apaga a validação testada e desliga o saque. */
    public static function gerarToken(string $companyUuid): string
    {
        if (!static::daEmpresa($companyUuid)) {
            throw new \InvalidArgumentException('Conecte a conta Asaas primeiro.');
        }
        $token = bin2hex(random_bytes(24));
        $agora = now()->format('Y-m-d H:i:s');
        DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->update([
            'token_hash'              => hash('sha256', $token),
            'token_gerado_em'         => $agora,
            'validacao_confirmada_em' => null,
            'teste_transfer_id'       => null,
            'teste_pedido_em'         => null,
            'saque_ligado'            => false,
            'updated_at'              => $agora,
        ]);
        Log::info('[entregas] saque: token da validação gerado', ['empresa' => $companyUuid]);

        return $token;
    }

    /**
     * Manda R$ 1,00 para a chave Pix do admin; a validação do Entregas precisa recusar (e aí confirma). Um teste por vez
     * (o anterior ainda sem resposta da validação segura por MINUTOS_DA_VALIDACAO) e no máximo TESTES_POR_DIA por dia
     * (de Brasília); grava e loga quem pediu, com a chave Pix escondida.
     */
    public static function testarValidacao(string $companyUuid, $tipo, $chavePix, ?string $usuarioUuid): object
    {
        $conexao = static::daEmpresa($companyUuid);
        if (!$conexao) {
            throw new \InvalidArgumentException('Conecte a conta Asaas primeiro.');
        }
        if ($conexao->token_hash === null) {
            throw new \InvalidArgumentException('Gere o token e configure a validação no Asaas antes de testar.');
        }
        $pix = ChavePix::validar($tipo, $chavePix);
        if (isset($pix['erro'])) {
            throw new \InvalidArgumentException($pix['erro']);
        }
        $chave = Cofre::decifrar((string) $conexao->chave_cifrada);
        if ($chave === null) {
            throw new CofreIndisponivel();
        }

        $agora = now()->format('Y-m-d H:i:s');
        $hoje  = Financeiro::hoje($companyUuid);
        $anterior = DB::transaction(function () use ($companyUuid, $usuarioUuid, $agora, $hoje) {
            // a linha travada: dois pedidos ao mesmo tempo não passam juntos pelo "um por vez" nem pela contagem do dia
            $linha = DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->lockForUpdate()->first();
            if (!$linha) {
                throw new \InvalidArgumentException('Conecte a conta Asaas primeiro.');
            }
            $recente = now()->subMinutes(Saque::MINUTOS_DA_VALIDACAO)->format('Y-m-d H:i:s');
            if ($linha->validacao_confirmada_em === null && $linha->teste_pedido_em !== null && (string) $linha->teste_pedido_em > $recente) {
                throw new \InvalidArgumentException('Espere o resultado do teste anterior.');
            }
            $testes = (string) $linha->testes_dia === $hoje ? (int) $linha->testes_no_dia : 0;
            if ($testes >= static::TESTES_POR_DIA) {
                throw new \InvalidArgumentException('Limite de testes de hoje atingido.');
            }
            DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->update([
                'teste_pedido_em'         => $agora,
                'teste_pedido_por'        => $usuarioUuid,
                'teste_transfer_id'       => null,
                'validacao_confirmada_em' => null,
                'testes_no_dia'           => $testes + 1,
                'testes_dia'              => $hoje,
                'updated_at'              => $agora,
            ]);

            return $linha;
        });
        $resposta = (new AsaasCliente($chave))->transferirPix(static::VALOR_DO_TESTE, $pix['chave'], Saque::TIPOS_ASAAS[$pix['tipo']], 'Teste da validação de saque do Entregas', 'teste:' . Str::uuid());
        $id       = $resposta->dados['id'] ?? null;
        if ($resposta->ok() && is_string($id) && $id !== '') {
            // whereNull: se a validação chegou antes e já gravou o id, fica o dela
            DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->whereNull('teste_transfer_id')->update(['teste_transfer_id' => $id]);
        } elseif (!$resposta->ok()) {
            if ($confirmada = static::desfazerTeste($companyUuid, $anterior, $agora)) {
                return $confirmada;
            }
            if ($resposta->semResposta()) {
                throw new ErroDoAsaas('O Asaas não respondeu. Tente de novo.');
            }
            throw new \InvalidArgumentException($resposta->mensagem('O Asaas recusou o teste.'));
        }
        Log::info('[entregas] saque: teste da validação pedido', ['empresa' => $companyUuid, 'por' => $usuarioUuid, 'transfer' => $id, 'chave' => Saque::chaveEscondida($pix['chave'], $pix['tipo'])]);

        return static::daEmpresa($companyUuid);
    }

    /**
     * O teste não saiu (recusa ou sem resposta): volta a validação e o teste ao que eram antes, para um teste que falha não
     * desligar o saque de uma central já confirmada. Se a validação confirmou durante a chamada (o Asaas a chama antes de
     * responder), não desfaz e devolve a conexão: quem chamou responde sem erro.
     */
    private static function desfazerTeste(string $companyUuid, object $anterior, string $pedidoEm): ?object
    {
        $voltou = DB::table(Saque::CONEXOES)
            ->where('company_uuid', $companyUuid)
            ->where('teste_pedido_em', $pedidoEm)
            ->whereNull('validacao_confirmada_em')
            ->update([
                'validacao_confirmada_em' => $anterior->validacao_confirmada_em,
                'teste_transfer_id'       => $anterior->teste_transfer_id,
                'teste_pedido_em'         => $anterior->teste_pedido_em,
                'teste_pedido_por'        => $anterior->teste_pedido_por,
            ]);
        if ($voltou > 0) {
            return null;
        }
        $linha = static::daEmpresa($companyUuid);

        return $linha && $linha->validacao_confirmada_em !== null ? $linha : null;
    }

    /** Limites e o interruptor da central. Ligar exige a validação testada; desligar sempre pode. */
    public static function configurar(string $companyUuid, array $dados, ?string $usuarioUuid): object
    {
        $atual = static::daEmpresa($companyUuid);
        if (!$atual) {
            throw new \InvalidArgumentException('Conecte a conta Asaas primeiro.');
        }
        $campos = [];
        foreach (['minimo' => 'mínimo por saque', 'maximo_dia' => 'máximo por dia', 'taxa' => 'taxa'] as $campo => $rotulo) {
            if (array_key_exists($campo, $dados)) {
                $valor = static::numero($dados[$campo]);
                if ($valor === null) {
                    throw new \InvalidArgumentException("Valor inválido: {$rotulo}.");
                }
                $campos[$campo] = $valor;
            }
        }
        if (array_key_exists('saques_dia', $dados)) {
            $quantos = filter_var($dados['saques_dia'], FILTER_VALIDATE_INT);
            if ($quantos === false || $quantos < 1 || $quantos > 20) {
                throw new \InvalidArgumentException('Saques por dia: de 1 a 20.');
            }
            $campos['saques_dia'] = $quantos;
        }

        $final = array_merge(static::limites($atual), $campos);
        if ($final['minimo'] < static::MINIMO_POR_SAQUE) {
            throw new \InvalidArgumentException('O mínimo por saque é de pelo menos R$ 2,00.');
        }
        if ($final['maximo_dia'] < $final['minimo']) {
            throw new \InvalidArgumentException('O máximo por dia precisa ser maior ou igual ao mínimo por saque.');
        }
        if ($final['taxa'] < 0 || $final['taxa'] > 10) {
            throw new \InvalidArgumentException('A taxa vai de R$ 0,00 a R$ 10,00.');
        }
        if ($final['taxa'] >= $final['minimo']) {
            throw new \InvalidArgumentException('A taxa precisa ser menor que o mínimo por saque.');
        }

        if (array_key_exists('saque_ligado', $dados)) {
            $ligar = filter_var($dados['saque_ligado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($ligar === null) {
                throw new \InvalidArgumentException('Informe se o saque fica ligado.');
            }
            if ($ligar && $atual->validacao_confirmada_em === null) {
                throw new \InvalidArgumentException('Teste a validação de saque antes de ligar.');
            }
            $campos['saque_ligado'] = $ligar;
        }

        $agora = now()->format('Y-m-d H:i:s');
        DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->update($campos + ['atualizada_por' => $usuarioUuid, 'updated_at' => $agora]);
        Log::info('[entregas] saque: configuração da central', ['empresa' => $companyUuid, 'por' => $usuarioUuid, 'campos' => array_keys($campos)]);

        return static::daEmpresa($companyUuid);
    }

    /** O maior valor que cabe nas colunas decimal(10,2). */
    public const MAIOR_VALOR = 9999999.99;

    /** "10,50", "10.5", 10.5 → 10.5; negativo, infinito, NaN, acima de MAIOR_VALOR ou outra coisa → null. */
    private static function numero($valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            $numero = (float) $valor;

            return is_finite($numero) && $numero >= 0 && $numero <= static::MAIOR_VALOR ? round($numero, 2) : null;
        }
        if (is_string($valor) && preg_match('/^\d{1,7}([.,]\d{1,2})?$/', trim($valor))) {
            return round((float) str_replace(',', '.', trim($valor)), 2);
        }

        return null;
    }

    /** A resposta, se deu certo; chave recusada → 422 com a orientação; sem resposta ou outro erro → 502. */
    private static function exigir(RespostaAsaas $resposta): RespostaAsaas
    {
        if ($resposta->ok()) {
            return $resposta;
        }
        if ($resposta->chaveRecusada()) {
            throw new \InvalidArgumentException('Chave de API inválida ou sem permissão. Gere uma nova no Asaas (Integrações → Chaves de API).');
        }
        if ($resposta->semResposta()) {
            throw new ErroDoAsaas('O Asaas não respondeu. Tente de novo.');
        }

        throw new ErroDoAsaas($resposta->mensagem('O Asaas recusou o pedido.'));
    }
}
