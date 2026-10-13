<?php

namespace App\Support\Entregas\Financeiro;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: pagamentos aos motoboys e recebimentos das lojas (entregas_pagamentos). Só registro: o
 * dinheiro sai e entra fora do sistema. Cada um gera, na mesma transação, o lançamento negativo `pagamento` (conta
 * motoboy) ou `recebimento` (conta loja) com o mesmo pagamento_uuid. Estornar marca estornado_em e cria o estorno
 * positivo desse lançamento (o saldo volta). O comprovante fica no disco 'local' (privado) e só sai por URL assinada.
 *
 * Erros de entrada (valor, forma, data, conta, arquivo) lançam \InvalidArgumentException com a mensagem em pt-BR, para
 * o controller devolver 422.
 */
final class Pagamentos
{
    public const TABELA = 'entregas_pagamentos';

    /** Nome da rota GET v1/entregas/comprovantes/{uuid} (RouteServiceProvider, tarefa B2): a URL assinada aponta para ela. */
    public const ROTA_DO_COMPROVANTE = 'entregas.comprovantes.mostrar';

    public const DISCO = 'local';

    public const PASTA = 'entregas/comprovantes';

    public const TAMANHO_MAXIMO = 10 * 1024 * 1024;

    public const MINUTOS_DA_URL = 30;

