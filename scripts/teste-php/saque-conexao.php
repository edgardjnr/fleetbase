<?php

// Saque Pix: quem pode configurar (AdminDaCentral) e a conexão da central com o Asaas (ConexaoAsaas): conectar, trocar
// a chave, desconectar, token da validação, teste da validação e limites.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-conexao.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\AdminDaCentral;
use App\Support\Entregas\Saques\Cofre;
use App\Support\Entregas\Saques\CofreIndisponivel;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\ErroDoAsaas;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\Models\CompanyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teste\Http as HttpFalso;
use Teste\Relogio;
use Teste\UsuarioDoSaque;

function mensagem(callable $fazer): ?string
{
    return excecao($fazer)?->getMessage();
}

function conexao(): ?object
{
    return ConexaoAsaas::daEmpresa('empresa-1');
}

echo '== AdminDaCentral' . PHP_EOL;
reiniciarSaque();
session(['company' => 'empresa-1']);
\Fleetbase\Support\Auth::$usuario = null;
confere(AdminDaCentral::usuario(new Request()) === null, 'sem usuário: ninguém');
\Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', 'admin');
confere(AdminDaCentral::usuario(new Request())?->uuid === 'user-admin', 'administrador do sistema pode');
\Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-org');
confere(AdminDaCentral::usuario(new Request()) === null, 'usuário comum sem papel: não pode');
CompanyUser::$todos[] = new CompanyUser(['user_uuid' => 'user-org', 'company_uuid' => 'empresa-2', 'papeis' => ['Administrator']]);
confere(AdminDaCentral::usuario(new Request()) === null, 'Administrator de OUTRA organização: não pode');
CompanyUser::$todos[] = new CompanyUser(['user_uuid' => 'user-org', 'company_uuid' => 'empresa-1', 'papeis' => ['Operador']]);
confere(AdminDaCentral::usuario(new Request()) === null, 'outro papel na organização: não pode');
CompanyUser::$todos[] = new CompanyUser(['user_uuid' => 'user-org', 'company_uuid' => 'empresa-1', 'papeis' => ['Administrator']]);
confere(AdminDaCentral::usuario(new Request())?->uuid === 'user-org', 'papel Administrator na organização da sessão: pode');

echo '== Conectar' . PHP_EOL;
reiniciarSaque();
confere(mensagem(fn () => ConexaoAsaas::conectar('empresa-1', 'curta', 'user-admin')) === 'Chave de API inválida.', 'chave curta: inválida');
confere(mensagem(fn () => ConexaoAsaas::conectar('empresa-1', 'chave com espaco no meio 123', 'user-admin')) === 'Chave de API inválida.', 'chave com espaço: inválida');
unlink(CHAVE_DO_COFRE);
confere(excecao(fn () => ConexaoAsaas::conectar('empresa-1', CHAVE_ASAAS, 'user-admin')) instanceof CofreIndisponivel && HttpFalso::$chamadas === [], 'sem o secret: CofreIndisponivel e nem chama o Asaas');
reiniciarSaque();
asaas(401, ['errors' => [['description' => 'A chave de API informada não pertence a este ambiente']]]);
$erro = excecao(fn () => ConexaoAsaas::conectar('empresa-1', CHAVE_ASAAS, 'user-admin'));
confere($erro instanceof \InvalidArgumentException && $erro->getMessage() === 'Chave de API inválida ou sem permissão. Gere uma nova no Asaas (Integrações → Chaves de API).', 'chave recusada pelo Asaas: 422 com a orientação');
HttpFalso::$respostas[] = 'conexao';
confere(excecao(fn () => ConexaoAsaas::conectar('empresa-1', CHAVE_ASAAS, 'user-admin')) instanceof ErroDoAsaas, 'Asaas fora do ar: ErroDoAsaas (502)');
asaas(200, ['name' => 'Central Teste']);
asaas(200, ['data' => []]);
confere(mensagem(fn () => ConexaoAsaas::conectar('empresa-1', CHAVE_ASAAS, 'user-admin')) === 'O Asaas não informou a carteira desta conta.', 'sem wallet: erro');
confere(linhasDe('entregas_asaas_conexoes') === [], 'nada gravado nas recusas');

