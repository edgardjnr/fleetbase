<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Ifood\HomologacaoIfood as Cenarios;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Entregas RestaurantePro: cenários de erro para a homologação do iFood (App\Support\Entregas\Ifood\HomologacaoIfood).
 * Roteiro: docs/ifood/homologacao-roteiro.md. Rodar no container da API:
 *   docker exec -it $(docker ps -q -f name=entregas_application) php artisan entregas:ifood-homologacao situacao
 */
class HomologacaoIfood extends Command
{
    protected $signature = 'entregas:ifood-homologacao
        {acao : situacao | token-vencido | token-invalido | simular | limpar}
        {--loja= : public_id do Fornecedor (vendor_…) ou merchant id; sem ela, a única loja vinculada}
        {--operacao= : em simular: polling, ack, pedido, assignDriver, goingToOrigin, arrivedAtOrigin, dispatch, arrivedAtDestination ou verifyDeliveryCode}
        {--status= : em simular: 400, 404, 409, 429, 500 ou 503}
        {--espera= : em simular com 429: o Retry-After, em segundos (padrão 60)}';

    protected $description = 'iFood: cenários de erro para a homologação (só com ENTREGAS_IFOOD_HOMOLOGACAO=1)';

    public function handle(): int
    {
        try {
            switch ($this->argument('acao')) {
                case 'situacao':
                    $this->line(json_encode(Cenarios::situacao(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    break;
                case 'token-vencido':
                    $vinculo = Cenarios::vinculo($this->option('loja'));
                    Cenarios::vencerToken($vinculo);
                    $this->info("Token da loja {$vinculo->merchant_id} marcado como vencido. Na próxima chamada (polling em até 30 s) sai o log \"token renovado\" com motivo \"vencimento\".");
                    break;
                case 'token-invalido':
                    $vinculo = Cenarios::vinculo($this->option('loja'));
                    Cenarios::invalidarToken($vinculo);
                    $this->info("Token da loja {$vinculo->merchant_id} trocado por um inválido. No próximo polling: 401 do iFood, \"token recusado (401), renovando\", \"token renovado\" com motivo \"401\" e a chamada repetida.");
                    break;
                case 'simular':
                    $operacao = (string) $this->option('operacao');
                    $status   = (int) $this->option('status');
                    $espera   = $this->option('espera') !== null ? (int) $this->option('espera') : null;
                    Cenarios::simular($operacao, $status, $espera);
                    $this->info("A próxima chamada \"{$operacao}\" sobe com {$status}, sem ir ao iFood (log \"erro simulado (homologação)\"). Vale por 10 min.");
                    break;
                case 'limpar':
                    Cenarios::limpar();
                    $this->info('Erros simulados pendentes removidos.');
                    break;
                default:
                    $this->error('Ação desconhecida. Use: situacao, token-vencido, token-invalido, simular ou limpar.');

                    return self::FAILURE;
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
