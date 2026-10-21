<?php

// Financeiro: a migration das cinco tabelas, a configuração (Financeiro), o livro de lançamentos (Lancamentos) e os
// pagamentos e recebimentos (Pagamentos), no banco em memória do stubs-ifood.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-pagamentos.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Teste\Banco;
use Teste\Config;
use Teste\Disco;
use Teste\Relogio;

echo '== Migration' . PHP_EOL;
$arquivos = glob('/repo/api/database/migrations/*_entregas_financeiro_*.php');
// a dos multiplicadores (2026_10_21) também casa com o glob, de propósito: entra no banco em memória dos testes
$contrato = array_filter($arquivos, fn ($arquivo) => str_ends_with($arquivo, '2026_10_13_100000_create_entregas_financeiro_tables.php'));
confere(count($contrato) === 1, 'a migration das tabelas do financeiro, com o nome do contrato');
$migration       = require '/repo/api/database/migrations/2026_10_13_100000_create_entregas_financeiro_tables.php';
Schema::$criadas = [];
$migration->up();

/** Colunas da tabela criada: nome => Coluna (índices e timestamps pelo tipo). */
function colunasDe(string $tabela): array
{
    $colunas = [];
    foreach ((Schema::$criadas[$tabela] ?? null)?->colunas ?? [] as $coluna) {
        $primeiro = $coluna->argumentos[0] ?? null;
        $chave = match (true) {
            $primeiro === null                                                 => $coluna->tipo,
            is_string($primeiro) && !in_array($coluna->tipo, ['index', 'unique'], true) => $primeiro,
            default                                                            => $coluna->tipo . ':' . json_encode($primeiro),
        };
        $colunas[$chave] = $coluna;
    }

    return $colunas;
}

$esperadas = [
    'entregas_lancamentos'         => ['uuid', 'company_uuid', 'conta_tipo', 'conta_uuid', 'tipo', 'valor', 'data_referencia', 'order_uuid', 'pagamento_uuid', 'fatura_uuid', 'estorno_de', 'motivo', 'observacao_interna', 'detalhes', 'chave_unica', 'criado_por'],
    'entregas_pagamentos'          => ['uuid', 'company_uuid', 'conta_tipo', 'conta_uuid', 'valor', 'forma', 'data', 'comprovante_path', 'comprovante_nome', 'comprovante_tipo', 'observacao_interna', 'estornado_em', 'estornado_por', 'criado_por'],
    'entregas_faturas'             => ['uuid', 'company_uuid', 'vendor_uuid', 'numero', 'periodo_inicio', 'periodo_fim', 'vencimento', 'situacao', 'total', 'emitida_em', 'emitida_por', 'cancelada_em', 'cancelada_por', 'email_enviado_em', 'lembrete_enviado_em', 'aviso_vencida_enviado_em'],
    'entregas_cobranca_lojas'      => ['company_uuid', 'vendor_uuid', 'ciclo', 'dia_fechamento', 'prazo_dias'],
    'entregas_motoboys_financeiro' => ['company_uuid', 'driver_uuid', 'chave_pix', 'tipo_chave'],
];
confere(array_keys(Schema::$criadas) === array_keys($esperadas), 'cria as cinco tabelas');
foreach ($esperadas as $tabela => $nomes) {
    $colunas = colunasDe($tabela);
    $faltam  = array_diff(array_merge(['id', 'timestamps'], $nomes), array_keys($colunas));
    confere(!$faltam, "{$tabela}: todas as colunas" . ($faltam ? ' (faltam ' . implode(', ', $faltam) . ')' : ''));
}
$lanc = colunasDe('entregas_lancamentos');
confere(($lanc['valor']->argumentos ?? null) === ['valor', 10, 2] && $lanc['data_referencia']->tipo === 'date', 'valor decimal(10,2) e data_referencia date');
confere(array_key_exists('unique', $lanc['chave_unica']->modificadores) && array_key_exists('nullable', $lanc['chave_unica']->modificadores) && ($lanc['chave_unica']->argumentos[1] ?? null) === 80, 'chave_unica string(80), nula e única');
confere(array_key_exists('unique', $lanc['uuid']->modificadores) && $lanc['detalhes']->tipo === 'json' && ($lanc['observacao_interna']->argumentos[1] ?? null) === 500, 'uuid único, detalhes json, observação interna até 500');
confere(isset($lanc['index:' . json_encode(['company_uuid', 'conta_tipo', 'conta_uuid', 'data_referencia'])], $lanc['index:"fatura_uuid"'], $lanc['index:"order_uuid"']), 'índices da conta por data, da fatura e do pedido');
$fat = colunasDe('entregas_faturas');
confere(isset($fat['unique:' . json_encode(['company_uuid', 'numero'])]) && array_key_exists('nullable', $fat['numero']->modificadores), 'número da fatura único por empresa (nulo no rascunho)');
confere(array_key_exists('unique', colunasDe('entregas_cobranca_lojas')['vendor_uuid']->modificadores) && array_key_exists('unique', colunasDe('entregas_motoboys_financeiro')['driver_uuid']->modificadores), 'uma configuração por loja e um Pix por motoboy');
$migration->down();
confere(Schema::$criadas === [], 'o down apaga as cinco');

