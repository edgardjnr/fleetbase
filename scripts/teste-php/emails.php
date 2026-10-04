<?php

// E-mails em pt-BR: CodigosPorEmail, EmailsEmPortugues, CanalEmailEntregas e o assunto dos Mailables.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/emails.php

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-emails.php';

// notificações reais do core-api e do Fleet-Ops (cópias em packages/, nas versões da produção)
require '/repo/packages/core-api/src/Notifications/UserForgotPassword.php';
require '/repo/packages/core-api/src/Notifications/UserInvited.php';
require '/repo/packages/core-api/src/Notifications/UserEmailChange.php';
require '/repo/packages/core-api/src/Notifications/UserCreated.php';
require '/repo/packages/core-api/src/Notifications/UserAcceptedCompanyInvite.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderDispatchFailed.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderCompleted.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderFailed.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderCanceled.php';

// as cópias em packages/ (carregadas pelos testes) têm de ser as versões que a produção instala (api/composer.lock)
function confereVersaoDoCore(): void
{
    $local    = json_decode(file_get_contents('/repo/packages/core-api/composer.json'), true)['version'] ?? null;
    $producao = null;
    foreach (json_decode(file_get_contents('/repo/api/composer.lock'), true)['packages'] ?? [] as $pacote) {
        if (($pacote['name'] ?? null) === 'fleetbase/core-api') {
            $producao = ltrim((string) $pacote['version'], 'v');
        }
    }
    confere($local !== null && $local === $producao, "core-api local ({$local}) é a versão da produção ({$producao})");
}

echo '== Versões' . PHP_EOL;
confereVersaoDoCore();
confereVersaoDoFleetOps();

use App\Notifications\Entregas\Email\CodigosPorEmail;
use App\Notifications\Entregas\Email\Saudacao;

echo PHP_EOL . '== Saudação' . PHP_EOL;
confere(Saudacao::para('Ana') === 'Olá, Ana!', 'saudação com nome');
confere(Saudacao::para('  ') === 'Olá!', 'saudação sem nome (só espaços)');
confere(Saudacao::para(null) === 'Olá!', 'saudação sem nome (null)');

echo PHP_EOL . '== Códigos por e-mail' . PHP_EOL;
$codigos = [
    'email_verification'    => ['123456 é o seu código de verificação', 'Confirme seu e-mail', 'Olá, Ana! Use o código abaixo para confirmar seu e-mail no Entregas RestaurantePro.', 'O código vale por 1 hora. Se não foi você, ignore este e-mail.'],
    '2fa'                   => ['123456 é o seu código de acesso', 'Seu código de acesso', 'Olá, Ana! Para concluir a entrada na sua conta, digite este código:', 'Se você não tentou entrar agora, troque sua senha.'],
    'driver_login'          => ['123456 é o seu código para entrar no app', 'Entrar no app de entregas', 'Olá, Ana! Digite este código no app para entrar:', 'Se não foi você, ignore este e-mail.'],
    'driver_password_reset' => ['123456 é o seu código para redefinir a senha', 'Redefinir a senha do app', 'Olá, Ana! Use este código no app para criar uma nova senha:', 'Se não foi você, ignore este e-mail.'],
    'tipo_inventado'        => ['123456 é o seu código', 'Seu código', 'Olá, Ana! Use este código para continuar:', 'Se não foi você, ignore este e-mail.'],
];
foreach ($codigos as $tipo => [$assunto, $titulo, $texto, $observacao]) {
    $t = CodigosPorEmail::textos($tipo, 'Ana');
    confere(CodigosPorEmail::assunto($tipo, '123456') === $assunto, "assunto de {$tipo}");
    confere($t['titulo'] === $titulo && $t['texto'] === $texto && $t['observacao'] === $observacao, "título, texto e observação de {$tipo}");
}
confere(CodigosPorEmail::conhece('2fa') && !CodigosPorEmail::conhece('tipo_inventado') && !CodigosPorEmail::conhece(null), 'conhece só os tipos da tabela');
confere(CodigosPorEmail::textos('2fa', null)['texto'] === 'Olá! Para concluir a entrada na sua conta, digite este código:', 'texto sem nome');


