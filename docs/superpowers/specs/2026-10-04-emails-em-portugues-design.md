# E-mails em português com o layout do Entregas RestaurantePro: desenho

Data: 2026-10-04. Aprovado pelo Edgard na conversa: estilo C, tom "você", rodapé só com aviso, motoboy sem e-mail e
abordagem 1.

## Objetivo

Hoje todos os e-mails saem em inglês e em dois visuais diferentes:

- os Mailables (códigos, credenciais e teste) usam o layout da Fleetbase com logo;
- as notificações (senha, convite, pedido) usam o modelo padrão do Laravel, sem logo.

O SMTP já está ligado em Admin → Configurações, então esses e-mails chegam às pessoas. A meta é:

- um layout só para todos os e-mails, no estilo C (minimalista, como uma carta);
- todos os textos em pt-BR, no tom "você";
- o motoboy deixa de receber e-mail, exceto o código de login quando o SMS falha;
- dois defeitos corrigidos:
  - o "esqueci a senha" da loja manda para o console;
  - a rota de reset do portal não recebe o id.

Fica de fora: e-mails das extensões que não estão no console (storefront, ledger, registry-bridge) e o código morto
(`PasswordReset`, `WaypointCompleted`, `OrderSplit`).

## Restrição

Em produção, a API usa os pacotes publicados no Composer: core-api 1.6.61, fleetops-api 0.6.65 e
customer-portal-api 0.0.13. As cópias em `packages/` estão nas mesmas versões e servem só de referência. Nada em
`packages/*/server` nem em `packages/core-api` chega à produção. Por isso:

- tudo o que é do lado da API fica em `api/` (views, `app/`);
- a única mudança de frontend é a rota do portal, em `packages/customer-portal/addon`, que entra no build do console.

Nenhum texto dos pacotes passa por `__()`: os assuntos e as linhas estão fixos em inglês no PHP ou no Blade. A única
exceção é o `@lang('All Rights Reserved.')` do layout.

## Visual (estilo C)

- **Fundo e conteúdo:** fundo branco, sem cartão; largura útil de 560 px, centralizada.
- **Cabeçalho:** `logo.png` (o mesmo do `Setting::getBrandingLogoUrl()`, que cai em
  `api/config/fleetbase.php` → `https://entregas.restaurantepro.com.br/images/logo.png`). Altura fixa de 40 px com
  largura automática: hoje o layout força 200×35 e distorce. Uma linha cinza-clara embaixo.
- **Corpo:**
  - título em negrito, 20 px, `#111827`;
  - texto 15 px, `#374151`, altura de linha 1,5;
  - observações 13 px, `#6b7280`.
- **Código:** 28 px, negrito, espaçamento de 6 px entre os caracteres, fundo `#f3f4f6`, centralizado.
- **Botão:** fundo `#111827`, texto branco em negrito, cantos de 6 px. Abaixo dele, a linha "Se o botão não abrir,
  copie este endereço:" com a URL. É o subcopy do Laravel, traduzido.
- **Rodapé:** linha cinza-clara e o texto "Entregas RestaurantePro · e-mail automático, não responda.", 12 px,
  `#9ca3af`. Sem endereço e sem contato de suporte.
- **Versão em texto:** as views `text/*` seguem o mesmo conteúdo, sem HTML.
- **Fontes:** Arial/Helvetica (fontes do sistema); nada externo além do logo.

## Textos

Os nomes dos campos (`{nome}`, `{codigo}` etc.) são ilustrativos: cada entrada do catálogo lê as propriedades reais da
notificação ou as variáveis da view.