asaas(200, ['name' => 'Central Teste', 'cpfCnpj' => '12.345.678/0001-99']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
$c = ConexaoAsaas::conectar('empresa-1', '  ' . CHAVE_ASAAS . ' ', 'user-admin');
confere($c->wallet_id === 'wallet-1' && $c->conta_nome === 'Central Teste' && $c->conta_documento === '12345678000199' && $c->conectada_por === 'user-admin' && $c->conectada_em === '2026-10-27 10:00:00', 'conectada: wallet, nome, documento (dígitos), quem e quando');
confere(Cofre::decifrar($c->chave_cifrada) === CHAVE_ASAAS, 'a chave (sem os espaços) decifra');
confere(!str_contains(json_encode(linhasDe('entregas_asaas_conexoes')), CHAVE_ASAAS), 'no banco, só a chave cifrada');
$pub = ConexaoAsaas::publico($c, 'empresa-1');
confere(!str_contains(json_encode($pub), 'v1:') && !array_key_exists('chave_cifrada', $pub) && !array_key_exists('token_hash', $pub), 'público: nada da chave nem do token');
confere($pub['conectada'] === true && $pub['conta'] === ['nome' => 'Central Teste', 'documento' => '**.345.678/0001-**', 'wallet' => 'wallet-1'], 'público: conta com o documento escondido');
confere($pub['saque_ligado'] === false && $pub['token_gerado'] === false && $pub['validacao_confirmada_em'] === null, 'público: saque desligado, sem token, sem validação');
confere($pub['limites'] === ['minimo' => 10.0, 'maximo_dia' => 500.0, 'saques_dia' => 3, 'taxa' => 0.0], 'público: limites padrão: ' . json_encode($pub['limites']));
confere($pub['url_da_validacao'] === 'https://entregas-api.teste/v1/entregas/asaas/validar-saque', 'público: a URL para colar no Asaas');
confere(ConexaoAsaas::publico(null, 'empresa-1') === ['conectada' => false, 'ligada' => true, 'cofre' => true], 'sem conexão: só conectada false, interruptor e cofre');

echo '== Trocar a chave' . PHP_EOL;
asaas(200, ['name' => 'Outra Central']);
asaas(200, ['data' => [['id' => 'wallet-2']]]);
confere(mensagem(fn () => ConexaoAsaas::conectar('empresa-1', 'chave-de-outra-conta-0000', 'user-admin')) === 'Esta chave é de outra conta Asaas. Para mudar de conta, desconecte e conecte de novo.', 'chave de outra conta: recusada');
confere(Cofre::decifrar(conexao()->chave_cifrada) === CHAVE_ASAAS, 'a chave antiga continua');
asaas(200, ['name' => 'Central Teste']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
ConexaoAsaas::conectar('empresa-1', 'chave-nova-da-mesma-conta-999', 'user-2');
confere(Cofre::decifrar(conexao()->chave_cifrada) === 'chave-nova-da-mesma-conta-999' && conexao()->conectada_por === 'user-2' && count(linhasDe('entregas_asaas_conexoes')) === 1, 'mesma conta: troca a chave, uma linha só');

echo '== Token da validação' . PHP_EOL;
$token = ConexaoAsaas::gerarToken('empresa-1');
confere(strlen($token) === 48 && ctype_xdigit($token), 'token de 48 caracteres hexadecimais');
confere(conexao()->token_hash === hash('sha256', $token) && !str_contains(json_encode(linhasDe('entregas_asaas_conexoes')), $token), 'no banco, só o hash do token');
confere(ConexaoAsaas::publico(conexao(), 'empresa-1')['token_gerado'] === true, 'público: token gerado');
confere(mensagem(fn () => ConexaoAsaas::gerarToken('empresa-9')) === 'Conecte a conta Asaas primeiro.', 'sem conexão: não gera token');

echo '== Teste da validação' . PHP_EOL;
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => true], 'user-admin')) === 'Teste a validação de saque antes de ligar.', 'não liga sem a validação testada');
confere(mensagem(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '123', 'user-admin')) === 'Chave Pix inválida para o tipo escolhido.', 'teste: chave Pix inválida');
$antes = count(HttpFalso::$chamadas);
asaas(200, ['id' => 'tra_teste', 'status' => 'PENDING']);
ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '123.456.789-01', 'user-admin');
$c = HttpFalso::$chamadas[$antes];
confere($c['metodo'] === 'POST' && $c['url'] === 'https://asaas.teste/v3/transfers' && $c['dados']['value'] === 1.0 && $c['dados']['pixAddressKey'] === '12345678901' && $c['dados']['pixAddressKeyType'] === 'CPF' && str_starts_with($c['dados']['externalReference'], 'teste:'), 'teste: R$ 1,00 para a chave do admin: ' . json_encode($c['dados']));
confere(($c['headers']['access_token'] ?? null) === 'chave-nova-da-mesma-conta-999', 'teste: com a chave da central decifrada');
confere(conexao()->teste_transfer_id === 'tra_teste' && conexao()->teste_pedido_em === '2026-10-27 10:00:00' && conexao()->validacao_confirmada_em === null, 'teste pedido: id gravado, validação ainda não confirmada');
confere(conexao()->teste_pedido_por === 'user-admin' && (int) conexao()->testes_no_dia === 1 && conexao()->testes_dia === '2026-10-27', 'teste pedido: quem pediu e a contagem do dia');
confere(end(Log::$registros) === ['info', '[entregas] saque: teste da validação pedido', ['empresa' => 'empresa-1', 'por' => 'user-admin', 'transfer' => 'tra_teste', 'chave' => '*******8901']], 'log: quem pediu e a chave escondida: ' . json_encode(end(Log::$registros), JSON_UNESCAPED_UNICODE));
Relogio::$agora = '2026-10-27 10:02:00';
asaas(400, ['errors' => [['description' => 'Saldo insuficiente.']]]);
confere(mensagem(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901', 'user-admin')) === 'Saldo insuficiente.', 'teste recusado pelo Asaas: a mensagem dele');
Relogio::$agora = '2026-10-27 10:04:00';
HttpFalso::$respostas[] = 'conexao';
confere(excecao(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901', 'user-admin')) instanceof ErroDoAsaas, 'teste sem resposta: ErroDoAsaas');

echo '== Teste da validação: um por vez e no máximo 5 por dia' . PHP_EOL;
centralComSaque(['validacao_confirmada_em' => null, 'saque_ligado' => false]);
/** Pede o teste às $quando (com a resposta $id do Asaas, se vier): null se passou, a mensagem se recusou. */
function testar(string $quando, ?string $id = null): ?string
{
    Relogio::$agora = $quando;
    if ($id !== null) {
        asaas(200, ['id' => $id, 'status' => 'PENDING']);
    }
    $chamadas = count(HttpFalso::$chamadas);
    $erro     = mensagem(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901', 'user-admin'));
    if ($erro !== null && count(HttpFalso::$chamadas) !== $chamadas) {
        return 'chamou o Asaas: ' . $erro;
    }

    return $erro;
}
confere(testar('2026-10-27 10:00:00', 'tra_1') === null, '1º teste do dia');
confere(testar('2026-10-27 10:01:59') === 'Espere o resultado do teste anterior.' && (int) conexao()->testes_no_dia === 1 && conexao()->teste_transfer_id === 'tra_1', 'outro teste antes de 2 min, sem a validação confirmar: recusado sem chamar o Asaas');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['validacao_confirmada_em' => '2026-10-27 10:00:30']);
confere(testar('2026-10-27 10:01:59', 'tra_2') === null && (int) conexao()->testes_no_dia === 2, 'validação confirmada: pode testar de novo logo');
confere(testar('2026-10-27 10:04:00', 'tra_3') === null, '3º: o anterior sem resposta há 2 min já não segura');
confere(testar('2026-10-27 10:06:00', 'tra_4') === null && testar('2026-10-27 10:08:00', 'tra_5') === null && (int) conexao()->testes_no_dia === 5, '4º e 5º');
confere(testar('2026-10-27 23:59:00') === 'Limite de testes de hoje atingido.' && (int) conexao()->testes_no_dia === 5 && conexao()->teste_transfer_id === 'tra_5', '6º no mesmo dia: recusado sem chamar o Asaas');
confere(testar('2026-10-28 00:00:30', 'tra_6') === null && (int) conexao()->testes_no_dia === 1 && conexao()->testes_dia === '2026-10-28', 'dia seguinte (Brasília): a contagem recomeça');
confere(conexao()->teste_pedido_por === 'user-admin', 'quem pediu fica gravado');

echo '== Teste que falha não desliga o saque' . PHP_EOL;
centralComSaque();
/** A validação e o teste da central como estão no banco. */
function estadoDoTeste(): array
{
    $c = conexao();

    return [$c->validacao_confirmada_em, $c->teste_transfer_id, $c->teste_pedido_em, $c->teste_pedido_por];
}
$confirmada = estadoDoTeste();
asaas(400, ['errors' => [['description' => 'Saldo insuficiente.']]]);
confere(mensagem(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901', 'user-admin')) === 'Saldo insuficiente.', 'central ligada e confirmada, teste recusado (400): a mensagem do Asaas');
confere(estadoDoTeste() === $confirmada && Saques::situacaoDoMotoboy('empresa-1', 'driver-1') !== null && (int) conexao()->testes_no_dia === 1, 'a validação continua confirmada e o saque liberado (o teste conta no dia): ' . json_encode(estadoDoTeste()));
Relogio::$agora = '2026-10-27 10:00:10';
HttpFalso::$respostas[] = 'conexao';
confere(excecao(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901', 'user-admin')) instanceof ErroDoAsaas, 'teste sem resposta: ErroDoAsaas');
confere(estadoDoTeste() === $confirmada && Saques::situacaoDoMotoboy('empresa-1', 'driver-1') !== null, 'sem resposta: idem');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['validacao_confirmada_em' => null, 'teste_transfer_id' => 'tra_antigo', 'teste_pedido_em' => '2026-10-27 09:00:00', 'teste_pedido_por' => 'user-2']);
asaas(400, ['errors' => [['description' => 'Saldo insuficiente.']]]);
excecao(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901', 'user-admin'));
confere(estadoDoTeste() === [null, 'tra_antigo', '2026-10-27 09:00:00', 'user-2'], 'teste anterior sem resposta: volta a ele: ' . json_encode(estadoDoTeste()));

echo '== Teste recusado que a validação confirmou durante a chamada' . PHP_EOL;
centralComSaque(['validacao_confirmada_em' => null, 'saque_ligado' => false]);
HttpFalso::responderCom(function () {
    // a validação do Asaas chega (e confirma) antes de o POST /transfers responder com a recusa
    DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['validacao_confirmada_em' => '2026-10-27 10:00:01']);

    return [400, ['errors' => [['description' => 'Transferência recusada pelo mecanismo de validação.']]]];
});
$c = null;
$erro = excecao(function () use (&$c) { $c = ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901', 'user-admin'); });
confere($erro === null && $c?->validacao_confirmada_em === '2026-10-27 10:00:01' && $c->teste_pedido_em === '2026-10-27 10:00:00', 'sem erro: devolve a conexão com a validação confirmada' . ($erro ? ': ' . $erro->getMessage() : ''));

echo '== Limites e ligar' . PHP_EOL;
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['validacao_confirmada_em' => '2026-10-27 10:00:05']);
ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => true, 'minimo' => '20,00', 'maximo_dia' => 300, 'saques_dia' => '2', 'taxa' => '1.5'], 'user-admin');
confere(ConexaoAsaas::limites(conexao()) === ['minimo' => 20.0, 'maximo_dia' => 300.0, 'saques_dia' => 2, 'taxa' => 1.5] && (bool) conexao()->saque_ligado && conexao()->atualizada_por === 'user-admin', 'limites e saque ligado');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['minimo' => 0.5], 'u')) === 'O mínimo por saque é de pelo menos R$ 1,00.', 'mínimo abaixo de R$ 1');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['maximo_dia' => 10], 'u')) === 'O máximo por dia precisa ser maior ou igual ao mínimo por saque.', 'máximo abaixo do mínimo');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['saques_dia' => 0], 'u')) === 'Saques por dia: de 1 a 20.', 'saques por dia 0');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['taxa' => 25], 'u')) === 'A taxa vai de R$ 0,00 a R$ 10,00.', 'taxa acima de 10');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['minimo' => 5, 'taxa' => 5], 'u')) === 'A taxa precisa ser menor que o mínimo por saque.', 'taxa igual ao mínimo');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['minimo' => 'abc'], 'u')) === 'Valor inválido: mínimo por saque.', 'mínimo que não é número');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => 'talvez'], 'u')) === 'Informe se o saque fica ligado.', 'ligado que não é sim/não');
confere(ConexaoAsaas::limites(conexao())['minimo'] === 20.0, 'recusa não muda nada');
ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => false], 'u');
confere(!(bool) conexao()->saque_ligado, 'desligar sempre pode');