use App\Notifications\Entregas\Email\EmailsEmPortugues;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatchFailed;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\Models\Company;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Notifications\UserAcceptedCompanyInvite;
use Fleetbase\Notifications\UserCreated;
use Fleetbase\Notifications\UserEmailChange;
use Fleetbase\Notifications\UserForgotPassword;
use Fleetbase\Notifications\UserInvited;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

// notificação real sem o construtor (que pede modelos do banco), com as propriedades públicas preenchidas
function notificacao(string $classe, array $propriedades)
{
    $n = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
    foreach ($propriedades as $nome => $valor) {
        $n->{$nome} = $valor;
    }

    return $n;
}

function usuario(string $nome, string $email, string $tipo = 'user'): User
{
    $u        = new User();
    $u->name  = $nome;
    $u->email = $email;
    $u->type  = $tipo;

    return $u;
}

function codigoDeVerificacao(string $codigo, string $uuid, $usuario = null, array $meta = []): VerificationCode
{
    $c          = new VerificationCode();
    $c->code    = $codigo;
    $c->uuid    = $uuid;
    $c->subject = $usuario;
    $c->meta    = $meta;

    return $c;
}

function empresa(string $nome): Company
{
    $c       = new Company();
    $c->name = $nome;

    return $c;
}

function pedido(string $rastreio, ?string $loja = null): \Teste\PedidoDoEmail
{
    $p                 = new \Teste\PedidoDoEmail();
    $p->public_id      = 'order_abc';
    $p->trackingNumber = (object) ['tracking_number' => $rastreio];
    $p->customer       = $loja === null ? null : (object) ['name' => $loja];

    return $p;
}

// o resumo do que o leitor de e-mail mostra: assunto, título, linhas antes e depois do botão, botão
function resumoDoEmail(?MailMessage $m): ?array
{
    return $m === null ? null : [
        'assunto' => $m->subject,
        'titulo'  => $m->greeting,
        'antes'   => $m->introLines,
        'botao'   => $m->actionText,
        'url'     => $m->actionUrl,
        'depois'  => $m->outroLines,
    ];
}

function trackOrder(string $rastreio): MailMessage
{
    return (new MailMessage())->subject('Order ' . $rastreio)->line('english')->action('Track Order', 'https://entregas.restaurantepro.com.br/track-order?order=' . $rastreio);
}

echo PHP_EOL . '== Catálogo: senha, convite e troca de e-mail' . PHP_EOL;

$ana        = usuario('Ana', 'ana@exemplo.com');
$codigoAna  = codigoDeVerificacao('482915', 'vc-1', $ana);
$esqueci    = notificacao(UserForgotPassword::class, ['verificationCode' => $codigoAna, 'url' => 'https://entregas.restaurantepro.com.br/auth/reset-password/vc-1?code=482915']);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($esqueci, $ana, $esqueci->toMail($ana))) === [
    'assunto' => 'Redefina sua senha do Entregas RestaurantePro',
    'titulo'  => 'Redefinir sua senha',
    'antes'   => ['Olá, Ana! Recebemos um pedido para redefinir a senha da sua conta. Toque no botão para criar uma nova.'],
    'botao'   => 'Criar nova senha',
    'url'     => 'https://entregas.restaurantepro.com.br/auth/reset-password/vc-1?code=482915',
    'depois'  => ['Se a página pedir, use o código: 482915', 'Se não foi você, ignore este e-mail: sua senha continua a mesma.'],
], 'esqueci a senha (console)');

$loja = usuario('Terraço', 'loja@exemplo.com', 'customer');
confere(EmailsEmPortugues::traduzir($esqueci, $loja, $esqueci->toMail($loja))->actionUrl === 'https://entregas.restaurantepro.com.br/customer-portal/auth/reset-password/vc-1?code=482915', 'esqueci a senha (loja): link do portal');