| # | Origem | Assunto | Título e corpo |
|---|---|---|---|
| 1 | `VerificationMail`, `type=email_verification` | `{codigo} é o seu código de verificação` | **Confirme seu e-mail.** "Olá, {nome}! Use o código abaixo para confirmar seu e-mail no Entregas RestaurantePro." [código] "O código vale por 1 hora. Se não foi você, ignore este e-mail." |
| 2 | `VerificationMail`, `type=2fa` | `{codigo} é o seu código de acesso` | **Seu código de acesso.** "Olá, {nome}! Para concluir a entrada na sua conta, digite este código:" [código] "Se você não tentou entrar agora, troque sua senha." |
| 3 | `VerificationMail`, `type=driver_login` | `{codigo} é o seu código para entrar no app` | **Entrar no app de entregas.** "Olá, {nome}! Digite este código no app para entrar:" [código] "Se não foi você, ignore este e-mail." |
| 3b | `VerificationMail`, `type=driver_password_reset` | `{codigo} é o seu código para redefinir a senha` | **Redefinir a senha do app.** "Olá, {nome}! Use este código no app para criar uma nova senha:" [código] "Se não foi você, ignore este e-mail." |
| 3c | `VerificationMail`, outro `type` | `{codigo} é o seu código` | **Seu código.** "Olá, {nome}! Use este código para continuar:" [código] "Se não foi você, ignore este e-mail." Registra `[entregas] e-mail sem tradução` com o `type`. |
| 4 | `UserForgotPassword` | `Redefina sua senha do Entregas RestaurantePro` | **Redefinir sua senha.** "Olá, {nome}! Recebemos um pedido para redefinir a senha da sua conta. Toque no botão para criar uma nova." Botão: **Criar nova senha**. "Se a página pedir, use o código: {codigo}". "Se não foi você, ignore este e-mail: sua senha continua a mesma." |
| 5 | `UserInvited` | `Você foi convidado para a equipe {empresa}` | **Você foi convidado!** "Olá, {nome}! {quem convidou} convidou você para a equipe {empresa} no Entregas RestaurantePro." Botão: **Aceitar convite**. "Código do convite: {codigo}". Sem "quem convidou": "Você foi convidado para a equipe…". |
| 6 | `UserEmailChange` | `Confirme seu novo e-mail` | **Confirme seu novo e-mail.** "Olá, {nome}! Foi pedida a troca do e-mail de login da sua conta." "E-mail atual: {antigo}" "Novo e-mail: {novo}" Botão: **Confirmar troca de e-mail**. "Se o botão não abrir, use o código: {codigo}". "Se não foi você, ignore este e-mail: o e-mail da conta não muda." Os dados vêm de `verificationCode->subject` (usuário) e `verificationCode->meta.old_email`/`new_email`; a URL vem de `$notification->url`. |
| 7 | `UserCredentialsMail` | `Seus dados de acesso ao Entregas RestaurantePro` | **Seus dados de acesso.** "Olá, {nome}! Seguem seus dados para entrar:" Tabela E-mail / Senha. Botão: **Entrar**, para o console (`Utils::consoleUrl()`). "Troque a senha no primeiro acesso, em Ver perfil." |
| 8 | `OrderDispatchFailed` | `Pedido {codigo do pedido} não foi despachado` | **O pedido não foi despachado.** "O pedido {codigo do pedido} da loja {loja} não pôde ser despachado." "Motivo: {motivo}" (ver tabela de motivos). Botão: **Acompanhar o pedido**, com a URL da original (`track-order` do console). Sem loja: "O pedido {codigo do pedido} não pôde ser despachado." Dados: `$notification->order` e `$notification->reason`; a loja é o `order->customer` (Vendor), se houver. |
| 9 | `TestMail` | `Teste de e-mail do Entregas RestaurantePro` | **O envio de e-mail está funcionando.** "Se você recebeu esta mensagem, a configuração de e-mail do Entregas RestaurantePro está certa." |

**Avisos opcionais.** Só são enviados se estiverem ligados em Admin → Notificações (`NotificationRegistry`). Seguem o
mesmo layout e o mesmo tom, sempre com botão para o pedido ou para o usuário no console:

