<?php

// Integração iFood: a fila própria dos jobs iFood (antes da primeira loja real). Os jobs ProcessarPedidoIfood e
// EnviarAcaoIfood vão para a fila "ifood" (FilaIfood::NOME), atendida pelo worker queue-ifood do stack; o worker queue
// atende "default,ifood" (os push primeiro; o iFood só quando estiver livre, e assim nada para se o queue-ifood ainda
// não existir). A conexão redis tem after_commit: job ou broadcast disparado dentro de uma transação só entra na fila
// depois do commit (com dois workers, o broadcast do pedido criado pelo CriadorDoPedidoIfood podia ser lido antes).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/fila-ifood.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Jobs\Entregas\EnviarAcaoIfood;
use App\Jobs\Entregas\ProcessarPedidoIfood;
use App\Support\Entregas\Ifood\FilaIfood;

// o texto dos arquivos sem o \r da cópia de trabalho no Windows (autocrlf)
function textoDe(string $arquivo): string
{
    return str_replace("\r\n", "\n", file_get_contents($arquivo));
}

echo '== Jobs na fila ifood' . PHP_EOL;
confere(FilaIfood::NOME === 'ifood', 'o nome da fila é "ifood"');
confere((new ProcessarPedidoIfood('pedido-1'))->queue === 'ifood', 'ProcessarPedidoIfood vai para a fila ifood');
$acao = new EnviarAcaoIfood('uuid-1');
confere($acao->queue === 'ifood', 'EnviarAcaoIfood vai para a fila ifood');
confere($acao->afterCommit === true, 'EnviarAcaoIfood continua com afterCommit');

echo '== after_commit na conexão redis' . PHP_EOL;
$fila = textoDe('/repo/api/config/queue.php');
confere((bool) preg_match("/'redis' => \[[^\]]*'after_commit' => true,/s", $fila), "conexão redis com 'after_commit' => true");

echo '== Workers no stack' . PHP_EOL;
$stack = textoDe('/repo/deploy/docker-stack.yml');
confere(str_contains($stack, 'command: ["php", "artisan", "queue:work", "--queue=default,ifood"]'), 'o queue atende default,ifood (push primeiro)');
confere((bool) preg_match('/\n  queue-ifood:\n    image: entregas-api:latest\n    command: \["php", "artisan", "queue:work", "--queue=ifood"\]/', $stack), 'o queue-ifood atende só a fila ifood');
$servicoIfood = substr($stack, strpos($stack, "\n  queue-ifood:"), 600);
confere(str_contains($servicoIfood, 'environment: *api-env') && str_contains($servicoIfood, 'queue:status') && str_contains($servicoIfood, 'networks: [internal]'), 'o queue-ifood tem as variáveis, o healthcheck e a rede do queue');

echo '== Deploy' . PHP_EOL;
$atualizar = textoDe('/repo/deploy/atualizar.sh');
confere(str_contains($atualizar, "if tem_servico queue-ifood; then\n    atualiza queue-ifood"), 'o atualizar.sh atualiza o queue-ifood quando ele existe');

resumo();
