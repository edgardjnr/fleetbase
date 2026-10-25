<?php

namespace App\Http\Middleware;

use App\Support\Entregas\ImagensPadrao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entregas RestaurantePro: nas respostas JSON da API, as imagens padrão do bucket do Fleetbase (avatar sem foto,
 * ícones de motoboy, local e veículo...) viram as cópias servidas pelo console (ver ImagensPadrao). Global: vale para o
 * console, o portal da loja e o app do motoboy. Só lê o corpo quando ele cita "amazonaws.com".
 */
class TrocarImagensDoFleetbase
{
    public function handle(Request $request, Closure $next)
    {
        $resposta = $next($request);

        if ($resposta instanceof StreamedResponse || $resposta instanceof BinaryFileResponse) {
            return $resposta;
        }

        $tipo = (string) $resposta->headers->get('Content-Type', '');
        if (!str_contains($tipo, 'json')) {
            return $resposta;
        }

        $corpo = $resposta->getContent();
        if (!is_string($corpo) || !str_contains($corpo, 'amazonaws.com')) {
            return $resposta;
        }

        $novo = ImagensPadrao::trocar($corpo);
        if ($novo !== $corpo) {
            $resposta->setContent($novo);
            // o tamanho mudou: sem o Content-Length antigo (se havia), o servidor recalcula
            $resposta->headers->remove('Content-Length');
        }

        return $resposta;
    }
}
