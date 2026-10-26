<?php

// Saque Pix: a rotina entregas:saques-conferir (AcompanhamentoDeSaques): consulta as transferências em andamento, adota
// pelo externalReference a que ficou sem id (ou cujo id dá 404 há mais de 30 min) e falha a que nunca chegou ao Asaas.
// O órfão (404) nunca falha sozinho: depois de 6 h de 404 seguidos, um log crítico só. Erro de rede nunca dá por falho;
// um saque com problema não para os outros.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-acompanhamento.php

require __DIR__ . '/stubs-saque.php';

use App\Console\Commands\Entregas\SaquesConferir;
use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;
use App\Support\Entregas\Saques\AcompanhamentoDeSaques;
use App\Support\Entregas\Saques\Saques;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Saques\ValidacaoDeSaque;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teste\Banco;
use Teste\Config;
use Teste\Http as HttpFalso;
use Teste\Relogio;

const UA = 'aaaaaaaa-0000-4000-8000-00000000000a';
const UB = 'aaaaaaaa-0000-4000-8000-00000000000b';
const UC = 'aaaaaaaa-0000-4000-8000-00000000000c';

/** As mensagens dos logs de um nível desde o último reinício. */
function logsDe(string $nivel): array
{
    return array_values(array_map(fn ($r) => $r[1], array_filter(Log::$registros, fn ($r) => $r[0] === $nivel)));
}

echo '== Em processamento' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
motoboyComSaldo('driver-3', 'Pedro Lima');
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_a', 'status' => 'PENDING']);
saqueEnviado('driver-2', UB, 20, ['id' => 'tra_b', 'status' => 'PENDING']);
saqueEnviado('driver-3', UC, 30, ['id' => 'tra_c', 'status' => 'BANK_PROCESSING']);
HttpFalso::$chamadas = [];
Driver::$avisos      = [];
asaas(200, ['id' => 'tra_a', 'status' => 'DONE', 'transactionReceiptUrl' => 'https://www.asaas.com/c/a']);
asaas(200, ['id' => 'tra_b', 'status' => 'FAILED', 'failReason' => 'Chave Pix não encontrada']);
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 2, 'dois saques terminaram');
confere(array_column(HttpFalso::$chamadas, 'url') === ['https://asaas.teste/v3/transfers/tra_a', 'https://asaas.teste/v3/transfers/tra_b', 'https://asaas.teste/v3/transfers/tra_c'], 'consulta cada um pelo id');
confere(saqueDoBanco(UA)->situacao === 'concluido' && saqueDoBanco(UA)->comprovante_url === 'https://www.asaas.com/c/a', 'DONE: concluído com o comprovante');
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->motivo_falha === 'Não foi possível concluir o saque agora.' && saqueDoBanco(UB)->motivo_asaas === 'Chave Pix não encontrada' && saqueDoBanco(UB)->asaas_status === 'FAILED', 'FAILED com failReason: o texto do Asaas em motivo_asaas, a frase nossa em motivo_falha');
confere(saqueDoBanco(UC)->situacao === 'processando', 'sem resposta: continua processando (nunca falha às cegas)');
confere(Driver::$avisos[0][1] instanceof SaqueConcluido && Driver::$avisos[1][1] instanceof SaqueNaoConcluido && count(Driver::$avisos) === 2, 'um push para cada um que terminou');
asaas(200, ['id' => 'tra_c', 'status' => 'CANCELLED']);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UC)->motivo_falha === 'Transferência cancelada no Asaas.' && saqueDoBanco(UC)->motivo_asaas === null, 'CANCELLED sem motivo: "cancelada no Asaas" (texto nosso)');