echo '== Financeiro (configuração)' . PHP_EOL;
reiniciarFinanceiro();
confere(Financeiro::ligada() === true, 'ENTREGAS_FINANCEIRO=1: ligada');
Config::$valores['services.entregas.financeiro'] = null;
confere(Financeiro::ligada() === false, 'vazia: desligada');
confere(Financeiro::corte('empresa-1') === '2026-10-01' && Financeiro::corte('empresa-2') === null, 'data de corte por empresa (sem: null)');
Setting::$valores['company.empresa-3.entregas.financeiro_corte'] = 'lixo';
confere(Financeiro::corte('empresa-3') === null, 'data de corte inválida: null');
confere(Financeiro::cobrancaPadrao('empresa-1') === ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3], 'cobrança padrão sugerida');
Setting::$valores['company.empresa-1.entregas.cobranca_padrao'] = ['ciclo' => 'mensal', 'dia_fechamento' => '5', 'prazo_dias' => 10];
confere(Financeiro::cobrancaPadrao('empresa-1') === ['ciclo' => 'mensal', 'dia_fechamento' => 5, 'prazo_dias' => 10], 'a da empresa, em números');
Setting::$valores['company.empresa-1.entregas.cobranca_padrao'] = '{"ciclo":"quinzenal"}';
confere(Financeiro::cobrancaPadrao('empresa-1') === ['ciclo' => 'quinzenal', 'dia_fechamento' => 0, 'prazo_dias' => 3], 'gravada em JSON e incompleta: completa com o padrão');
confere(Financeiro::fuso('empresa-1') === 'America/Sao_Paulo' && Financeiro::fuso('sem-fuso') === 'America/Sao_Paulo', 'fuso da empresa (sem fuso: Brasília)');
Company::$fusos['manaus'] = 'America/Manaus';
Relogio::$agora           = '2026-10-05 23:30:00';
confere(Financeiro::hoje('empresa-1') === '2026-10-05', 'hoje às 23:30 de Brasília ainda é dia 5 (não vira o dia do UTC)');
Relogio::$agora = '2026-10-06 00:30:00';
confere(Financeiro::hoje('manaus') === '2026-10-05', 'hoje no fuso da empresa (Manaus, 1 h atrás)');
confere(Financeiro::numeroDaFatura(12) === 'FAT-0012' && Financeiro::numeroDaFatura(12345) === 'FAT-12345' && Financeiro::numeroDaFatura(null) === null, 'número da fatura');

echo '== Lancamentos' . PHP_EOL;
reiniciarFinanceiro();

