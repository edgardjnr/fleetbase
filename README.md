# Entregas RestaurantePro

Sistema de entregas do RestaurantePro para operadoras de frota de motoboys que atendem restaurantes. O pedido chega do
iFood (integração Logistics) ou do sistema da loja, é distribuído aos motoboys, que usam o app **Navigator**, e o
motoboy é pago por km. A central opera pelo console; a loja acompanha os pedidos pelo portal.

É um fork do [Fleetbase](https://github.com/fleetbase/fleetbase), com a interface toda em português do Brasil.

## Produção

| | Endereço | Serviço |
|---|---|---|
| Console | https://entregas.restaurantepro.com.br | `entregas_console:4200` |
| API | https://entregas-api.restaurantepro.com.br | `entregas_application:8000` |
| Socket | https://entregas-socket.restaurantepro.com.br | `entregas_socket:8000` |

Roda numa VPS ARM64 com Docker Swarm (stack `entregas` no Portainer), atrás de um Cloudflare Tunnel.

## Estrutura

- `api/`: Laravel. O código próprio fica em `api/app/` (controllers, middlewares, comandos e o domínio em
  `app/Support/Entregas/`); os pacotes do Fleetbase vêm do Composer.
- `console/`: console em Ember, buildado com os pacotes locais de `packages/`.
- `packages/`: extensões do console (Fleet-Ops, IAM, Developers, portal da loja) e bibliotecas (`ember-core`,
  `ember-ui`, `fleetops-data`); o `core-api` e os `server/` do Fleet-Ops e do portal são cópias de referência do PHP
  publicado (não vão para a produção).
- `deploy/`: stack do Swarm (`docker-stack.yml`), modelo das variáveis (`stack.env.example`) e o `atualizar.sh`.
- `docker/`: Dockerfile da API, socket (SocketCluster multi-arch) e crontab.
- `scripts/`: verificação das traduções e os testes (`teste-php/`, no PHP 8.2 via php-wasm, e `teste-portal/`).
- `docs/`: especificações e planos.

## Documentação

Cada assunto tem um `CLAUDE.md` na pasta do código dele. O da raiz traz o índice ("Onde está cada assunto") e as
regras que valem para o repositório inteiro.

## Deploy

As imagens são buildadas na própria VPS, a partir do clone em `~/entregas`:

```bash
cd ~/entregas && bash deploy/atualizar.sh        # pull, build de tudo, atualiza os serviços e roda o deploy.sh
bash deploy/atualizar.sh api                     # só a API (application, queue, queue-ifood e scheduler) + migrations
bash deploy/atualizar.sh console                 # só o console
```

Detalhes e armadilhas: `deploy/README.md` e o `CLAUDE.md` da raiz ("Produção").

## Licença

AGPL-3.0, a mesma do Fleetbase (`LICENSE.md`).