echo '== Sem id: espera 5 min, depois procura pelo externalReference' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
$a = Saques::pedir('empresa-1', 'driver-1', UA, 10)['saque'];
$b = Saques::pedir('empresa-1', 'driver-2', UB, 20)['saque'];
confere(AcompanhamentoDeSaques::conferir() === 0 && HttpFalso::$chamadas === [], 'recém-criados sem id: não consulta ainda');
Relogio::$agora = '2026-10-27 10:06:00';
asaas(200, ['data' => [['id' => 'tra_z', 'externalReference' => 'saque:outro', 'status' => 'DONE'], ['id' => 'tra_a', 'externalReference' => 'saque:' . UA, 'status' => 'BANK_PROCESSING', 'value' => 10]], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 1, 'um mudou (o que nunca chegou ao Asaas)');
confere(count(HttpFalso::$chamadas) === 1 && HttpFalso::$chamadas[0]['url'] === 'https://asaas.teste/v3/transfers?offset=0&limit=100', 'lista as transferências uma vez só para os dois');
confere(saqueDoBanco(UA)->situacao === 'processando' && saqueDoBanco(UA)->transfer_id === 'tra_a' && saqueDoBanco(UA)->asaas_status === 'BANK_PROCESSING', 'achado pelo externalReference: adotado');
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->motivo_falha === 'Saque interrompido antes de chegar ao Asaas.', 'não achado: falha e devolve');

echo '== Terminou no meio da busca: não é adotado' . PHP_EOL;
centralComSaque();
Saques::pedir('empresa-1', 'driver-1', UC, 10);
Relogio::$agora = '2026-10-27 10:06:00';
HttpFalso::responderCom(function () {
    // outro processo dá o saque por falho (e devolve o valor) enquanto a rotina lista as transferências
    Saques::falhar(saqueDoBanco(UC), 'Falhou em outro processo.');

    return [200, ['data' => [['id' => 'tra_c', 'externalReference' => 'saque:' . UC, 'status' => 'DONE', 'value' => 10]], 'hasMore' => false]];
});
AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(UC)->situacao === 'falhou' && saqueDoBanco(UC)->transfer_id === null && saqueDoBanco(UC)->motivo_falha === 'Falhou em outro processo.', 'o falho (já devolvido) não volta a processando nem conclui: ' . json_encode(saqueDoBanco(UC)));
confere(in_array('[entregas] saque: transferência no Asaas para saque que não está em andamento', logsDe('critical'), true), 'e vai ao log como crítico (o dinheiro saiu e o valor foi devolvido)');

echo '== Lista fora do ar: espera' . PHP_EOL;
centralComSaque();
Saques::pedir('empresa-1', 'driver-1', UC, 10);
Relogio::$agora = '2026-10-27 10:06:00';
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UC)->situacao === 'enviando', 'sem a lista: não falha');

echo '== Sem o secret' . PHP_EOL;
unlink(CHAVE_DO_COFRE);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UC)->situacao === 'enviando' && count(HttpFalso::$chamadas) === 1, 'sem chave: nada muda, nada é chamado');

echo '== Um saque com erro não trava os outros da central' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
Saques::pedir('empresa-1', 'driver-1', UA, 10);
saqueEnviado('driver-2', UB, 20, ['id' => 'tra_b', 'status' => 'PENDING']);
Relogio::$agora = '2026-10-27 10:06:00';
asaas(200, ['data' => [], 'hasMore' => false]);
asaas(200, ['id' => 'tra_b', 'status' => 'DONE', 'value' => 20]);
Banco::$falhar['entregas_lancamentos'] = 'banco fora do ar';
$n = AcompanhamentoDeSaques::conferir();
unset(Banco::$falhar['entregas_lancamentos']);
confere(saqueDoBanco(UA)->situacao === 'enviando', 'o primeiro (falhar deu erro no banco): continua enviando');
confere($n === 1 && saqueDoBanco(UB)->situacao === 'concluido', 'o seguinte é conferido e conclui');
$aviso = array_values(array_filter(Log::$registros, fn ($r) => $r[1] === '[entregas] saque: acompanhamento do saque falhou'));
confere(count($aviso) === 1 && $aviso[0][0] === 'warning' && $aviso[0][2] === ['saque' => UA, 'erro' => 'Teste\ErroDeBanco'], 'warning com o saque e a classe do erro: ' . json_encode($aviso[0][2] ?? null));