function corrida(string $motoboy, float $valor, string $data, string $pedido, array $extra = []): array
{
    return $extra + ['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => $motoboy, 'tipo' => 'corrida', 'valor' => $valor, 'data_referencia' => $data, 'order_uuid' => $pedido, 'chave_unica' => 'corrida:motoboy:' . $pedido, 'detalhes' => ['pedido' => $pedido, 'km' => 3.21]];
}

$id = Lancamentos::inserir(corrida('driver-1', 8.5, '2026-10-02', 'order-1'));
confere(is_int($id) && $id > 0, 'inserir devolve o id');
$linha = Banco::$tabelas['entregas_lancamentos'][$id] ?? [];
confere(strlen((string) ($linha['uuid'] ?? '')) === 36 && $linha['created_at'] === '2026-10-05 15:00:00' && $linha['updated_at'] === '2026-10-05 15:00:00', 'gera o uuid e grava as datas na hora de Brasília');
confere(json_decode((string) $linha['detalhes'], true) === ['pedido' => 'order-1', 'km' => 3.21], 'detalhes em JSON');
confere(Lancamentos::inserir(corrida('driver-1', 9, '2026-10-02', 'order-1')) === null && count(linhasDe('entregas_lancamentos')) === 1, 'chave_unica repetida: null, sem erro e sem segunda linha');
// o insertOrIgnore também engole outros erros (aqui, o uuid repetido): sem a chave_unica gravada, é falha de verdade
$gerados                          = \Illuminate\Support\Str::$gerados;
\Illuminate\Support\Str::$gerados = (int) substr((string) $linha['uuid'], -12) - 1;
$erro                             = excecao(fn () => Lancamentos::inserir(corrida('driver-1', 7, '2026-10-03', 'order-novo')));
\Illuminate\Support\Str::$gerados = $gerados;
confere($erro instanceof \RuntimeException && count(linhasDe('entregas_lancamentos')) === 1, 'insert ignorado por outro motivo (a chave_unica não existe): lança (' . ($erro ? get_class($erro) . ': ' . $erro->getMessage() : 'nada') . ')');
$semChave = Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'diaria', 'valor' => 30, 'data_referencia' => '2026-10-01', 'motivo' => 'Diária', 'criado_por' => 'user-admin']);
$outro    = Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'diaria', 'valor' => 30, 'data_referencia' => '2026-10-01']);
confere($semChave && $outro && $semChave !== $outro, 'sem chave_unica: lança quantas vezes for pedido (duas diárias)');
Lancamentos::inserir(corrida('driver-2', 10, '2026-10-01', 'order-2'));
Lancamentos::inserir(corrida('driver-x', 10, '2026-10-01', 'order-x', ['company_uuid' => 'empresa-2']));

confere(Lancamentos::porId('empresa-1', $id)?->order_uuid === 'order-1' && Lancamentos::porId('empresa-2', $id) === null, 'porId só na empresa');
$uuid = Banco::$tabelas['entregas_lancamentos'][$id]['uuid'];
confere(Lancamentos::porUuid('empresa-1', $uuid)?->id === $id && Lancamentos::porUuid('empresa-2', $uuid) === null, 'porUuid só na empresa');
confere(array_map(fn ($l) => (int) $l->id, Lancamentos::daConta('empresa-1', 'motoboy', 'driver-1')) === [$semChave, $outro, $id], 'daConta: todos da conta, por data e depois id');
confere(Lancamentos::saldos('empresa-1', 'motoboy') === ['driver-1' => 68.5, 'driver-2' => 10.0], 'saldos por conta, em números, só da empresa');
confere(Lancamentos::saldos('empresa-1', 'loja') === [], 'sem lançamento: nenhum saldo');

