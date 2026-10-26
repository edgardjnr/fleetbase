# Entregas RestaurantePro (fork do Fleetbase)

Fork de `fleetbase/fleetbase` em `edgardjnr/fleetbase` (branch `main`), customizado como o produto
**Entregas** do RestaurantePro. Toda a interface fica em **pt-BR**, roda em produção numa VPS ARM64 e
o código das extensões está incorporado ao repositório.

Responda e documente em português do Brasil.

## Onde está cada assunto

Este arquivo traz só o que vale em qualquer parte do repo. Os detalhes ficam num `CLAUDE.md` por pasta, que o Claude Code carrega sozinho quando lê um arquivo daquela pasta. **Antes de mexer num assunto, leia o arquivo dele**, mesmo começando por outra pasta (ex.: uma tela do console que mostra dado do iFood, ou o app do motoboy, que fica em outro repo).

| Assunto | Arquivo |
|---|---|
| Decisões e armadilhas de produção (healthcheck, console em produção, HTTPS, banco, organização, e-mail) | `deploy/CLAUDE.md` |
| Imagens ARM64 e origem no socket | `docker/CLAUDE.md` |
| API: fuso de Brasília, sem chave do Google no `track()`, armadilhas do Fleetbase | `api/CLAUDE.md` |
| Busca de endereço (Google Places) | `api/app/Support/Entregas/Enderecos/CLAUDE.md` |
| Integração iFood (etapas 2 a 4, homologação) | `api/app/Support/Entregas/Ifood/CLAUDE.md` |
| Distribuição de pedidos abertos (ofertas e rodadas) | `api/app/Support/Entregas/Distribuicao/CLAUDE.md` |
| Integração de pedidos de outros sistemas (RestaurantePro) | `api/app/Support/Entregas/Integracao/CLAUDE.md` |
| App do motoboy (APK, push, alarme, ganhos, líder, trava "Atualize o app", foto) | `api/app/Http/Controllers/Entregas/CLAUDE.md` |
| E-mails em pt-BR | `api/app/Notifications/Entregas/Email/CLAUDE.md` |
| Financeiro: extrato e pagamento dos motoboys, faturas das lojas, painel | `api/app/Support/Entregas/Financeiro/CLAUDE.md` |
| Saque Pix do motoboy pelo app (Asaas da central, chave cifrada, validação de saque) | `api/app/Support/Entregas/Saques/CLAUDE.md` |
| Corridas do sistema: relatório do dono (todas as organizações) e preço por central | `api/app/Support/Entregas/Sistema/CLAUDE.md` |
| Painel inicial do console: números do período, Ao vivo, Atribuir e a comanda do pedido (folha do console) | `api/app/Support/Entregas/Painel/CLAUDE.md` |
| Fleet-Ops: telas ocultas, pagamento e cobrança, mapa ao vivo, tela de pedidos (`/fleet-ops`) | `packages/fleetops/CLAUDE.md` |
| Portal da loja (segurança, mapa de motoboys, chat, riscos conhecidos) | `packages/customer-portal/CLAUDE.md` |
| Tradução pt-BR (convenções) | `packages/CLAUDE.md` |
| Build do console, marca e landing | `console/CLAUDE.md` |
| Histórico do que foi entregue | `docs/historico.md` |

Mudou uma regra? Atualize o arquivo do assunto, não este. Assunto novo ganha um `CLAUDE.md` na pasta do código dele e uma linha nesta tabela.

## Produção

| | |
|---|---|
| Console | https://entregas.restaurantepro.com.br → `entregas_console:4200` |
| API | https://entregas-api.restaurantepro.com.br → `entregas_application:8000` |
| Socket | https://entregas-socket.restaurantepro.com.br → `entregas_socket:8000` |

- **VPS:** Oracle **ARM64** (aarch64), Docker Swarm + Portainer. É a mesma VPS do RestoFit.
- **Entrada:** Cloudflare Tunnel `restaurantepro-oracle`, na rede overlay `network_public`, com 3 rotas publicadas (Zero Trust → Conectores). Não há portas abertas.
- **Stack:** `entregas` no Portainer. O arquivo é `deploy/docker-stack.yml`; as variáveis ficam em `deploy/stack.env`, que é ignorado pelo git e existe só no PC do Edgard.
- **Imagens:** `entregas-api`, `entregas-console` e `entregas-socket`, todas buildadas **na VPS**. O stack não builda.
- **Clone na VPS:** `~/entregas`. O Claude não tem SSH; quem roda os comandos é o usuário.

### Deploy / atualização

```bash
cd ~/entregas && bash deploy/atualizar.sh            # pull + build de tudo + service update + deploy.sh
bash deploy/atualizar.sh console                      # só o console (~15 min, usa packages/*)
bash deploy/atualizar.sh api                          # API + queue + scheduler, depois roda o deploy.sh (migrations)
SEM_PULL=1 bash deploy/atualizar.sh socket
```