echo '== PoC 3 do revisor: dois "enviando" de 50 e um processando' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria', 100.0, '21988887777');
motoboyComSaldo('driver-3', 'Ze', 100.0, '31977776666');
Saques::pedir('empresa-1', 'driver-2', UB, 50);
Saques::pedir('empresa-1', 'driver-1', UA, 50);
Saques::pedir('empresa-1', 'driver-3', UC, 20);
DB::table('entregas_saques')->where('uuid', UC)->update(['transfer_id' => 'tra_C', 'situacao' => 'processando']);
ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_A', 'value' => 50, 'bankAccount' => ['pixAddressKey' => '+5511999994321']]]);
Relogio::$agora = '2026-10-27 10:06:00';
asaas(200, ['data' => [['id' => 'tra_A', 'externalReference' => 'saque:' . UA, 'status' => 'PENDING', 'value' => 50]], 'hasMore' => false]);
asaas(200, ['id' => 'tra_C', 'status' => 'DONE', 'value' => 20]);
$n = AcompanhamentoDeSaques::conferir();
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->transfer_id === null, 'B (não está no Asaas): falha e devolve; nunca recebe o id de A');
confere(saqueDoBanco(UA)->situacao === 'processando' && saqueDoBanco(UA)->transfer_id === 'tra_A', 'A: adotado pelo externalReference');
confere(saqueDoBanco(UC)->situacao === 'concluido' && $n === 2, 'C: conferido e concluído (a central não travou)');
confere(logsDe('critical') === ['[entregas] saque: concluído sem a aprovação da validação'], 'só o aviso de C sem aprovação: ' . json_encode(logsDe('critical'), JSON_UNESCAPED_UNICODE));