$convite          = new Invite();
$convite->code    = 'X7K2Q9';
$convidou         = notificacao(UserInvited::class, ['invite' => $convite, 'company' => empresa('Central Entregas'), 'sender' => usuario('Edgard', 'edgard@exemplo.com'), 'url' => 'https://entregas.restaurantepro.com.br/join/org/abc']);
$bruno            = usuario('Bruno', 'bruno@exemplo.com');
confere(resumoDoEmail(EmailsEmPortugues::traduzir($convidou, $bruno, $convidou->toMail($bruno))) === [
    'assunto' => 'Você foi convidado para a equipe Central Entregas',
    'titulo'  => 'Você foi convidado!',
    'antes'   => ['Olá, Bruno! Edgard convidou você para a equipe Central Entregas no Entregas RestaurantePro.'],
    'botao'   => 'Aceitar convite',
    'url'     => 'https://entregas.restaurantepro.com.br/join/org/abc',
    'depois'  => ['Código do convite: X7K2Q9'],
], 'convite');

$semRemetente = notificacao(UserInvited::class, ['invite' => $convite, 'company' => empresa('Central Entregas'), 'url' => 'https://entregas.restaurantepro.com.br/join/org/abc']);
confere(EmailsEmPortugues::traduzir($semRemetente, $bruno, new MailMessage())->introLines === ['Olá, Bruno! Você foi convidado para a equipe Central Entregas no Entregas RestaurantePro.'], 'convite sem quem convidou');

$troca = notificacao(UserEmailChange::class, [
    'verificationCode' => codigoDeVerificacao('731904', 'vc-2', $ana, ['old_email' => 'ana@antigo.com', 'new_email' => 'ana@novo.com']),
    'url'              => 'https://entregas.restaurantepro.com.br/auth/confirm-email-change/vc-2?code=731904',
]);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($troca, $ana, $troca->toMail($ana))) === [
    'assunto' => 'Confirme seu novo e-mail',
    'titulo'  => 'Confirme seu novo e-mail',
    'antes'   => ['Olá, Ana! Foi pedida a troca do e-mail de login da sua conta.', 'E-mail atual: ana@antigo.com', 'Novo e-mail: ana@novo.com'],
    'botao'   => 'Confirmar troca de e-mail',
    'url'     => 'https://entregas.restaurantepro.com.br/auth/confirm-email-change/vc-2?code=731904',
    'depois'  => ['Se o botão não abrir, use o código: 731904', 'Se não foi você, ignore este e-mail: o e-mail da conta não muda.'],
], 'troca de e-mail');

echo PHP_EOL . '== Catálogo: avisos opcionais da equipe' . PHP_EOL;

$novo = notificacao(UserCreated::class, ['user' => usuario('Bruno', 'bruno@exemplo.com'), 'company' => empresa('Central Entregas')]);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($novo, $ana, new MailMessage())) === [
    'assunto' => 'Novo usuário na equipe Central Entregas',
    'titulo'  => 'Novo usuário na equipe',
    'antes'   => ["Bruno (bruno​@exemplo​.​com) entrou na equipe Central Entregas."], // delinkify real: U+200B quebra o autolink
    'botao'   => 'Ver usuários',
    'url'     => 'https://entregas.restaurantepro.com.br/iam/users',
    'depois'  => [],
], 'novo usuário');

$aceito = notificacao(UserAcceptedCompanyInvite::class, ['user' => usuario('Bruno', 'bruno@exemplo.com'), 'company' => empresa('Central Entregas')]);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($aceito, $ana, new MailMessage())) === [
    'assunto' => 'Bruno aceitou o convite para a equipe Central Entregas',
    'titulo'  => 'Convite aceito',
    'antes'   => ['Bruno agora faz parte da equipe Central Entregas.'],
    'botao'   => 'Ver a equipe',
    'url'     => 'https://entregas.restaurantepro.com.br/iam/users',
    'depois'  => [],
], 'convite aceito');

echo PHP_EOL . '== Catálogo: pedidos' . PHP_EOL;

