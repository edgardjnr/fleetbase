<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Financeiro\Pagamentos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Entregas RestaurantePro: o comprovante de um pagamento ou recebimento do Financeiro (imagem ou PDF, no disco `local`,
 * privado). GET v1/entregas/comprovantes/{uuid}, rota nomeada Pagamentos::ROTA_DO_COMPROVANTE, sem token: só abre pela
 * URL assinada de 30 min (middleware `signed`) que o Pagamentos::urlDoComprovante gera para o console (extrato e resposta
 * do registro) e para o app (recibo). A assinatura é a proteção: quem não tem o link não acha o arquivo.
 */
class ComprovantesController extends Controller
{
    public function mostrar(Request $request, string $uuid)
    {
        $pagamento = DB::table(Pagamentos::TABELA)->where('uuid', $uuid)->first();
        if (!$pagamento || empty($pagamento->comprovante_path)) {
            return response()->json(['errors' => ['Comprovante não encontrado.']], 404);
        }

        $disco = Storage::disk(Pagamentos::DISCO);
        if (!$disco->exists($pagamento->comprovante_path)) {
            return response()->json(['errors' => ['Comprovante não encontrado.']], 404);
        }

        // o nome que o navegador mandou vai no Content-Disposition: barra e caractere de controle derrubariam a resposta (o
        // Symfony recusa) e não servem num nome de arquivo
        $nome = $pagamento->comprovante_nome ? preg_replace('/[\x00-\x1f\x7f\/\\\\]/u', '_', (string) $pagamento->comprovante_nome) : null;

        return $disco->response($pagamento->comprovante_path, $nome ?: null, [
            // o tipo detectado no envio (só imagem ou PDF): o navegador não adivinha outro
            'Content-Type'           => $pagamento->comprovante_tipo ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=1800',
        ], 'inline');
    }
}