| Origem | Assunto | Corpo |
|---|---|---|
| `OrderCompleted` | `Pedido {codigo do pedido} entregue` | "O pedido {codigo do pedido} foi entregue." |
| `OrderFailed` | `Pedido {codigo do pedido} com falha na entrega` | "A entrega do pedido {codigo do pedido} falhou." Mais o motivo, se houver. |
| `OrderCanceled`, quando não vai ao motoboy | `Pedido {codigo do pedido} cancelado` | "O pedido {codigo do pedido} foi cancelado." |
| `UserCreated` | `Novo usuário na equipe {empresa}` | "{nome} ({email}) entrou na equipe {empresa}." |
| `UserAcceptedCompanyInvite` | `{nome} aceitou o convite para a equipe {empresa}` | "{nome} agora faz parte da equipe {empresa}." |

**Motivos de falha de despacho.** O texto chega em inglês dos pacotes. Os conhecidos são traduzidos; qualquer outro
fica como "Motivo: não informado", e o texto original vai para o log.

| Original | pt-BR |
|---|---|
| `No driver assigned for order to dispatch to.` | nenhum motoboy atribuído ao pedido |
| `Order was dispatched, but driver was unable to be notified.` | o pedido foi despachado, mas o motoboy não pôde ser avisado |

O "código do pedido" é o número de rastreio (`trackingNumber.tracking_number`). Sem ele, usa o `public_id`.

## Arquitetura

Tudo o que é PHP novo fica em `api/app/Notifications/Entregas/Email/`, com os testes em `scripts/teste-php/`.

### 1. Layout (views em `api/resources/views/vendor/`)

O Laravel 10 põe `{view.paths}/vendor/{namespace}` à frente do caminho do pacote no `loadViewsFrom`, e a busca é feita
arquivo por arquivo. A `api/config/mail.php` já aponta `markdown.paths` para `views/vendor/mail`. Arquivos novos:

- **`vendor/mail/html/{layout,header,footer,button,message,subcopy}.blade.php` e `themes/default.css`**: o estilo C.
  - O `header` mostra o logo de `Setting::getBrandingLogoUrl()`. Se a classe não existir, cai em
    `config('fleetbase.branding.logo_url')`.
  - O `subcopy` traz o texto do link em pt-BR.
  - O `footer` traz o rodapé fixo.
  - Ponto de partida: as views do Laravel 10 do `vendor/` da imagem, conferidas com
    `cat vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/*.blade.php` na VPS. As cópias ficam iguais na
    estrutura e mudam só o visual e os textos.
- **`vendor/mail/text/*`**: equivalentes em texto.
- **`vendor/fleetbase/layout/mail.blade.php`**: o `<x-mail-layout>` dos Mailables passa a usar o mesmo
  `<x-mail::layout>`, com o mesmo cabeçalho e rodapé. Sai o logo forçado em 200×35 e o "All Rights Reserved".
- **`vendor/notifications/email.blade.php`**: a view das MailMessage. Usa os mesmos componentes e mostra:
  - `greeting` como título;
  - `introLines`, o botão (`actionText`/`actionUrl`) e `outroLines`;
  - sem o "Regards", que vira o rodapé.

  O bloco de código é uma linha marcada pelo catálogo, mostrada com o estilo do código.

### 2. Corpo dos Mailables (`api/resources/views/vendor/fleetbase/mail/`)

- **`verification.blade.php`**: monta título, texto e observação pelo `$type` (textos 1 a 3c). O `$code` aparece no bloco
  de código, e o `$user->name` vai na saudação (sem nome: "Olá!"). Ignora o `$content` em inglês que vem do PHP e o botão
  "Verify Email": a loja e o console digitam o código na tela.
- **`user-credentials.blade.php`**: texto 7, com `$user` e `$plaintextPassword`.
- **`test.blade.php`**: texto 9.

O `fleetops::mail.customer-credentials` não muda: o cadastro de lojas (`LojasController`) não envia e-mail. Se for
usado, sai em inglês no layout novo.

### 3. `CanalEmailEntregas`

`App\Notifications\Entregas\Email\CanalEmailEntregas extends Illuminate\Notifications\Channels\MailChannel`.