$falha = notificacao(OrderDispatchFailed::class, ['order' => pedido('ABC123', 'Terraço Pizza Bar'), 'reason' => 'No driver assigned for order to dispatch to.']);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($falha, $ana, trackOrder('ABC123'))) === [
    'assunto' => 'Pedido ABC123 não foi despachado',
    'titulo'  => 'O pedido não foi despachado',
    'antes'   => ['O pedido ABC123 da loja Terraço Pizza Bar não pôde ser despachado.', 'Motivo: nenhum motoboy atribuído ao pedido.'],
    'botao'   => 'Acompanhar o pedido',
    'url'     => 'https://entregas.restaurantepro.com.br/track-order?order=ABC123',
    'depois'  => [],
], 'falha no despacho');

$avisado = notificacao(OrderDispatchFailed::class, ['order' => pedido('ABC123'), 'reason' => 'Order was dispatched, but driver was unable to be notified.']);
confere(EmailsEmPortugues::traduzir($avisado, $ana, trackOrder('ABC123'))->introLines === ['O pedido ABC123 não pôde ser despachado.', 'Motivo: o pedido foi despachado, mas o motoboy não pôde ser avisado.'], 'falha no despacho: sem loja e o outro motivo');

Log::$registros = [];
$estranho       = notificacao(OrderDispatchFailed::class, ['order' => pedido('ABC123'), 'reason' => 'Something else']);
confere(EmailsEmPortugues::traduzir($estranho, $ana, trackOrder('ABC123'))->introLines[1] === 'Motivo: não informado.', 'motivo desconhecido vira "não informado"');
confere((Log::$registros[0][1] ?? null) === '[entregas] motivo de falha de despacho sem tradução' && (Log::$registros[0][2]['motivo'] ?? null) === 'Something else', 'motivo desconhecido vai para o log');

$semRastreio                 = pedido('');
$semRastreio->trackingNumber = null;
$porId                       = notificacao(OrderDispatchFailed::class, ['order' => $semRastreio, 'reason' => 'No driver assigned for order to dispatch to.']);
confere(EmailsEmPortugues::traduzir($porId, $ana, new MailMessage())->subject === 'Pedido order_abc não foi despachado', 'sem rastreio usa o public_id');
confere(EmailsEmPortugues::traduzir($porId, $ana, new MailMessage())->actionText === null, 'sem URL na original, sem botão');

$entregue = notificacao(OrderCompleted::class, ['order' => pedido('RP-1')]);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($entregue, $ana, trackOrder('RP-1'))) === [
    'assunto' => 'Pedido RP-1 entregue',
    'titulo'  => 'Pedido entregue',
    'antes'   => ['O pedido RP-1 foi entregue.'],
    'botao'   => 'Acompanhar o pedido',
    'url'     => 'https://entregas.restaurantepro.com.br/track-order?order=RP-1',
    'depois'  => [],
], 'pedido entregue');

$falhou = notificacao(OrderFailed::class, ['order' => pedido('RP-1'), 'reason' => 'Cliente ausente']);
confere(EmailsEmPortugues::traduzir($falhou, $ana, trackOrder('RP-1'))->introLines === ['A entrega do pedido RP-1 falhou.', 'Motivo: Cliente ausente'], 'pedido com falha, com motivo');
confere(EmailsEmPortugues::traduzir($falhou, $ana, trackOrder('RP-1'))->subject === 'Pedido RP-1 com falha na entrega', 'assunto do pedido com falha');

$cancelado = notificacao(OrderCanceled::class, ['order' => pedido('RP-1')]);
confere(EmailsEmPortugues::traduzir($cancelado, $ana, trackOrder('RP-1'))->introLines === ['O pedido RP-1 foi cancelado.'], 'pedido cancelado sem motivo');
confere(EmailsEmPortugues::traduzir($cancelado, $ana, trackOrder('RP-1'))->subject === 'Pedido RP-1 cancelado', 'assunto do pedido cancelado');

