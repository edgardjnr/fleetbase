<?php

// Financeiro: as faturas das lojas (Faturas): ciclo da loja, rascunho, conferência (ajuste, "Não cobrar", vencimento),
// emissão com número sequencial, cancelamento, situação pelos recebimentos e os formatos JSON "Fatura" e "Lancamento".
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-faturas.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Financeiro\Faturas;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\Setting;
use Teste\Banco;
use Teste\Relogio;

reiniciarFinanceiro();
Vendor::$todos[] = new Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Burger Hub']);
Vendor::$todos[] = new Vendor(['uuid' => 'vendor-z', 'public_id' => 'vendor_z', 'company_uuid' => 'empresa-2', 'name' => 'Outra empresa']);
Banco::$tabelas['users'] = [1 => ['id' => 1, 'uuid' => 'user-admin', 'name' => 'Edgard']];

/** Lançamento de loja; devolve o id. */
function daLoja(string $vendor, string $tipo, float $valor, string $data, array $extra = []): int
{
    return Lancamentos::inserir($extra + ['company_uuid' => 'empresa-1', 'conta_tipo' => 'loja', 'conta_uuid' => $vendor, 'tipo' => $tipo, 'valor' => $valor, 'data_referencia' => $data]);
}

function corridaDaLoja(string $vendor, float $valor, string $data, string $pedido, array $extra = []): int
{
    return daLoja($vendor, 'corrida', $valor, $data, $extra + ['order_uuid' => $pedido, 'chave_unica' => 'corrida:loja:' . $pedido,
        'detalhes' => ['pedido' => 'order_' . $pedido, 'numero' => 'RP' . strtoupper($pedido), 'km' => 3.21, 'faixa' => ['de_km' => 2, 'ate_km' => 4, 'acima' => false], 'loja_nome' => 'Pizzaria Ficticia', 'destino' => 'Rua X, 10', 'cancelado_pago' => false, 'cancelado_origem' => null]]);
}

function idsDosItens(object $fatura): array
{
    return array_map(fn ($l) => (int) $l->id, Faturas::itens($fatura));
}

echo '== Ciclo da loja' . PHP_EOL;
confere(Faturas::configDaLoja('empresa-1', 'vendor-a') === ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3, 'padrao' => true], 'sem ciclo próprio: o padrão da empresa');
Faturas::salvarConfig('empresa-1', 'vendor-b', ['ciclo' => 'mensal', 'dia_fechamento' => '5', 'prazo_dias' => '10']);
confere(Faturas::configDaLoja('empresa-1', 'vendor-b') === ['ciclo' => 'mensal', 'dia_fechamento' => 5, 'prazo_dias' => 10, 'padrao' => false], 'com ciclo próprio, em números');
Faturas::salvarConfig('empresa-1', 'vendor-b', ['ciclo' => 'quinzenal', 'dia_fechamento' => 9, 'prazo_dias' => 2]);
confere(count(linhasDe('entregas_cobranca_lojas')) === 1 && Faturas::configDaLoja('empresa-1', 'vendor-b') === ['ciclo' => 'quinzenal', 'dia_fechamento' => 0, 'prazo_dias' => 2, 'padrao' => false], 'salvar de novo troca a mesma linha (quinzenal grava dia 0)');
confere(Faturas::configDaLoja('empresa-2', 'vendor-b')['padrao'] === true, 'o ciclo é da empresa (outra empresa: padrão)');
$erro = excecao(fn () => Faturas::salvarConfig('empresa-1', 'vendor-a', ['ciclo' => 'semanal', 'dia_fechamento' => 9, 'prazo_dias' => 3]));
confere($erro instanceof \InvalidArgumentException && $erro->getMessage() !== '' && Faturas::configDaLoja('empresa-1', 'vendor-a')['padrao'] === true, 'ciclo inválido: recusa e não grava');
Setting::$valores['company.empresa-1.entregas.cobranca_padrao'] = ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3];