$estorno = Lancamentos::estornar(Lancamentos::porId('empresa-1', $id), 'user-admin', 'Lançado errado');
$linhaE  = Banco::$tabelas['entregas_lancamentos'][$estorno] ?? [];
confere(is_int($estorno) && $linhaE['tipo'] === 'estorno' && (float) $linhaE['valor'] === -8.5 && $linhaE['estorno_de'] === $id, 'estorno: valor oposto e estorno_de');
confere($linhaE['chave_unica'] === 'estorno:' . $id && $linhaE['conta_uuid'] === 'driver-1' && $linhaE['order_uuid'] === 'order-1' && $linhaE['motivo'] === 'Lançado errado' && $linhaE['criado_por'] === 'user-admin', 'na mesma conta e pedido, com o motivo e quem fez');
confere($linhaE['data_referencia'] === '2026-10-05', 'com a data de hoje (Brasília)');
confere(Lancamentos::estornar(Lancamentos::porId('empresa-1', $id), null, null) === null, 'não estorna duas vezes');
confere(Lancamentos::estornar(Lancamentos::porId('empresa-1', $estorno), null, null) === null, 'não estorna um estorno');
confere(Lancamentos::jaEstornados([$id, $semChave, 999]) === [$id], 'jaEstornados: só os que têm estorno');
confere(Lancamentos::jaEstornados([]) === [], 'jaEstornados sem ids: vazio');

echo '== Pagamentos' . PHP_EOL;
reiniciarFinanceiro();
$comprovante = new UploadedFile('pix do joão.jpg', 'image/jpeg', 200 * 1024);
$pagamento   = Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 150, 'pix', '2026-10-04', $comprovante, 'Pago pelo Itaú', 'user-admin');
$pags        = linhasDe('entregas_pagamentos');
$lancs       = linhasDe('entregas_lancamentos');
confere(count($pags) === 1 && count($lancs) === 1, 'uma linha de pagamento e um lançamento');
confere($pagamento->uuid === $pags[0]['uuid'] && (float) $pags[0]['valor'] === 150.0 && $pags[0]['forma'] === 'pix' && $pags[0]['data'] === '2026-10-04' && $pags[0]['criado_por'] === 'user-admin', 'o pagamento: valor positivo, forma, data e quem fez');
confere($lancs[0]['tipo'] === 'pagamento' && (float) $lancs[0]['valor'] === -150.0 && $lancs[0]['pagamento_uuid'] === $pagamento->uuid && $lancs[0]['data_referencia'] === '2026-10-04' && $lancs[0]['conta_uuid'] === 'driver-1', 'o lançamento: débito com o mesmo pagamento_uuid');
$caminho = 'entregas/comprovantes/empresa-1/' . $pagamento->uuid . '.jpg';
confere($pags[0]['comprovante_path'] === $caminho && isset(Disco::$arquivos['local:' . $caminho]), "comprovante no disco local ({$pags[0]['comprovante_path']})");
confere($pags[0]['comprovante_nome'] === 'pix do joão.jpg' && $pags[0]['comprovante_tipo'] === 'image/jpeg' && $pags[0]['observacao_interna'] === 'Pago pelo Itaú', 'nome, tipo e observação interna');

$recebimento = Pagamentos::registrar('empresa-1', 'loja', 'vendor-a', 99.9, 'transferencia', '2026-10-05', new UploadedFile('boleto.PDF', 'application/pdf', 1024), null, 'user-admin');
$lancR       = Banco::$tabelas['entregas_lancamentos'][2] ?? [];
confere($lancR['tipo'] === 'recebimento' && (float) $lancR['valor'] === -99.9 && $lancR['conta_tipo'] === 'loja', 'conta loja: lançamento recebimento');
confere(str_ends_with((string) Pagamentos::porUuid('empresa-1', $recebimento->uuid)?->comprovante_path, $recebimento->uuid . '.pdf'), 'PDF aceito, extensão pelo tipo');
$semArquivo = Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 50, 'dinheiro', '2026-10-05', null, null, null);
confere($semArquivo->comprovante_path === null && Pagamentos::urlDoComprovante($semArquivo) === null, 'sem comprovante: sem caminho e sem URL');
confere(Pagamentos::porUuid('empresa-2', $pagamento->uuid) === null, 'porUuid só na empresa');