echo PHP_EOL . '== Catálogo: desconhecido e falha de leitura' . PHP_EOL;

$inventada = new class extends \Illuminate\Notifications\Notification {};
confere(!EmailsEmPortugues::conhece($inventada) && EmailsEmPortugues::traduzir($inventada, $ana, new MailMessage()) === null, 'notificação desconhecida: null');

Log::$registros = [];
$quebrada       = notificacao(UserForgotPassword::class, ['url' => 'x']); // sem verificationCode
confere(EmailsEmPortugues::conhece($quebrada) && EmailsEmPortugues::traduzir($quebrada, $ana, new MailMessage()) === null, 'leitura que falha: null');
confere((Log::$registros[0][1] ?? null) === '[entregas] falha ao montar o e-mail em pt-BR', 'leitura que falha vai para o log');

echo PHP_EOL . '== Texto sem escape duplo' . PHP_EOL;
// o delinkify do core-api já escapa HTML e a view escapa de novo: o texto do e-mail tem de sair puro
confere(Saudacao::para("Joana D'Arc") === "Olá, Joana D'Arc!", 'saudação com apóstrofo não vira &#039;');
confere(Saudacao::para("​  ") === 'Olá!', 'saudação: só espaço de largura zero vale sem nome');

$convitePao = notificacao(UserInvited::class, ['invite' => $convite, 'company' => empresa('Pão & Cia'), 'sender' => usuario("Bob's", 'bob@exemplo.com'), 'url' => 'https://entregas.restaurantepro.com.br/join/org/abc']);
$mensagemPao = EmailsEmPortugues::traduzir($convitePao, $bruno, new MailMessage());
confere($mensagemPao->subject === 'Você foi convidado para a equipe Pão & Cia', 'convite: assunto com "&" sem escape');
confere($mensagemPao->introLines === ["Olá, Bruno! Bob's convidou você para a equipe Pão & Cia no Entregas RestaurantePro."], 'convite: linha sem &amp; nem &#039;');

$mensagemNovo = EmailsEmPortugues::traduzir($novo, $ana, new MailMessage());
$textosDoNovo = array_merge([$mensagemNovo->subject], $mensagemNovo->introLines);
confere(count(array_filter($textosDoNovo, fn ($t) => str_contains($t, '&#'))) === 0, 'novo usuário: nenhuma entidade &# no assunto nem nas linhas');

use App\Notifications\Entregas\Email\CanalEmailEntregas;
use Fleetbase\FleetOps\Models\Driver;

echo PHP_EOL . '== Canal de e-mail' . PHP_EOL;

$carteiro = new \Teste\CarteiroFalso();
$canal    = new CanalEmailEntregas($carteiro, null);

// motoboy (Driver) não recebe e-mail de pedido
Log::$registros = [];
$motoboy            = new Driver();
$motoboy->public_id = 'driver_1';
confere($canal->send($motoboy, $entregue) === null && $carteiro->enviados === [], 'motoboy: e-mail de pedido não sai');
confere((Log::$registros[0][1] ?? null) === '[entregas] e-mail ao motoboy não enviado' && (Log::$registros[0][2]['motoboy'] ?? null) === 'driver_1', 'motoboy: corte no log');

// usuário do tipo motoboy não recebe o convite de organização
$usuarioMotoboy = usuario('Carlos', 'carlos@exemplo.com', 'driver');
confere($canal->send($usuarioMotoboy, $convidou) === null && $carteiro->enviados === [], 'convite para usuário motoboy não sai');

// notificação conhecida sai em pt-BR, com os dados da mensagem e o nome da notificação
confere($canal->send($ana, $esqueci) === 'enviado', 'esqueci a senha sai');
$dados = $carteiro->enviados[0]['dados'] ?? [];
confere(($dados['subject'] ?? null) === 'Redefina sua senha do Entregas RestaurantePro' && ($dados['actionText'] ?? null) === 'Criar nova senha', 'sai com assunto e botão em pt-BR');
confere(($dados['__laravel_notification'] ?? null) === UserForgotPassword::class, 'mantém o nome da notificação nos dados');

