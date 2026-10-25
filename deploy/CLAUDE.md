# Deploy e produção

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Endereços, VPS, stack e comandos de deploy: `CLAUDE.md` da raiz ("Produção"). Imagens ARM64 e socket: `docker/CLAUDE.md`. Roteiro da troca de fuso no banco: `deploy/fuso/LEIAME.md`.

## Decisões e armadilhas de produção (não desfazer)

- **Healthcheck.** A base `dunglas/frankenphp` testa `:2019/metrics`, que não existe no scheduler, e o Swarm matava o task a cada ~90 s. No stack: `scheduler` com `healthcheck.disable`, e `application` testando `curl localhost:8000/`.
- **Console em produção.**
  - O `config/environment.js` desliga o runtime config quando `ENVIRONMENT=production`. O `console/Dockerfile` força `DISABLE_RUNTIME_CONFIG=false`.
  - O `console/nginx-entrypoint/40-fleetbase-runtime-config.sh` gera o `fleetbase.config.json` a partir das env vars do serviço `console`.
- **HTTPS atrás do tunnel.** O `api/app/Http/Middleware/TrustProxies.php` lê `TRUSTED_PROXIES` via `getenv` (sobrevive ao `config:cache`).
- **Logs da API (`application`) no `docker service logs`** (2026-10-19). O `octane:frankenphp` descarta o stdout do servidor e só repassa o stderr, filtrado: linha JSON sem o campo `msg` (as do Monolog) some. Por isso, até essa data, nenhum log do Laravel da `application` aparecia (`vínculo falhou`, `loja vinculada`, `líder trocou o motoboy`...). No stack, só a `application` tem `LOG_CHANNEL: stderr` e o `command` com `--log-level=WARN`. Com essa opção, o Octane repassa o stderr cru; WARN é o nível do Caddy que já valia em produção. A fila e o scheduler (CLI) continuam com `LOG_CHANNEL=stdout`, que funciona.
  - **Não tire o `--log-level` nem volte a `application` para `stdout`**: os logs somem de novo, sem erro nenhum.
  - **Ao atualizar o `laravel/octane`** (hoje 2.19.1), confira o `StartFrankenPhpCommand::writeServerOutput` e o `CMD` do estágio `app-release` do `docker/Dockerfile`, que o `command` do stack repete.
- **Outros ajustes da API.**
  - `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=false` (MySQL interno).
  - Sem xdebug, memcached e ssm-parent na imagem (2026-10-25; o `XDEBUG_MODE` saiu do stack).
  - `TELEMETRY_DISABLED=true` no stack (2026-10-25): sem o `telemetry:ping` diário, que mandava ao Fleetbase nome da empresa, URLs e contagens de usuários e pedidos. O core lê com `getenv` (vale com `config:cache`).
  - O link `public/storage` já vem criado na imagem.
- **Deploy sem o `registry:init`** (2026-10-25): o registry-bridge está fora do package:discover. O `mysql:createdb` (1ª linha do `deploy.sh`, com `set -e`) continua pedindo a conexão `storefront`, que vem do `App\Support\Entregas\ConexaoDoStorefront`; sem ela, o deploy pararia ali. O banco `fleetbase_storefront` e as tabelas `ledger_*`, `registry_*`, `ai_*` e do cashier ficam no banco, sem uso.
- **Cache e fila em bancos separados do Redis** (2026-10-25): o cache fica no banco 1 (`REDIS_CACHE_DATABASE`, padrão `1`, conexão `cache` do `api/config/database.php`) e a fila no 0 (`default`). Antes os dois caíam no 0 (o `cache_cache` do Fleetbase não é número), e o `cache:clear` do `deploy.sh` (FLUSHDB do banco do cache) apagava os jobs da distribuição. Conferido em produção: uma chave no banco 0 sobrevive ao `cache:clear`. As travas (`Cache::lock`) seguem na `default`. **Nunca rode `FLUSHDB`/`FLUSHALL` no banco 0** nem `redis-cli flushall`. Teste: `scripts/teste-php/cache-separado-da-fila.php`.
- **Banco.** Na primeira instalação, ou quando houver migrations novas, rode `bash deploy/atualizar.sh api`, que executa o `deploy.sh` dentro do container.
- **Organização.**
  - Moeda **BRL**, fuso **America/Sao_Paulo**.
  - A moeda foi gravada via API, porque o `CurrencySelect` só dispara a mudança quando o valor muda. Se a tela mostrar BRL só como sugestão, nada é salvo.
  - **Criar ou entrar em organização só o admin do sistema** (2026-10-25): o `BarrarOrganizacaoParaNaoAdmin` (fim do grupo `fleetbase.protected`) dá 403 em `int/v1/auth/create-organization` e `join-organization` a quem não é `type admin`; o console esconde o menu e o botão de Conta → Organizações. Instalação (`onboard/create-account`) e convite (`users/accept-company-invite`) não passam por ele. Teste: `scripts/teste-php/barrar-organizacao.php`.
  - **Motoboy não entra no console** (2026-10-23), nem o líder: o `BarrarMotoboyNoConsole` da API dá 403 no login. Para liberar o console a quem também tem cadastro de motoboy, dê a ele o papel Administrator na organização (ver "Segurança" em `packages/customer-portal/CLAUDE.md`).
- **E-mail:** `MAIL_MAILER=log` até alguém configurar SMTP no `stack.env`.