$antes = [count(linhasDe('entregas_pagamentos')), count(linhasDe('entregas_lancamentos'))];
foreach ([
    'arquivo que não é imagem nem PDF' => fn () => Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 10, 'pix', '2026-10-05', new UploadedFile('x.exe', 'application/x-msdownload', 10), null, null),
    'arquivo acima de 10 MB'           => fn () => Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 10, 'pix', '2026-10-05', new UploadedFile('x.jpg', 'image/jpeg', 10 * 1024 * 1024 + 1), null, null),
    'valor zero'                       => fn () => Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 0, 'pix', '2026-10-05', null, null, null),
    'forma desconhecida'               => fn () => Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 10, 'cheque', '2026-10-05', null, null, null),
    'conta desconhecida'               => fn () => Pagamentos::registrar('empresa-1', 'banco', 'x', 10, 'pix', '2026-10-05', null, null, null),
    'data inválida'                    => fn () => Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 10, 'pix', '05/10/2026', null, null, null),
] as $caso => $fazer) {
    $erro = excecao($fazer);
    confere($erro instanceof \InvalidArgumentException && $erro->getMessage() !== '', "{$caso}: recusa ({$erro?->getMessage()})");
}
confere([count(linhasDe('entregas_pagamentos')), count(linhasDe('entregas_lancamentos'))] === $antes, 'nada gravado nas recusas');

Banco::$falhar['entregas_lancamentos'] = 'banco fora do ar';
$erro = excecao(fn () => Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 10, 'pix', '2026-10-05', new UploadedFile('y.png', 'image/png', 10), null, null));
unset(Banco::$falhar['entregas_lancamentos']);
confere($erro !== null && count(linhasDe('entregas_pagamentos')) === $antes[0], 'falha no lançamento desfaz o pagamento (mesma transação)');
confere(count(Disco::$apagados) === 1 && str_ends_with(Disco::$apagados[0], '.png') && !array_filter(array_keys(Disco::$arquivos), fn ($k) => str_ends_with($k, '.png')), 'e apaga o arquivo já salvo');

Relogio::$agora = '2026-10-05 16:00:00';
$url            = Pagamentos::urlDoComprovante($pagamento);
confere(is_string($url) && str_contains($url, $pagamento->uuid), 'URL do comprovante');
confere((URL::$assinadas[0]['nome'] ?? null) === Pagamentos::ROTA_DO_COMPROVANTE && (URL::$assinadas[0]['parametros'] ?? null) === ['uuid' => $pagamento->uuid] && URL::$assinadas[0]['expira']->format('Y-m-d H:i:s') === '2026-10-05 16:30:00', 'assinada, pela rota do comprovante, válida por 30 min');

confere(Pagamentos::ultimoDaConta('empresa-1', 'motoboy', 'driver-1')?->uuid === $semArquivo->uuid, 'último pagamento da conta: o mais recente');
confere(Pagamentos::estornar(Pagamentos::porUuid('empresa-1', $semArquivo->uuid), 'user-admin') === true, 'estornar: true');
$estornado = Pagamentos::porUuid('empresa-1', $semArquivo->uuid);
confere($estornado->estornado_em === '2026-10-05 16:00:00' && $estornado->estornado_por === 'user-admin', 'marca quando e quem estornou');
$estornoP = array_values(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['tipo'] === 'estorno'));
confere(count($estornoP) === 1 && (float) $estornoP[0]['valor'] === 50.0 && $estornoP[0]['pagamento_uuid'] === $semArquivo->uuid, 'cria o estorno positivo do lançamento do pagamento');
confere(Pagamentos::estornar(Pagamentos::porUuid('empresa-1', $semArquivo->uuid), 'user-admin') === false && count(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['tipo'] === 'estorno')) === 1, 'segunda vez: false, sem outro estorno');
confere(Pagamentos::ultimoDaConta('empresa-1', 'motoboy', 'driver-1')?->uuid === $pagamento->uuid, 'o estornado deixa de ser o último pagamento');
confere(Pagamentos::ultimoDaConta('empresa-1', 'motoboy', 'driver-9') === null, 'conta sem pagamento: null');

resumo();