    /** Tipos aceitos no comprovante (detectados no servidor) => extensão gravada. */
    public const TIPOS_DO_COMPROVANTE = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
        'image/heif'      => 'heif',
        'image/gif'       => 'gif',
        'application/pdf' => 'pdf',
    ];

    /**
     * Registra pagamento (conta motoboy) ou recebimento (conta loja): linha em entregas_pagamentos + lançamento
     * 'pagamento'|'recebimento' com valor negativo, na mesma transação. $comprovante opcional (UploadedFile, imagem ou PDF,
     * até 10 MB) salvo no disco 'local' em entregas/comprovantes/<company>/<uuid>.<ext>. Devolve o objeto do pagamento.
     * Valor acima do saldo é aceito (vira adiantamento).
     */
    public static function registrar(string $companyUuid, string $contaTipo, string $contaUuid, float $valor, string $forma,
        string $data, ?UploadedFile $comprovante, ?string $observacao, ?string $usuarioUuid): object
    {
        if (!in_array($contaTipo, [Financeiro::CONTA_MOTOBOY, Financeiro::CONTA_LOJA], true) || trim($contaUuid) === '') {
            throw new \InvalidArgumentException('Conta inválida.');
        }
        $valor = round($valor, 2);
        if ($valor <= 0) {
            throw new \InvalidArgumentException('O valor precisa ser maior que zero.');
        }
        if (!in_array($forma, Financeiro::FORMAS, true)) {
            throw new \InvalidArgumentException('Forma de pagamento inválida.');
        }
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$dia || $dia->format('Y-m-d') !== $data) {
            throw new \InvalidArgumentException('Data inválida.');
        }
        $extensao = $comprovante ? static::extensaoDoComprovante($comprovante) : null;

        $uuid    = (string) Str::uuid();
        $caminho = null;
        if ($comprovante) {
            $caminho = Storage::disk(static::DISCO)->putFileAs(static::PASTA . '/' . $companyUuid, $comprovante, $uuid . '.' . $extensao);
            if (!$caminho) {
                throw new \RuntimeException('Não foi possível salvar o comprovante.');
            }
        }

        $agora  = now()->format('Y-m-d H:i:s');
        $obs    = $observacao !== null && trim($observacao) !== '' ? mb_substr(trim($observacao), 0, 500) : null;
        $linha  = [
            'uuid'               => $uuid,
            'company_uuid'       => $companyUuid,
            'conta_tipo'         => $contaTipo,
            'conta_uuid'         => $contaUuid,
            'valor'              => $valor,
            'forma'              => $forma,
            'data'               => $data,
            'comprovante_path'   => $caminho,
            'comprovante_nome'   => $comprovante ? mb_substr((string) $comprovante->getClientOriginalName(), 0, 255) : null,
            'comprovante_tipo'   => $comprovante ? (string) $comprovante->getMimeType() : null,
            'observacao_interna' => $obs,
            'criado_por'         => $usuarioUuid,
            'created_at'         => $agora,
            'updated_at'         => $agora,
        ];

        try {
            DB::transaction(function () use ($linha, $companyUuid, $contaTipo, $contaUuid, $valor, $data, $uuid, $obs, $usuarioUuid) {
                DB::table(static::TABELA)->insert($linha);
                Lancamentos::inserir([
                    'company_uuid'       => $companyUuid,
                    'conta_tipo'         => $contaTipo,
                    'conta_uuid'         => $contaUuid,
                    'tipo'               => $contaTipo === Financeiro::CONTA_MOTOBOY ? 'pagamento' : 'recebimento',
                    'valor'              => -$valor,
                    'data_referencia'    => $data,
                    'pagamento_uuid'     => $uuid,
                    'observacao_interna' => $obs,
                    'criado_por'         => $usuarioUuid,
                ]);
            });
        } catch (\Throwable $e) {
            if ($caminho) {
                try {
                    Storage::disk(static::DISCO)->delete($caminho);
                } catch (\Throwable $falha) {
                    Log::warning('[entregas] financeiro: comprovante órfão no disco', ['caminho' => $caminho, 'erro' => get_class($falha)]);
                }
            }
            throw $e;
        }

        return static::porUuid($companyUuid, $uuid) ?? (object) $linha;
    }

    public static function porUuid(string $companyUuid, string $uuid): ?object
    {
        return DB::table(static::TABELA)->where('company_uuid', $companyUuid)->where('uuid', $uuid)->first();
    }

    /** Marca estornado_em e cria o estorno do lançamento do pagamento. false se já estornado. */
    public static function estornar(object $pagamento, ?string $usuarioUuid): bool
    {
        return DB::transaction(function () use ($pagamento, $usuarioUuid) {
            $agora    = now()->format('Y-m-d H:i:s');
            $marcados = DB::table(static::TABELA)
                ->where('company_uuid', $pagamento->company_uuid)
                ->where('uuid', $pagamento->uuid)
                ->whereNull('estornado_em')
                ->update(['estornado_em' => $agora, 'estornado_por' => $usuarioUuid, 'updated_at' => $agora]);
            if ($marcados < 1) {
                return false;
            }

            $lancamento = DB::table(Lancamentos::TABELA)
                ->where('company_uuid', $pagamento->company_uuid)
                ->where('pagamento_uuid', $pagamento->uuid)
                ->whereIn('tipo', ['pagamento', 'recebimento'])
                ->first();
            if ($lancamento) {
                $motivo = $lancamento->tipo === 'pagamento' ? 'Pagamento estornado' : 'Recebimento estornado';
                Lancamentos::estornar($lancamento, $usuarioUuid, $motivo);
            }

            return true;
        });
    }

    /** URL assinada (30 min) de GET v1/entregas/comprovantes/{uuid}; null sem comprovante. */
    public static function urlDoComprovante(object $pagamento): ?string
    {
        if (empty($pagamento->comprovante_path)) {
            return null;
        }

        return URL::temporarySignedRoute(static::ROTA_DO_COMPROVANTE, now()->addMinutes(static::MINUTOS_DA_URL), ['uuid' => $pagamento->uuid]);
    }

    /** O pagamento (não estornado) mais recente da conta: maior data, depois o último registrado. */
    public static function ultimoDaConta(string $companyUuid, string $contaTipo, string $contaUuid): ?object
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', $contaTipo)
            ->where('conta_uuid', $contaUuid)
            ->whereNull('estornado_em')
            ->orderBy('data', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /** Extensão gravada para o comprovante, pelo tipo detectado no servidor; recusa o que não é imagem nem PDF ou passa de 10 MB. */
    private static function extensaoDoComprovante(UploadedFile $arquivo): string
    {
        $tipo = strtolower((string) $arquivo->getMimeType());
        if (!isset(static::TIPOS_DO_COMPROVANTE[$tipo])) {
            throw new \InvalidArgumentException('O comprovante precisa ser uma imagem ou um PDF.');
        }
        if ((int) $arquivo->getSize() > static::TAMANHO_MAXIMO) {
            throw new \InvalidArgumentException('O comprovante pode ter até 10 MB.');
        }

        return static::TIPOS_DO_COMPROVANTE[$tipo];
    }
}