echo '== Id que dá 404 (saque órfão): nunca falha sozinho' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_sumiu', 'status' => 'PENDING']);
HttpFalso::$chamadas = [];
asaas(404, ['errors' => [['code' => 'not_found', 'description' => 'Transferência não encontrada.']]]);
Relogio::$agora = '2026-10-27 10:05:00';
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando' && count(HttpFalso::$chamadas) === 1, 'menos de 30 min: 404 só espera (nem lista)');
confere(saqueDoBanco(UA)->nao_encontrado_desde === '2026-10-27 10:05:00' && logsDe('warning') === ['[entregas] saque: transferência não encontrada no Asaas'], 'o primeiro 404 grava nao_encontrado_desde (warning)');
Relogio::$agora = '2026-10-27 10:30:00';
asaas(500, null);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando' && count(HttpFalso::$chamadas) === 2, 'outro erro (500), mesmo depois de 30 min: espera');
confere(saqueDoBanco(UA)->nao_encontrado_desde === '2026-10-27 10:05:00', 'e não mexe na marca do 404');
asaas(404, null);
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando', '404 e a lista fora do ar: espera');
Relogio::$agora = '2026-10-27 10:31:00';
asaas(404, null);
asaas(200, ['data' => [], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando', 'PoC F: 404 com 31 min e a lista vazia: NÃO falha (o dinheiro pode ter saído)');
confere(ExtratoDaConta::saldo('empresa-1', 'motoboy', 'driver-1') === 90.0 && logsDe('critical') === [] && saqueDoBanco(UA)->nao_encontrado_desde === '2026-10-27 10:05:00', 'nada devolvido, nenhum log crítico ainda, a marca continua do primeiro 404');
asaas(200, ['id' => 'tra_sumiu', 'status' => 'BANK_PROCESSING', 'value' => 10]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->nao_encontrado_desde === null && saqueDoBanco(UA)->asaas_status === 'BANK_PROCESSING', 'resposta OK: limpa nao_encontrado_desde');
Relogio::$agora = '2026-10-27 11:00:00';
asaas(404, null);
asaas(200, ['data' => [['id' => 'tra_x', 'externalReference' => 'saque:outro', 'status' => 'DONE']], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->nao_encontrado_desde === '2026-10-27 11:00:00', 'novo 404 depois do OK: a contagem recomeça');
Relogio::$agora = '2026-10-27 16:59:59';
asaas(404, null);
asaas(200, ['data' => [], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && logsDe('critical') === [], 'menos de 6 h de 404 seguidos: sem log crítico');
Relogio::$agora = '2026-10-27 17:00:00';
asaas(404, null);
asaas(200, ['data' => [], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando' && saqueDoBanco(UA)->alerta_critico_em === '2026-10-27 17:00:00', '6 h de 404 seguidos e fora das recentes: continua processando, marca alerta_critico_em');
confere(logsDe('critical') === ['[entregas] saque: transferência não encontrada no Asaas há horas; conferir à mão'], 'e um log crítico');
Relogio::$agora = '2026-10-27 17:01:00';
asaas(404, null);
asaas(200, ['data' => [], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && count(logsDe('critical')) === 1 && saqueDoBanco(UA)->situacao === 'processando', 'no minuto seguinte: nem falha, nem repete o log crítico');
confere(ExtratoDaConta::saldo('empresa-1', 'motoboy', 'driver-1') === 90.0, 'o valor não volta sozinho (a central confere no Asaas)');
$parado = array_values(array_filter(Saques::atencao('empresa-1'), fn ($i) => $i['tipo'] === 'saque_parado'));
confere(count($parado) === 1 && $parado[0]['saque'] === UA, 'aparece como "saque parado" no painel');

centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_sumiu', 'status' => 'PENDING']);
Relogio::$agora = '2026-10-27 10:31:00';
asaas(404, null);
asaas(200, ['data' => [['id' => 'tra_certa', 'externalReference' => 'saque:' . UA, 'status' => 'DONE', 'value' => 10, 'transactionReceiptUrl' => 'https://www.asaas.com/c/certa']], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UA)->transfer_id === 'tra_certa' && saqueDoBanco(UA)->situacao === 'concluido', 'achada pelo externalReference: adota (troca o id) e aplica o status');
confere(in_array('[entregas] saque: transferência do saque trocada pela achada no Asaas', logsDe('critical'), true), 'a troca do id vai ao log como crítica');

centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_sumiu', 'status' => 'PENDING']);
Relogio::$agora = '2026-10-27 10:31:00';
asaas(404, null);
asaas(200, ['data' => [['id' => 'tra_errada', 'externalReference' => 'saque:' . UA, 'status' => 'DONE', 'value' => 99]], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->transfer_id === 'tra_sumiu' && saqueDoBanco(UA)->situacao === 'processando', 'achada com outro valor: não adota, nada muda');
confere(in_array('[entregas] saque: transferência não confere com o saque', logsDe('critical'), true), 'e vai ao log como crítico');

echo '== Segunda revisão, N2: mais de uma transferência com o mesmo externalReference' . PHP_EOL;
/** O saque UA de 50 "enviando" sem id (POST sem resposta), aprovado pela validação para tra_REAL se $aprovar. */
function saqueSemRespostaDoPost(bool $aprovar): void
{
    centralComSaque();
    $s = Saques::pedir('empresa-1', 'driver-1', UA, 50)['saque'];
    asaas(200, ['data' => [['id' => 'wallet-1']]]);
    asaas(0, null);
    Saques::enviar($s);
    if ($aprovar) {
        $real = ['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_REAL', 'value' => 50, 'operationType' => 'PIX', 'externalReference' => 'saque:' . UA, 'bankAccount' => ['pixAddressKey' => '+5511999994321']]];
        confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, $real) === [200, ['status' => 'APPROVED']], 'a validação aprovou tra_REAL');
    }
    Relogio::$agora = '2026-10-27 10:06:00';
}
saqueSemRespostaDoPost(true);
asaas(200, ['data' => [
    ['id' => 'tra_REAL', 'status' => 'DONE', 'value' => 50, 'externalReference' => 'saque:' . UA, 'transactionReceiptUrl' => 'https://www.asaas.com/c/real'],
    ['id' => 'tra_ATT', 'status' => 'DONE', 'value' => 50, 'externalReference' => 'saque:' . UA, 'transactionReceiptUrl' => 'https://www.asaas.com/c/att'],
], 'hasMore' => false]);
AcompanhamentoDeSaques::conferir();
$s = saqueDoBanco(UA);
confere($s->situacao === 'concluido' && $s->transfer_id === 'tra_REAL' && $s->comprovante_url === 'https://www.asaas.com/c/real', 'PoC B: duas na lista (a do atacante por último): adota a aprovada (tra_REAL), com o comprovante dela');
confere(logsDe('critical') === [], 'sem log crítico');

saqueSemRespostaDoPost(true);
asaas(200, ['data' => [
    ['id' => 'tra_REAL', 'status' => 'DONE', 'value' => 50, 'externalReference' => 'saque:' . UA],
    ['id' => 'tra_ATT', 'status' => 'FAILED', 'value' => 50, 'externalReference' => 'saque:' . UA, 'failReason' => 'Recusada'],
], 'hasMore' => false]);
AcompanhamentoDeSaques::conferir();
$s = saqueDoBanco(UA);
confere($s->situacao === 'concluido' && $s->transfer_id === 'tra_REAL', 'PoC C: a do atacante FAILED não falha (nem devolve) o saque; vale a aprovada (DONE)');

saqueSemRespostaDoPost(false);
asaas(200, ['data' => [
    ['id' => 'tra_X', 'status' => 'DONE', 'value' => 50, 'externalReference' => 'saque:' . UA],
    ['id' => 'tra_Y', 'status' => 'FAILED', 'value' => 50, 'externalReference' => 'saque:' . UA],
], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'enviando' && saqueDoBanco(UA)->transfer_id === null, 'duas e nenhuma aprovada: nem adota, nem falha (fica parado)');
confere(logsDe('critical') === ['[entregas] saque: transferências no Asaas com o externalReference do saque e nenhuma é a aprovada'], 'e vai ao log como crítico');

saqueSemRespostaDoPost(true);
asaas(200, ['data' => [['id' => 'tra_ATT', 'status' => 'DONE', 'value' => 50, 'externalReference' => 'saque:' . UA]], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'enviando' && saqueDoBanco(UA)->transfer_id === null, 'uma só, mas não é a aprovada: não adota, nem falha');
confere(logsDe('critical') === ['[entregas] saque: transferências no Asaas com o externalReference do saque e nenhuma é a aprovada'], 'e vai ao log como crítico');

saqueSemRespostaDoPost(false);
asaas(200, ['data' => [['id' => 'tra_X', 'status' => 'BANK_PROCESSING', 'value' => 50, 'externalReference' => 'saque:' . UA]], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->transfer_id === 'tra_X' && saqueDoBanco(UA)->situacao === 'processando', 'uma só e nenhuma aprovação gravada: adota como antes');

echo '== Segunda revisão, N2: a conclusão confere a chave Pix e o operationType' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_a', 'status' => 'PENDING']);
asaas(200, ['id' => 'tra_a', 'status' => 'DONE', 'value' => 10, 'bankAccount' => ['pixAddressKey' => 'atacante@exemplo.com']]);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando', 'GET com outra chave Pix: não conclui');
confere(logsDe('critical') === ['[entregas] saque: transferência não confere com o saque'], 'e vai ao log como crítico');
asaas(200, ['id' => 'tra_a', 'status' => 'FAILED', 'value' => 10, 'operationType' => 'TED']);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UA)->situacao === 'processando', 'GET com operationType TED: não falha (nem devolve)');
asaas(200, ['id' => 'tra_a', 'status' => 'DONE', 'value' => 10, 'operationType' => 'PIX', 'bankAccount' => ['pixAddressKey' => '(11) *****-4321']]);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UA)->situacao === 'concluido', 'chave mascarada no GET: não dá para comparar, segue (Pix, valor certo)');
centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_a', 'status' => 'PENDING']);
asaas(200, ['id' => 'tra_a', 'status' => 'DONE', 'value' => 10, 'operationType' => 'PIX', 'bankAccount' => ['pixAddressKey' => '+55 11 99999-4321']]);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UA)->situacao === 'concluido', 'a mesma chave (com +55): conclui');

echo '== Comando e agenda' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_a', 'status' => 'PENDING']);
Config::$valores['services.entregas.saque'] = null;
$antes = count(HttpFalso::$chamadas);
confere((new SaquesConferir())->handle() === 0 && count(HttpFalso::$chamadas) === $antes, 'desligada: o comando sai sem chamar o Asaas');
Config::$valores['services.entregas.saque'] = '1';
asaas(200, ['id' => 'tra_a', 'status' => 'DONE']);
confere((new SaquesConferir())->handle() === 0 && saqueDoBanco(UA)->situacao === 'concluido', 'ligada: confere');
$kernel = file_get_contents('/repo/api/app/Console/Kernel.php');
confere(str_contains($kernel, "\$schedule->command('entregas:saques-conferir')->everyMinute()->when(fn () => Saque::ligada())->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);") && str_contains($kernel, 'use App\Support\Entregas\Saques\Saque;'), 'agenda: a cada minuto, só com a chave ligada');
confere(str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/SaquesConferir.php'), "'entregas:saques-conferir'"), 'assinatura do comando');

resumo();