**Registro:** `$this->app->bind(MailChannel::class, CanalEmailEntregas::class)` no `AppServiceProvider`, no mesmo
padrão da troca do `FcmChannel` (`AppServiceProvider.php:47`). O `ChannelManager::createMailDriver()` resolve o canal
pelo container, então vale para as notificações do core, do Fleet-Ops e do portal.

**`send($notifiable, $notification)`:**

1. **Motoboy.** Não envia se:
   - o notifiable é `Fleetbase\FleetOps\Models\Driver`; ou
   - a notificação é `UserInvited` e o notifiable é um `User` com `type === 'driver'`.

   Nos dois casos registra `[entregas] e-mail ao motoboy não enviado`, com `aviso` (classe) e `motoboy` (public_id), em
   nível `info`.
2. **Mensagem original.** Pede ao pai a `MailMessage` original (`$notification->toMail($notifiable)`), dentro de
   `try`. Se a montagem falhar, deixa o pai enviar como antes.
3. **Catálogo.** Passa a mensagem pelo catálogo `EmailsEmPortugues::traduzir($notification, $notifiable, $mensagem)`.
   - Classe conhecida: devolve uma `MailMessage` nova em pt-BR (assunto, saudação, linhas, botão).
   - Classe desconhecida: devolve `null`. O canal registra `[entregas] e-mail sem tradução`, com `aviso` (classe), em
     nível `warning`, e envia a original, já no layout novo.
4. **Envio.** Envia a mensagem em pt-BR com o mesmo mecanismo do pai, sobrescrevendo só o ponto em que o pai obtém a
   mensagem. O método exato depende da assinatura do `MailChannel` do Laravel 10 da imagem (`send` / `buildMessage` /
   `messageBuilder`). O plano começa conferindo esse arquivo no `vendor/` da VPS.

### 4. Catálogo `EmailsEmPortugues`

Uma classe com uma entrada por notificação conhecida. Cada entrada recebe a notificação, o notifiable e a mensagem
original e devolve a `MailMessage` em pt-BR. Só lê propriedades públicas e as linhas/URL da original. Exemplos:

- **`UserForgotPassword`:**
  - Usa `$notification->verificationCode`, com `->code` e `->uuid`.
  - A URL vem da original, salvo para usuário de loja (`$notifiable->type === 'customer'`). Nesse caso usa
    `Utils::consoleUrl('customer-portal/auth/reset-password/' . $uuid, ['code' => $code])`.
- **`UserInvited`:** usa `$notification->invite->code`, `$notification->company->name`, `$notification->sender->name`
  e `$notification->url`.
- **`OrderDispatchFailed`:** usa `$notification->order` e `$notification->reason`, e traduz o motivo pela tabela de
  motivos.

Cada entrada fica protegida por `try`. Se uma propriedade não existir (o pacote mudou), devolve `null`, o e-mail sai
como veio e o log aponta a classe.

### 5. Assuntos dos Mailables: listener de `MessageSending`

`App\Listeners\Entregas\AssuntoDosEmailsEmPortugues`, registrado no `EventServiceProvider` para
`Illuminate\Mail\Events\MessageSending`.

**Identifica o Mailable** pelo `$event->data`:

- `VerificationMail`: tem `code` e `type`.
- `UserCredentialsMail`: tem `plaintextPassword` e `user`.
- `TestMail`: o assunto começa com "🎉 Your Fleetbase Mail Configuration Works".

**Ação:** troca o assunto pelos das linhas 1 a 3c, 7 e 9. Não mexe nas notificações, que já saem com assunto em
pt-BR pelo canal; elas trazem `__laravel_notification` no `data`, e o listener as ignora. Sem identificação, não muda
nada.

### 6. Portal da loja: rota de reset com id

Em `packages/customer-portal/addon/routes.js`, `this.route('reset-password')` passa a ser
`this.route('reset-password', { path: '/reset-password/:id' })`. A rota já lê `model({ id })`
(`routes/portal-auth/reset-password.js:11`) e o controller já usa `queryParams = ['code']`. Sem o `:id`, a validação
recebia `id` vazio.

