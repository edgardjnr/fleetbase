# E-mails (pt-BR, layout do RestaurantePro)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

- Desenho: `docs/superpowers/specs/2026-10-04-emails-em-portugues-design.md`.
  - Os pacotes trazem os textos fixos em inglês.
  - Tudo é sobrescrito em `api/`, sem editar os pacotes.
- **Layout (estilo C):**
  - `api/resources/views/vendor/mail/html/{message,codigo}.blade.php`, mais `themes/default.css` e `text/*`.
  - Os Mailables da Fleetbase usam o mesmo layout (`vendor/fleetbase/layout/mail.blade.php`).
  - Logo: `MarcaDoEmail` (Admin → Marca ou `api/config/fleetbase.php`).
- **Notificações:**
  - `CanalEmailEntregas` (bind do `MailChannel` no `AppServiceProvider`) monta assunto e texto pelo catálogo `EmailsEmPortugues`.
  - **Não manda e-mail ao motoboy:** nem para o Driver, nem o convite para usuário `type=driver`.
  - Aviso sem tradução sai em inglês no layout novo e aparece no log como `[entregas] e-mail sem tradução`. Notificação na fila registra no serviço da **fila** (`docker service logs entregas_queue | grep '[entregas]'`); as que não vão para a fila, no `entregas_application`.
  - Notificação nossa (namespace `App\Notifications\Entregas\`) já vem em pt-BR: o `CanalEmailEntregas::doEntregas` deixa passar como veio, sem o aviso "sem tradução".
- **E-mails das faturas** (Financeiro; regras de envio em `api/app/Support/Entregas/Financeiro/CLAUDE.md`, "E-mails para a loja e avisos"):
  - `FaturaEmitida`, `LembreteDeFatura` e `FaturaVencida` (base `AvisoDeFatura`), com `toMail()` já em pt-BR (`MailMessage`, `Saudacao::para`). Assuntos "Fatura FAT-0031 emitida", "Fatura FAT-0031 vence amanhã" e "Fatura FAT-0031 está vencida".
  - Quando: emitida na emissão e no "Reenviar e-mail" do console; lembrete 1 dia antes do vencimento e vencida uma vez depois do vencimento, só com valor em aberto (`entregas:financeiro-avisos`, 09:00). Só com `ENTREGAS_FINANCEIRO=1` e SMTP configurado.
  - **Destinatários** (`AvisosDaFatura::emailsDaLoja`): e-mail do Vendor + e-mails dos usuários ativos do portal da loja (contatos `customer` com `vendor_personnels.status = active`), sem repetir, num envio só (`Notification::route('mail', [...])`). Loja sem e-mail: nada enviado e o painel do Financeiro mostra "não enviada por e-mail".
  - Botão **"Ver a fatura"** para `<console>/customer-portal/financeiro/<uuid>` (`AvisosDaFatura::urlNoPortal`, `Utils::consoleUrl`; a rota `portal` do engine tem path `/`, então não há `/portal/` na URL).
  - Linhas simples ("Valor: R$ 620,00", "Vencimento: 08/10/2026", "Em aberto: …"), sem `<x-mail::dados>`: a linha da `MailMessage` é texto escapado. Nomes passam pelo `TextoDoEmail::semLink`.
  - Envio na hora (não são `ShouldQueue`): o log fica no `entregas_application` (ou no `entregas_scheduler`, pela rotina). Testes: `scripts/teste-php/financeiro-portal-emails.php` e `emails.php` (os três pelo canal).
- **Mailables (código, credenciais, teste):**
  - Corpo em `vendor/fleetbase/mail/*.blade.php`; os textos do código ficam em `CodigosPorEmail`.
  - Assunto pelo `AssuntoDosEmailsEmPortugues` (`MessageSending`, nunca devolver `false`: cancela o envio).
- **Nomes e e-mails no texto passam pelo `TextoDoEmail::semLink`.** É o `delinkify` do core, que quebra o autolink, desfeito do escape HTML. Sem isso, a view escapava de novo e saíam `&amp;` e `&#8203;`.
- **"Esqueci a senha" da loja** (usuário `customer`) leva ao portal: `customer-portal/auth/reset-password/<uuid>?code=`.
  - A rota do portal ganhou o `:id`.
- Testes:
  - `scripts/teste-php/emails.php` (php-wasm, com a `MailMessage` real do Laravel 10 em `scripts/teste-php/laravel10/`);
  - `node scripts/teste-emails-views.mjs`.
- **Ao atualizar o Laravel, o core-api ou o fleetops-api:** confira a lista no docblock do `CanalEmailEntregas`:
  - o `send()` do `MailChannel`;
  - os nomes e as variáveis das views sobrescritas;
  - as classes e as propriedades do catálogo.