// usuário de loja: link do portal também pelo canal
$carteiro->enviados = [];
$canal->send($loja, $esqueci);
confere(str_contains($carteiro->enviados[0]['dados']['actionUrl'] ?? '', '/customer-portal/auth/reset-password/vc-1'), 'loja: link do portal pelo canal');

// desconhecida: sai como veio, com aviso no log
$carteiro->enviados = [];
Log::$registros     = [];
$semTraducao        = new class extends \Illuminate\Notifications\Notification {
    public function toMail($notifiable) { return (new MailMessage())->subject('Original in English')->line('english'); }
};
confere($canal->send($ana, $semTraducao) === 'enviado' && ($carteiro->enviados[0]['dados']['subject'] ?? null) === 'Original in English', 'desconhecida sai como veio');
confere((Log::$registros[0][1] ?? null) === '[entregas] e-mail sem tradução', 'desconhecida vai para o log');

// sem endereço de e-mail: não envia (como o MailChannel do Laravel)
$carteiro->enviados = [];
$semEmail           = usuario('Sem', '');
confere($canal->send($semEmail, $esqueci) === null && $carteiro->enviados === [], 'sem e-mail: não envia');

// a configuração de envio da original (remetente, mailer, etiquetas) passa para a mensagem traduzida
confere(CanalEmailEntregas::copiarEnvio((new MailMessage())->from('central@exemplo.com', 'Central')->mailer('smtp')->tag('senha'), new MailMessage())->from === ['central@exemplo.com', 'Central'], 'copia o remetente da original');

use App\Listeners\Entregas\AssuntoDosEmailsEmPortugues;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;

echo PHP_EOL . '== Assunto dos Mailables' . PHP_EOL;

$escuta = new AssuntoDosEmailsEmPortugues();
// $data como o Laravel monta: dados da view (Mailable::buildViewData) + 'mailer'
$casos = [
    'verificação' => [['code' => '123456', 'type' => 'email_verification', 'mailer' => 'smtp'], '123456 is your Entregas verification code', '123456 é o seu código de verificação'],
    '2FA'         => [['code' => '123456', 'type' => '2fa', 'mailer' => 'smtp'], '123456 is your Entregas verification code', '123456 é o seu código de acesso'],
    'motoboy'     => [['code' => '123456', 'type' => 'driver_login', 'mailer' => 'smtp'], '123456 is your Entregas verification code', '123456 é o seu código para entrar no app'],
    'credenciais' => [['plaintextPassword' => 'x', 'user' => null, 'mailer' => 'smtp'], 'Your login credentials for Central on Entregas', 'Seus dados de acesso ao Entregas RestaurantePro'],
    'teste'       => [['mailSubject' => '🎉 Your Fleetbase Mail Configuration Works!', 'mailer' => 'smtp'], '🎉 Your Fleetbase Mail Configuration Works!', 'Teste de e-mail do Entregas RestaurantePro'],
    'notificação' => [['code' => '1', 'type' => '2fa', '__laravel_notification' => UserForgotPassword::class], 'Redefina sua senha do Entregas RestaurantePro', 'Redefina sua senha do Entregas RestaurantePro'],
    'outro'       => [['qualquer' => 1], 'Assunto qualquer', 'Assunto qualquer'],
];
foreach ($casos as $nome => [$dados, $antes, $depois]) {
    $email   = (new Email())->subject($antes);
    $retorno = $escuta->handle(new MessageSending($email, $dados));
    confere($email->getSubject() === $depois, "assunto: {$nome}");
    confere($retorno === null, "não cancela o envio: {$nome}");
}

Log::$registros = [];
$email          = (new Email())->subject('x');
$escuta->handle(new MessageSending($email, ['code' => '9', 'type' => 'tipo_inventado']));
confere($email->getSubject() === '9 é o seu código' && (Log::$registros[0][1] ?? null) === '[entregas] e-mail sem tradução', 'código de tipo desconhecido: assunto genérico e log');

resumo();