Confira também o "Voltar ao login" e os links internos que apontam para `portal-auth.reset-password`, que passam a
exigir o id.

## Fluxo

| Tipo | Por onde passa |
|---|---|
| Notificação (`notify`) | fila (`ShouldQueue`) → `ChannelManager` → `CanalEmailEntregas` → catálogo → `MailMessage` em pt-BR → view `notifications::email` (sobrescrita) → componentes `mail::*` (sobrescritos) → SMTP |
| Mailable (`Mail::to()->send()`) | síncrono → view `fleetbase::mail.*` (sobrescrita) dentro do `<x-mail-layout>` (sobrescrito) → `MessageSending` → listener troca o assunto → SMTP |

## Erros e riscos

- **Falha numa tradução:** nunca derruba o envio. O canal e o listener ficam em `try`, enviam o original e
  registram o erro com o prefixo `[entregas]`.
- **Fila:** as notificações são renderizadas e enviadas pelo serviço `entregas_queue`.
  - O deploy da API já reinicia esse serviço: `atualizar.sh api` faz `service update`, e o `deploy.sh` faz
    `queue:restart`.
  - O log do canal aparece em `docker service logs entregas_queue | grep '\[entregas\]'`.
- **Atualização dos pacotes.** O docblock do `CanalEmailEntregas` lista o que conferir antes de subir o `composer.lock`:
  - os nomes das views (`fleetbase::layout.mail`, `fleetbase::mail.verification`, `fleetbase::mail.user-credentials`,
    `fleetbase::mail.test`) e o alias `mail-layout`;
  - as variáveis passadas a elas (`code`, `type`, `user`, `plaintextPassword`, `content`, `currentHour`);
  - as classes e as propriedades públicas das notificações do catálogo;
  - a assinatura do `MailChannel` do Laravel.
- **Sem locale global.** O `app.locale` continua `en`: os textos em pt-BR vêm do catálogo e das views, sem mudar o
  idioma de `Carbon`, das validações etc. A única string por `__()` que sobra, 'All Rights Reserved.', some com o
  layout novo.
- **Botão "Verify Email" removido.** Ele apontava para o onboard do console, inclusive para a loja.

## Testes

Usam o php-wasm (PHP 8.2), em `scripts/teste-php/emails.php`, com o padrão do `rodar.mjs`: os arquivos reais do core-api
e do Fleet-Ops a partir de `packages/`, mais stubs do resto. Conferem:

1. **Catálogo:** para cada notificação conhecida, uma instância com dados de exemplo gera o assunto, a saudação, as
   linhas, o texto do botão e a URL esperados (os da tabela de textos).
2. **Link de reset:** usuário `type=customer` recebe o link do portal; os outros, o do console.
3. **Motoboy:** `Driver` e `UserInvited` para `type=driver` não são enviados, e a linha de log aparece.
4. **Desconhecido:** uma notificação inventada sai sem mudança, com a linha `[entregas] e-mail sem tradução`.
5. **Listener:** o assunto da `VerificationMail` de cada `type`, o da `UserCredentialsMail` e o da `TestMail` saem
   trocados; uma notificação passa sem mudança.
6. **Views:** onde o php-wasm permitir, renderiza as views sobrescritas com dados de exemplo e confere que o HTML contém
   título, código, botão e rodapé em pt-BR e não contém "Fleetbase" nem textos em inglês da lista. Se o Blade completo
   não rodar no php-wasm, fica pelo menos o `php -l` das views compiladas e a conferência manual pelo envio real.

Mais as verificações de rotina: `sintaxe.mjs` em todos os PHP novos e o `i18n-check` no portal (a rota não tem texto
novo).

**Depois do deploy (feito pelo Edgard):**

- Admin → Configurações → E-mail → Testar (texto 9).
- "Esqueci a senha" pelo console e pelo portal da loja de testes (Terraço Pizza Bar), conferindo se o link abre o portal.
- Convite de um usuário de teste pelo IAM.
- Cadastro de um motoboy de teste, conferindo que nenhum e-mail chega e que o log da fila mostra o corte.