echo '== Token novo pede novo teste' . PHP_EOL;
ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => true], 'u');
ConexaoAsaas::gerarToken('empresa-1');
confere(!(bool) conexao()->saque_ligado && conexao()->validacao_confirmada_em === null && conexao()->teste_transfer_id === null, 'gerar outro token desliga o saque e apaga a validação');

echo '== Desconectar' . PHP_EOL;
DB::table('entregas_saques')->insert(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'company_uuid' => 'empresa-1', 'driver_uuid' => 'driver-1', 'valor' => 10, 'chave_pix' => '1', 'tipo_chave' => 'cpf', 'situacao' => 'processando', 'external_reference' => 'saque:x', 'created_at' => '2026-10-27 09:00:00', 'updated_at' => '2026-10-27 09:00:00']);
confere(mensagem(fn () => ConexaoAsaas::desconectar('empresa-1', 'user-admin')) === 'Há saque em andamento; espere ele terminar.', 'não desconecta com saque em andamento');
DB::table('entregas_saques')->where('uuid', 'aaaaaaaa-0000-4000-8000-000000000001')->update(['situacao' => 'concluido']);
ConexaoAsaas::desconectar('empresa-1', 'user-admin');
confere(conexao() === null, 'desconectada: a linha (chave, token, limites) some');
confere(mensagem(fn () => ConexaoAsaas::desconectar('empresa-1', 'user-admin')) === 'A conta Asaas não está conectada.', 'desconectar de novo: erro');

resumo();