Ao atualizar o stack pelo Portainer, deixe "Re-pull image" **desligado**, porque as imagens são locais.
Mudou alguma variável do stack? Use Stacks → entregas → Editor → Update the stack.
Depois de cada deploy do console, abra o site com Ctrl+Shift+R. O console guarda o `fleetbase.config.json` no localStorage por 1 h.

## Estrutura

- `api/`: Laravel. Em produção, a API usa os pacotes **publicados** no Composer (fleetbase/core-api, fleetops-api…).
- `console/`: Ember. É buildado com os pacotes **locais** de `packages/*` (pnpm workspace via `scripts/package-linker.mjs`, só frontend).
- `packages/*`: extensões e bibliotecas (fleetops, iam-engine, dev-engine, customer-portal, ember-ui, ember-core, fleetops-data e core-api, que é só cópia de referência para os testes).
  - Eram submódulos e foram **incorporados ao repo**: o `.gitmodules` foi removido. As cópias originais seguem em `.git/modules/packages/*` e servem de base para diff contra o upstream.
  - storefront, ledger, registry-bridge, ai, pallet e fleetbase-extensions-indexer saíram do repo em 2026-10-25 (estão no histórico do git e em `.git/modules/packages/*`).
- Não há `.github` (o CI e os workflows do upstream saíram em 2026-10-25): os testes rodam à mão e o deploy é o `atualizar.sh`.
  - **Mudanças em `packages/*/server` (PHP) não chegam à produção.** Texto que vem da API é traduzido no frontend.
  - Código PHP próprio vai em `api/app/` (entra na imagem da API). Exemplo: `Http/Controllers/Entregas/`, com as rotas na `Providers/RouteServiceProvider.php`.
- `deploy/`: stack, modelo de env, `atualizar.sh` e README do deploy.
- `scripts/teste-php/`: testes de comportamento do PHP do `api/app` sem PHP instalado (php-wasm, PHP 8.2), com os arquivos reais do Fleet-Ops e do core-api (cópias em `packages/`) e stubs do resto. Uso no cabeçalho do `rodar.mjs`. O `sintaxe.mjs` confere a sintaxe com o `php -l` do PHP 8.2.
- `docker/`: Dockerfile da API, `docker/socket/` (socket ARM) e crontab.

## Escopo enxuto: delivery iFood → motoboy

O produto é só isto: o pedido chega do iFood (integração Logistics dentro da `api/app`, ver "Integração iFood" em `api/app/Support/Entregas/Ifood/CLAUDE.md`), é despachado para o motoboy, que usa o app **Navigator**, e o motoboy é pago por km.

- **Extensões fora do console:** storefront, ledger, registry-bridge (Extensions), ai, valhalla e vroom.
  - Saíram do `console/package.json`, do `pnpm-workspace.yaml` e do `Dockerfile.dockerignore`.
  - Os `app/router.js` e `app/extensions/*` são gerados no build a partir do `node_modules`.
  - No servidor, essas APIs e o laravel/cashier seguem no `composer.lock`, mas **fora do package:discover** (`extra.laravel.dont-discover` do `api/composer.json`, 2026-10-25): sem rotas, agendados, listeners nem migrations deles. Detalhes em "Pacotes fora do package:discover" de `api/CLAUDE.md`. Para reativar, tire o pacote do dont-discover (e volte o frontend às listas acima, se tiver tela).
- **Ficam:** Fleet-Ops, IAM (sem Grupos e sem a aba Clientes), **Developers** (chaves de API e Logs; a chave `flb_live_` do APK sai dali; Webhooks, Sockets e Eventos só dentro de Developers, fora dos atalhos do topo) e o **Portal do Cliente**, que voltou como portal da loja (ver "Portal da loja" em `packages/customer-portal/CLAUDE.md`).
- **Criar ou entrar em organização:** só o admin do sistema (console e API; ver "Organização" em `deploy/CLAUDE.md`).

## Regras que valem em qualquer lugar

- **Fuso:** o servidor roda no horário de Brasília. Nunca formate data em UTC para gravar nem para consultar (ver "Fuso (horário de Brasília)" em `api/CLAUDE.md`).
- **iFood:** não vincule loja real antes do APK da etapa 4 em todos os celulares e da trava "Atualize o app" ligada (ver `api/app/Support/Entregas/Ifood/CLAUDE.md`).
- **Ao atualizar o Laravel, o core-api ou o fleetops-api:** quase todo arquivo de assunto tem uma lista "Ao atualizar …, confira". Percorra todas.
- **Tradução:** antes de commitar, `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` precisa sair com exit 0 (detalhes em `packages/CLAUDE.md`).
- **Testes:** `scripts/teste-php/` (php-wasm, uso no cabeçalho do `rodar.mjs`) e `scripts/teste-portal/` (`node --import ./scripts/teste-portal/resolver.mjs --test ...`). Cada arquivo de assunto lista os seus.

## Git

- Confirme `git rev-parse --show-toplevel` antes de commitar. A home `C:\Users\Edgardjr` também é um repo git (acidental).
- Push em `main` **não** faz deploy automático; o deploy é o `atualizar.sh` na VPS.
- Nunca commitar `deploy/stack.env`, `api/.env` nem `console/dist`.
