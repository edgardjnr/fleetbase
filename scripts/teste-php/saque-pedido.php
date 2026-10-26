<?php

// Saque Pix: o que o motoboy pode sacar (Saques::situacaoDoMotoboy) e o pedido (Saques::pedir): conferências, limites,
// idempotência pelo uuid do app e a reserva no livro do Financeiro (pagamento Pix + taxa).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-pedido.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\Saques\SaqueRecusado;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Support\Facades\DB;
use Teste\Config;
use Teste\Relogio;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';
const U2 = 'aaaaaaaa-0000-4000-8000-000000000002';
const U3 = 'aaaaaaaa-0000-4000-8000-000000000003';

function recusa(callable $fazer): ?string
{
    $erro = excecao($fazer);

    return $erro instanceof SaqueRecusado ? $erro->getMessage() : ($erro ? get_class($erro) . ': ' . $erro->getMessage() : null);
}

function situacao(): ?array
{
    return Saques::situacaoDoMotoboy('empresa-1', 'driver-1');
}

function saldo(string $driver = 'driver-1'): float
{
    return ExtratoDaConta::saldo('empresa-1', 'motoboy', $driver);
}

echo '== Disponível' . PHP_EOL;
centralComSaque();
confere(situacao() === ['disponivel' => 100.0, 'minimo' => 10.0, 'maximo_hoje' => 500.0, 'taxa' => 0.0, 'em_andamento' => null, 'indisponivel_motivo' => null], 'saldo 100, padrões: ' . json_encode(situacao(), JSON_UNESCAPED_UNICODE));
Config::$valores['services.entregas.saque'] = null;
confere(situacao() === null, 'ENTREGAS_SAQUE desligada: sem bloco (o app esconde o botão)');
Config::$valores['services.entregas.saque'] = '1';
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saque_ligado' => false]);
confere(situacao() === null, 'central com o saque desligado: sem bloco');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saque_ligado' => true, 'validacao_confirmada_em' => null]);
confere(situacao() === null, 'validação não confirmada: sem bloco');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['validacao_confirmada_em' => '2026-10-26 09:00:00']);

echo '== Pedido recusado' . PHP_EOL;
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', 'nao-e-uuid', 50)) === 'Pedido de saque inválido.', 'uuid inválido');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 'abc')) === 'Informe o valor do saque.', 'valor que não é número');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 0)) === 'Informe o valor do saque.', 'valor zero');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 5)) === 'O valor mínimo do saque é R$ 10,00.', 'abaixo do mínimo');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 100.01)) === 'Valor acima do disponível para saque (R$ 100,00).', 'acima do disponível');
Config::$valores['services.entregas.saque'] = null;
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 50)) === 'Saque Pix indisponível no momento.', 'chave geral desligada');
Config::$valores['services.entregas.saque'] = '1';
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saque_ligado' => false]);
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 50)) === 'A central não liberou o saque Pix.', 'central com o saque desligado');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saque_ligado' => true]);
confere(linhasDe('entregas_saques') === [] && linhasDe('entregas_pagamentos') === [], 'recusas não gravam nada');

echo '== Pedido aceito' . PHP_EOL;
\Teste\Banco::$travas = [];
$r     = Saques::pedir('empresa-1', 'driver-1', strtoupper(U1), '60,50');
$saque = $r['saque'];
confere(array_slice(\Teste\Banco::$travas, 0, 2) === [['entregas_asaas_conexoes', true], ['entregas_motoboys_financeiro', true]], 'na transação: relê a conexão travada (desconectar ou desligar no meio espera) e depois a linha do motoboy: ' . json_encode(\Teste\Banco::$travas));
confere($r['novo'] === true && $saque->uuid === U1 && $saque->situacao === 'enviando' && (float) $saque->valor === 60.5 && (float) $saque->taxa === 0.0, 'saque "enviando" de R$ 60,50 (uuid em minúsculas)');
confere($saque->chave_pix === '11999994321' && $saque->tipo_chave === 'telefone' && $saque->external_reference === 'saque:' . U1 && $saque->transfer_id === null, 'chave Pix copiada do cadastro; externalReference estável');
$pagamento = Pagamentos::porUuid('empresa-1', (string) $saque->pagamento_uuid);
confere($pagamento && (float) $pagamento->valor === 60.5 && $pagamento->forma === 'pix' && $pagamento->criado_por === null && $pagamento->observacao_interna === 'Saque Pix pelo app' && $pagamento->data === '2026-10-27', 'pagamento Pix do sistema no livro');
confere(saldo() === 39.5, 'o saldo já desconta o saque: ' . saldo());
confere($saque->taxa_lancamento_id === null, 'sem taxa: sem lançamento de taxa');

