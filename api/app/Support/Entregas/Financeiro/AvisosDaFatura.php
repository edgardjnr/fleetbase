<?php

namespace App\Support\Entregas\Financeiro;

use App\Notifications\Entregas\Email\FaturaEmitida;
use App\Notifications\Entregas\Email\FaturaVencida;
use App\Notifications\Entregas\Email\LembreteDeFatura;
use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Entregas RestaurantePro: os e-mails das faturas para a loja (desenho, "E-mails para a loja"):
 *
 * - emitida: na emissão (B3: FinanceiroLojasController@emitir e @reenviar); pode repetir (reenviar);
 * - lembrete: na véspera do vencimento, uma vez, só com valor em aberto (entregas:financeiro-avisos, 09:00);
 * - vencida: no dia seguinte ao vencimento (ou depois), uma vez, só com valor em aberto (mesma rotina).
 *
 * Destinatários: o e-mail da loja (Vendor) e os dos usuários ativos do portal dela, num envio só. Sem destinatário, sem a
 * chave ENTREGAS_FINANCEIRO, fora da situação 'emitida' ou com falha no envio: devolve false e não grava nada (o painel
 * mostra "não enviada por e-mail" pelo email_enviado_em nulo). Ao enviar, grava email_enviado_em, lembrete_enviado_em ou
 * aviso_vencida_enviado_em (hora de Brasília). O envio é na hora (a notificação não vai para a fila), para o horário
 * gravado ser o do envio. O log nunca traz os e-mails.
 */
final class AvisosDaFatura
{
    public static function emitida(object $fatura): bool
    {
        return static::enviar($fatura, FaturaEmitida::class, 'email_enviado_em', false);
    }

    public static function lembrete(object $fatura): bool
    {
        return static::enviar($fatura, LembreteDeFatura::class, 'lembrete_enviado_em', true);
    }

    public static function vencida(object $fatura): bool
    {
        return static::enviar($fatura, FaturaVencida::class, 'aviso_vencida_enviado_em', true);
    }

    /**
     * E-mails da loja: o do Vendor e os dos contatos `customer` com vínculo ativo (VendorPersonnel, como o
     * LojaDoUsuario), em minúsculas, sem repetir, sem vazio nem inválido. Loja de outra empresa (ou apagada): nenhum.
     */
    public static function emailsDaLoja(string $companyUuid, string $vendorUuid): array
    {
        $loja = DB::table('vendors')->where('company_uuid', $companyUuid)->where('uuid', $vendorUuid)->whereNull('deleted_at')->first();
        if (!$loja) {
            return [];
        }

        $candidatos = [$loja->email ?? null];
        $contatos   = [];
        foreach (DB::table('vendor_personnels')->where('vendor_uuid', $vendorUuid)->where('status', 'active')->whereNull('deleted_at')->pluck('contact_uuid') as $uuid) {
            if ($uuid !== null) {
                $contatos[] = (string) $uuid;
            }
        }
        if ($contatos) {
            foreach (DB::table('contacts')->whereIn('uuid', $contatos)->where('company_uuid', $companyUuid)->where('type', 'customer')->whereNull('deleted_at')->pluck('email') as $email) {
                $candidatos[] = $email;
            }
        }

        $emails = [];
        foreach ($candidatos as $email) {
            $email = mb_strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /** A fatura no portal da loja: <console>/customer-portal/financeiro/<uuid> (rota portal.fatura do engine). */
    public static function urlNoPortal(string $faturaUuid): string
    {
        return Utils::consoleUrl('customer-portal/financeiro/' . $faturaUuid);
    }

    private static function enviar(object $fatura, string $aviso, string $coluna, bool $soUmaVezEmAberto): bool
    {
        $contexto = ['fatura' => $fatura->uuid ?? null, 'aviso' => substr($aviso, strrpos($aviso, '\\') + 1)];

        if (!Financeiro::ligada()) {
            Log::info('[entregas] financeiro: e-mail da fatura não enviado (ENTREGAS_FINANCEIRO desligado)', $contexto);

            return false;
        }

        // relida do banco: quem chama pode ter um objeto de antes da emissão ou do envio anterior
        $fatura = FaturasDaLoja::porUuid((string) $fatura->company_uuid, (string) $fatura->uuid);
        if (!$fatura || $fatura->situacao !== 'emitida' || ($soUmaVezEmAberto && $fatura->{$coluna} !== null)) {
            return false;
        }

        // a mesma situação do console e do portal (Faturas::situacoesDaLoja)
        $situacao = Faturas::situacoesDaLoja((string) $fatura->company_uuid, (string) $fatura->vendor_uuid)[$fatura->uuid] ?? null;
        $emAberto = (float) ($situacao['em_aberto'] ?? 0);
        if ($soUmaVezEmAberto && $emAberto <= 0) {
            return false;
        }

        $emails = static::emailsDaLoja($fatura->company_uuid, $fatura->vendor_uuid);
        if (!$emails) {
            Log::info('[entregas] financeiro: fatura sem e-mail para enviar', $contexto);

            return false;
        }

        try {
            Notification::route('mail', $emails)->notify(new $aviso(static::dados($fatura, $emAberto)));
        } catch (\Throwable $erro) {
            Log::warning('[entregas] financeiro: falha ao enviar o e-mail da fatura', $contexto + ['erro' => get_class($erro)]);

            return false;
        }

        $agora = now()->format('Y-m-d H:i:s');
        DB::table(FaturasDaLoja::TABELA)->where('id', $fatura->id)->update([$coluna => $agora, 'updated_at' => $agora]);
        Log::info('[entregas] financeiro: e-mail da fatura enviado', $contexto + ['destinatarios' => count($emails)]);

        return true;
    }

    /** O que o e-mail mostra (AvisoDeFatura::$fatura). */
    private static function dados(object $fatura, float $emAberto): array
    {
        return [
            'numero'         => Financeiro::numeroDaFatura($fatura->numero === null ? null : (int) $fatura->numero),
            'loja'           => DB::table('vendors')->where('uuid', $fatura->vendor_uuid)->value('name'),
            'empresa'        => DB::table('companies')->where('uuid', $fatura->company_uuid)->value('name'),
            'periodo_inicio' => substr((string) $fatura->periodo_inicio, 0, 10),
            'periodo_fim'    => substr((string) $fatura->periodo_fim, 0, 10),
            'vencimento'     => substr((string) $fatura->vencimento, 0, 10),
            'total'          => round((float) $fatura->total, 2),
            'em_aberto'      => round($emAberto, 2),
            'url'            => static::urlNoPortal((string) $fatura->uuid),
        ];
    }
}