echo '== Rascunho' . PHP_EOL;
$c1      = corridaDaLoja('vendor-a', 10, '2026-10-02', 'p1', ['observacao_interna' => 'conferir', 'criado_por' => 'user-admin']);
$c2      = corridaDaLoja('vendor-a', 12, '2026-10-04', 'p2');
$depois  = corridaDaLoja('vendor-a', 9, '2026-10-06', 'p3');
$antigo  = daLoja('vendor-a', 'ajuste', 5, '2026-09-20', ['motivo' => 'Taxa de setembro']);
$daB     = corridaDaLoja('vendor-b', 7, '2026-10-02', 'p4');
$doMoto  = Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'vendor-a', 'tipo' => 'corrida', 'valor' => 6, 'data_referencia' => '2026-10-02']);
$receb   = Pagamentos::registrar('empresa-1', 'loja', 'vendor-a', 20, 'pix', '2026-10-03', null, null, 'user-admin');
Pagamentos::estornar($receb, 'user-admin');

$fatura = Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-09-28', '2026-10-04', 'user-admin');
confere(is_object($fatura) && $fatura->situacao === 'rascunho' && $fatura->numero === null && $fatura->total === null, 'cria o rascunho, sem número e sem total');
confere($fatura->periodo_inicio === '2026-09-28' && $fatura->periodo_fim === '2026-10-04' && $fatura->vencimento === '2026-10-07' && $fatura->vendor_uuid === 'vendor-a' && $fatura->company_uuid === 'empresa-1', 'período, vencimento (fim + 3 dias do ciclo) e loja');
confere(strlen((string) $fatura->uuid) === 36 && $fatura->created_at === '2026-10-05 15:00:00', 'uuid e datas na hora de Brasília');
confere(idsDosItens($fatura) === [$antigo, $c1, $c2], 'pega os lançamentos da loja sem fatura até o fim (inclusive o atrasado), mais antigos primeiro');
confere(!in_array($depois, idsDosItens($fatura), true) && !in_array($daB, idsDosItens($fatura), true) && !in_array($doMoto, idsDosItens($fatura), true), 'fora: depois do fim, outra loja e conta do motoboy');
$recebimentos = array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['pagamento_uuid'] !== null);
confere(count($recebimentos) === 2 && !array_filter($recebimentos, fn ($l) => $l['fatura_uuid'] !== null), 'recebimento e o estorno dele não entram na fatura');
confere(Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-09-28', '2026-10-04', null) === null && count(linhasDe('entregas_faturas')) === 1, 'mesmo fim de período: não duplica');
confere(Faturas::gerarRascunho('empresa-1', 'vendor-c', '2026-09-28', '2026-10-04', null) === null && count(linhasDe('entregas_faturas')) === 1, 'sem lançamentos: não cria fatura vazia');
confere(Faturas::gerarRascunho('empresa-2', 'vendor-b', '2026-09-28', '2026-10-04', null) === null, 'a loja só tem lançamentos na empresa dela');
$erro = excecao(fn () => Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-10-05', '2026-10-04', null));
confere($erro instanceof \InvalidArgumentException, 'início depois do fim: recusa');
confere(excecao(fn () => Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-10-01', '04/10/2026', null)) instanceof \InvalidArgumentException, 'data inválida: recusa');
confere(Faturas::porUuid('empresa-1', $fatura->uuid)?->id === $fatura->id && Faturas::porUuid('empresa-2', $fatura->uuid) === null, 'porUuid só na empresa');

echo '== Conferência do rascunho' . PHP_EOL;
$ajuste = Faturas::ajuste($fatura, -3, 'Desconto de inauguração', 'combinado por telefone', 'user-admin');
$linhaA = Banco::$tabelas['entregas_lancamentos'][$ajuste];
confere($linhaA['tipo'] === 'ajuste' && (float) $linhaA['valor'] === -3.0 && $linhaA['fatura_uuid'] === $fatura->uuid && $linhaA['conta_tipo'] === 'loja' && $linhaA['conta_uuid'] === 'vendor-a', 'ajuste: lançamento da loja já na fatura');
confere($linhaA['data_referencia'] === '2026-10-04' && $linhaA['motivo'] === 'Desconto de inauguração' && $linhaA['observacao_interna'] === 'combinado por telefone' && $linhaA['criado_por'] === 'user-admin' && $linhaA['order_uuid'] === null, 'com a data do fim do período, motivo, observação e quem fez');
confere(excecao(fn () => Faturas::ajuste($fatura, 0, 'Nada', null, null)) instanceof \InvalidArgumentException, 'ajuste zero: recusa');
confere(excecao(fn () => Faturas::ajuste($fatura, 2, '  ', null, null)) instanceof \InvalidArgumentException, 'ajuste sem motivo: recusa (a loja vê o motivo)');

$naoCobrar = Faturas::naoCobrar($fatura, Lancamentos::porId('empresa-1', $c1), 'user-admin');
$linhaN    = Banco::$tabelas['entregas_lancamentos'][$naoCobrar];
confere($linhaN['tipo'] === 'ajuste' && (float) $linhaN['valor'] === -10.0 && $linhaN['order_uuid'] === 'p1' && $linhaN['fatura_uuid'] === $fatura->uuid && $linhaN['data_referencia'] === '2026-10-02', '"Não cobrar": ajuste negativo do valor inteiro, com o pedido, na fatura');
confere($linhaN['motivo'] === 'Entrega não cobrada' && json_decode((string) $linhaN['detalhes'], true)['numero'] === 'RPP1', 'com o motivo e os detalhes da corrida (número do pedido)');
confere(excecao(fn () => Faturas::naoCobrar($fatura, Lancamentos::porId('empresa-1', $c1), null)) instanceof \DomainException, '"Não cobrar" duas vezes: recusa');
confere(excecao(fn () => Faturas::naoCobrar($fatura, Lancamentos::porId('empresa-1', $depois), null)) instanceof \DomainException, '"Não cobrar" de corrida fora da fatura: recusa');
confere(excecao(fn () => Faturas::naoCobrar($fatura, Lancamentos::porId('empresa-1', $antigo), null)) instanceof \DomainException, '"Não cobrar" de lançamento que não é corrida: recusa');

Faturas::mudarVencimento($fatura, '2026-10-10');
confere(Faturas::porUuid('empresa-1', $fatura->uuid)->vencimento === '2026-10-10', 'muda o vencimento do rascunho');
confere(excecao(fn () => Faturas::mudarVencimento($fatura, '10/10/2026')) instanceof \InvalidArgumentException, 'vencimento inválido: recusa');
confere(excecao(fn () => Faturas::mudarVencimento($fatura, '2026-10-03')) instanceof \InvalidArgumentException, 'vencimento antes do fim do período: recusa');

$fatura = Faturas::porUuid('empresa-1', $fatura->uuid);
$r      = Faturas::resumo($fatura, [], 'Pizzaria Ficticia', false);
confere($r['id'] === $fatura->uuid && $r['numero'] === null && $r['loja'] === ['id' => 'vendor_a', 'nome' => 'Pizzaria Ficticia'], 'resumo do rascunho: id, sem número, loja');
confere($r['situacao'] === 'rascunho' && $r['pagamento'] === null && $r['vencida'] === false && $r['total'] === 14.0 && $r['recebido'] === 0.0 && $r['em_aberto'] === 0.0 && $r['entregas'] === 2, 'total = soma atual dos itens (10 + 12 + 5 − 3 − 10), 2 entregas');
confere($r['periodo_inicio'] === '2026-09-28' && $r['periodo_fim'] === '2026-10-04' && $r['vencimento'] === '2026-10-10' && $r['emitida_em'] === null && array_key_exists('email_enviado_em', $r), 'datas e campos do console');
confere(!array_key_exists('email_enviado_em', Faturas::resumo($fatura, [], 'Pizzaria Ficticia', true)), 'público: sem email_enviado_em');
confere(Faturas::resumo($fatura, [], null, false)['loja']['nome'] === 'Pizzaria Ficticia', 'sem o nome: lê da loja');

echo '== Itens no formato Lancamento' . PHP_EOL;
$itens = Faturas::itensFormatados($fatura, false);
confere(array_column($itens, 'id') === [$antigo, $c1, $naoCobrar, $c2, $ajuste], 'itens mais antigos primeiro (data, depois id)');
$item = $itens[1];
confere($item['tipo'] === 'corrida' && $item['valor'] === 10.0 && $item['data'] === '2026-10-02' && $item['criado_em'] === '2026-10-05T15:00:00-03:00', 'corrida: tipo, valor, data e instante ISO no fuso da empresa');
confere($item['pedido'] === ['id' => 'order_p1', 'numero' => 'RPP1'] && $item['detalhes']['km'] === 3.21 && $item['detalhes']['destino'] === 'Rua X, 10' && $item['detalhes']['faixa'] === ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'pedido e detalhes');
confere($item['observacao_interna'] === 'conferir' && $item['criado_por'] === ['nome' => 'Edgard'] && $item['fatura'] === ['id' => $fatura->uuid, 'numero' => null], 'console: observação interna, quem lançou e a fatura');
confere($item['situacao'] === null && $item['valor_pago'] === null && $item['pagamento'] === null && $item['estorno_de'] === null && $item['estornado'] === false && $item['motivo'] === null, 'sem situação por item (a da loja é a da fatura), sem pagamento, não estornado');
confere($itens[0]['pedido'] === null && $itens[0]['detalhes'] === null && $itens[0]['criado_por'] === null && $itens[0]['motivo'] === 'Taxa de setembro', 'ajuste sem pedido: pedido nulo; sistema: criado_por nulo');
$publicos = Faturas::itensFormatados($fatura, true);
confere(!array_key_exists('observacao_interna', $publicos[1]) && !array_key_exists('criado_por', $publicos[1]) && $publicos[1]['valor'] === 10.0, 'público: sem observação interna e sem quem lançou');

echo '== Emissão' . PHP_EOL;
Relogio::$agora = '2026-10-05 16:00:00';
$emitida        = Faturas::emitir($fatura, 'user-admin');
confere($emitida->situacao === 'emitida' && (int) $emitida->numero === 1 && (float) $emitida->total === 14.0, 'emite: número 1 e total gravado');
confere($emitida->emitida_em === '2026-10-05 16:00:00' && $emitida->emitida_por === 'user-admin', 'quando e quem emitiu');
foreach ([
    'ajuste'          => fn () => Faturas::ajuste($emitida, 1, 'x', null, null),
    '"Não cobrar"'    => fn () => Faturas::naoCobrar($emitida, Lancamentos::porId('empresa-1', $c2), null),
    'vencimento'      => fn () => Faturas::mudarVencimento($emitida, '2026-10-20'),
    'emitir de novo'  => fn () => Faturas::emitir($emitida, null),
] as $caso => $fazer) {
    $erro = excecao($fazer);
    confere($erro instanceof \DomainException && $erro->getMessage() !== '', "{$caso} em fatura emitida: recusa ({$erro?->getMessage()})");
}
corridaDaLoja('vendor-b', 4, '2026-10-01', 'p5');
$daLojaB = Faturas::emitir(Faturas::gerarRascunho('empresa-1', 'vendor-b', '2026-10-01', '2026-10-15', null), null);
confere((int) $daLojaB->numero === 2 && (float) $daLojaB->total === 11.0, 'a próxima emitida da empresa: número 2 (sequencial por empresa)');
Lancamentos::inserir(['company_uuid' => 'empresa-2', 'conta_tipo' => 'loja', 'conta_uuid' => 'vendor-z', 'tipo' => 'corrida', 'valor' => 3, 'data_referencia' => '2026-10-02']);
$outraEmpresa = Faturas::emitir(Faturas::gerarRascunho('empresa-2', 'vendor-z', '2026-10-01', '2026-10-04', null), null);
confere((int) $outraEmpresa->numero === 1, 'outra empresa começa no 1');

echo '== Situação pelos recebimentos' . PHP_EOL;
confere(Faturas::situacoesDaLoja('empresa-1', 'vendor-a') === [$fatura->uuid => ['pagamento' => 'aberta', 'recebido' => 0.0, 'em_aberto' => 14.0, 'vencida' => false]], 'recebimento estornado não conta: em aberto');
Pagamentos::registrar('empresa-1', 'loja', 'vendor-a', 8, 'pix', '2026-10-05', null, null, null);
$situacoes = Faturas::situacoesDaLoja('empresa-1', 'vendor-a');
confere($situacoes[$fatura->uuid] === ['pagamento' => 'parcial', 'recebido' => 8.0, 'em_aberto' => 6.0, 'vencida' => false], 'recebeu 8 de 14: em parte');
Relogio::$agora = '2026-10-11 09:00:00';
confere(Faturas::situacoesDaLoja('empresa-1', 'vendor-a')[$fatura->uuid]['vencida'] === true, 'depois do vencimento, com valor em aberto: vencida');
$r = Faturas::resumo(Faturas::porUuid('empresa-1', $fatura->uuid), Faturas::situacoesDaLoja('empresa-1', 'vendor-a'), 'Pizzaria Ficticia', false);
confere($r['numero'] === 'FAT-0001' && $r['situacao'] === 'emitida' && $r['pagamento'] === 'parcial' && $r['vencida'] === true && $r['total'] === 14.0 && $r['recebido'] === 8.0 && $r['em_aberto'] === 6.0, 'resumo da emitida com a situação calculada');
confere($r['emitida_em'] === '2026-10-05T16:00:00-03:00' && $r['entregas'] === 2 && $r['email_enviado_em'] === null, 'emitida em (ISO, Brasília), entregas e e-mail ainda não enviado');
confere(Faturas::itensFormatados(Faturas::porUuid('empresa-1', $fatura->uuid), false)[0]['fatura'] === ['id' => $fatura->uuid, 'numero' => 'FAT-0001'], 'itens com o número da fatura');
Pagamentos::registrar('empresa-1', 'loja', 'vendor-a', 6, 'dinheiro', '2026-10-11', null, null, null);
confere(Faturas::situacoesDaLoja('empresa-1', 'vendor-a')[$fatura->uuid] === ['pagamento' => 'paga', 'recebido' => 14.0, 'em_aberto' => 0.0, 'vencida' => false], 'pagou o resto: paga (e não vencida)');

echo '== Cancelamento' . PHP_EOL;
Relogio::$agora = '2026-10-12 10:00:00';
Faturas::cancelar(Faturas::porUuid('empresa-1', $fatura->uuid), 'user-admin');
$cancelada = Faturas::porUuid('empresa-1', $fatura->uuid);
confere($cancelada->situacao === 'cancelada' && (int) $cancelada->numero === 1 && $cancelada->cancelada_em === '2026-10-12 10:00:00' && $cancelada->cancelada_por === 'user-admin', 'cancela: guarda o número, quando e quem');
confere(Faturas::itens($cancelada) === [], 'libera os lançamentos');
confere(Faturas::situacoesDaLoja('empresa-1', 'vendor-a') === [], 'cancelada sai da situação (o recebido fica como crédito da loja)');
$rc = Faturas::resumo($cancelada, [], 'Pizzaria Ficticia', false);
confere($rc['situacao'] === 'cancelada' && $rc['numero'] === 'FAT-0001' && $rc['pagamento'] === null && $rc['vencida'] === false && $rc['em_aberto'] === 0.0 && $rc['total'] === 14.0, 'resumo da cancelada');
confere(excecao(fn () => Faturas::cancelar($cancelada, null)) instanceof \DomainException, 'cancelar de novo: recusa');
$proxima = Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-10-05', '2026-10-11', null);
confere(idsDosItens($proxima) === [$antigo, $c1, $naoCobrar, $c2, $ajuste, $depois], 'o próximo rascunho pega os lançamentos liberados (com os ajustes) e os novos');
confere((int) Faturas::emitir($proxima, null)->numero === 3, 'o número não é reaproveitado');
$rascunhoCancelado = Faturas::gerarRascunho('empresa-1', 'vendor-b', '2026-10-16', '2026-10-31', null);
confere($rascunhoCancelado === null, 'loja B sem lançamento novo: nada');
corridaDaLoja('vendor-b', 2, '2026-10-20', 'p6');
$rascunhoB = Faturas::gerarRascunho('empresa-1', 'vendor-b', '2026-10-16', '2026-10-31', null);
Faturas::cancelar($rascunhoB, null);
confere(Faturas::porUuid('empresa-1', $rascunhoB->uuid)->situacao === 'cancelada' && Faturas::porUuid('empresa-1', $rascunhoB->uuid)->numero === null, 'rascunho também pode ser descartado (cancelado, sem número)');
confere(Faturas::gerarRascunho('empresa-1', 'vendor-b', '2026-10-16', '2026-10-31', null) !== null, 'e o mesmo período pode ser gerado de novo');

echo '== Estorno de lançamento que está num rascunho' . PHP_EOL;
reiniciarFinanceiro();
Relogio::$agora = '2026-10-12 10:00:00';
$e1       = corridaDaLoja('vendor-a', 10, '2026-10-02', 'e1');
$e2       = corridaDaLoja('vendor-a', 12, '2026-10-03', 'e2');
$rascunho = Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-09-28', '2026-10-04', null);
$estorno  = Lancamentos::estornar(Lancamentos::porId('empresa-1', $e1), 'user-admin', 'Pedido cancelado após a conclusão');
confere(Lancamentos::porId('empresa-1', $estorno)->fatura_uuid === $rascunho->uuid, 'o estorno entra no mesmo rascunho');
$emitida = Faturas::emitir(Faturas::porUuid('empresa-1', $rascunho->uuid), null);
confere((float) $emitida->total === 12.0, 'e o total emitido já desconta o estornado (12,00)');
$estorno2 = Lancamentos::estornar(Lancamentos::porId('empresa-1', $e2), 'user-admin', null);
confere(Lancamentos::porId('empresa-1', $estorno2)->fatura_uuid === null, 'estorno de lançamento de fatura emitida: sem fatura (entra no próximo rascunho)');
$solto   = daLoja('vendor-a', 'ajuste', 5, '2026-10-06', ['motivo' => 'Taxa']);
$estorno3 = Lancamentos::estornar(Lancamentos::porId('empresa-1', $solto), null, null);
confere(Lancamentos::porId('empresa-1', $estorno3)->fatura_uuid === null, 'lançamento sem fatura: o estorno também fica sem');

echo '== Emitir sem valor a cobrar' . PHP_EOL;
reiniciarFinanceiro();
$z1     = corridaDaLoja('vendor-a', 10, '2026-10-02', 'z1');
$zero   = Faturas::gerarRascunho('empresa-1', 'vendor-a', '2026-09-28', '2026-10-04', null);
Faturas::naoCobrar($zero, Lancamentos::porId('empresa-1', $z1), null);
$erro = excecao(fn () => Faturas::emitir($zero, null));
confere($erro instanceof \DomainException && $erro->getMessage() === 'Fatura sem valor a cobrar; cancele o rascunho.' && Faturas::porUuid('empresa-1', $zero->uuid)->situacao === 'rascunho', 'total zero: recusa a emissão (' . ($erro?->getMessage()) . ')');
Faturas::ajuste($zero, -3, 'Desconto', null, null);
$erro = excecao(fn () => Faturas::emitir($zero, null));
confere($erro instanceof \DomainException && $erro->getMessage() === 'Fatura sem valor a cobrar; cancele o rascunho.', 'total negativo: a mesma recusa');

resumo();