echo '== Idempotência' . PHP_EOL;
$de_novo = Saques::pedir('empresa-1', 'driver-1', U1, 60.5);
confere($de_novo['novo'] === false && (int) $de_novo['saque']->id === (int) $saque->id && count(linhasDe('entregas_saques')) === 1 && count(linhasDe('entregas_pagamentos')) === 1, 'o mesmo uuid devolve o mesmo saque, sem outro pagamento');
motoboyComSaldo('driver-2', 'Maria Souza');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-2', U1, 20)) === 'Pedido de saque inválido.', 'uuid de outro motoboy: inválido');

echo '== Um por vez' . PHP_EOL;
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U2, 10)) === 'Já há um saque em andamento.', 'com saque em andamento, outro não');
$s = situacao();
confere(($s['em_andamento']['id'] ?? null) === U1 && $s['indisponivel_motivo'] === 'Saque em andamento.', 'situação: o saque em andamento e o motivo');

echo '== Limites do dia' . PHP_EOL;
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'concluido']);
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saques_dia' => 1]);
confere(situacao()['indisponivel_motivo'] === 'Limite de saques de hoje atingido.' && recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U2, 10)) === 'Limite de saques de hoje atingido.', 'saques por dia');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saques_dia' => 3, 'maximo_dia' => 70]);
confere(situacao()['maximo_hoje'] === 9.5 && situacao()['indisponivel_motivo'] === 'Limite de hoje atingido.', 'máximo do dia: sobra 9,50, abaixo do mínimo');
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'falhou']);
confere(situacao()['maximo_hoje'] === 70.0, 'saque que falhou não conta no dia');
Relogio::$agora = '2026-10-28 00:00:01';
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'concluido']);
confere(situacao()['maximo_hoje'] === 70.0, 'dia novo: limite cheio de novo');

echo '== Taxa' . PHP_EOL;
centralComSaque(['taxa' => 2.0]);
confere(situacao()['disponivel'] === 98.0 && situacao()['taxa'] === 2.0, 'disponível = saldo − taxa');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U3, 98.01)) === 'Valor acima do disponível para saque (R$ 98,00).', 'a taxa entra no limite');
$saque = Saques::pedir('empresa-1', 'driver-1', U3, 98)['saque'];
$taxa  = Lancamentos::porId('empresa-1', (int) $saque->taxa_lancamento_id);
confere($taxa && $taxa->tipo === 'ajuste' && (float) $taxa->valor === -2.0 && $taxa->motivo === 'Taxa do saque Pix' && $taxa->pagamento_uuid === null && (float) $saque->taxa === 2.0, 'taxa: ajuste −2,00, sem pagamento_uuid');
confere(saldo() === 0.0, 'saldo zerado (98 + 2)');

echo '== Impedimentos do motoboy' . PHP_EOL;
centralComSaque();
DB::table(ChavePix::TABELA)->where('driver_uuid', 'driver-1')->update(['chave_pix' => null]);
confere(situacao()['indisponivel_motivo'] === 'Sem chave Pix cadastrada: fale com a central.' && recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 10)) === 'Sem chave Pix cadastrada: fale com a central.', 'sem chave Pix');
DB::table(ChavePix::TABELA)->where('driver_uuid', 'driver-1')->update(['chave_pix' => '11999994321']);
Saques::bloquear('empresa-1', 'driver-1', true);
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 10)) === 'Saque bloqueado pela central.', 'bloqueado');
Saques::bloquear('empresa-1', 'driver-1', false);
ChavePix::salvar('empresa-1', 'driver-1', '21988887777', 'telefone', 'user-admin');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 10)) === 'Chave Pix alterada; saque liberado às 10:00 de 28/10.', 'carência depois da troca');
Relogio::$agora = '2026-10-28 10:00:01';
Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'ajuste', 'valor' => -95, 'data_referencia' => '2026-10-28', 'motivo' => 'Desconto']);
confere(situacao()['indisponivel_motivo'] === 'Saldo abaixo do mínimo para saque (R$ 10,00).', 'saldo 5 < mínimo');

resumo();
