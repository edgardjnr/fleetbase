# Portal da Loja — continuação (o que falta)

Retomar por aqui numa sessão nova. Documentos de referência:
- Plano completo (atualizado com todas as revisões): `docs/superpowers/plans/2026-10-02-portal-da-loja.md`
- Spec: `docs/superpowers/specs/2026-10-02-portal-da-loja-design.md`

## Como retomar

1. Ramo **`portal-da-loja`** (criado a partir de `main` em `d5669ccd`). **Nada foi enviado por push e nada foi para a `main`.** Confirme com `git branch --show-current` e `git log --oneline main..portal-da-loja`.
2. Execução por subagentes (skill `superpowers:subagent-driven-development`): para cada task, primeiro um implementador, depois a revisão de conformidade, depois a de qualidade, e as correções até a aprovação. Os relatórios dos subagentes devem ser curtos.
3. Não há PHP local. Para o lint de sintaxe, instale o parser no scratchpad da sessão nova:
   ```
   npm --prefix "<scratchpad>/phplint" i php-parser@3
   PHP_PARSER_DIR="<scratchpad>/phplint" node scripts/php-lint.cjs <arquivos.php>
   ```
4. No front, valide com `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` (precisa sair com exit 0) e com o parse do `@babel/parser` (ver CLAUDE.md).
5. Faça commits com `git commit -- <arquivos>` e confira antes o `git rev-parse --show-toplevel`. A home `C:\Users\Edgardjr` também é um repo git, por acidente.

## Situação por task

| Task | Situação | Commits |
|---|---|---|
| 0 Lint PHP | fechada | dd6a7f6f, e2c47b67 |
| 1 Portal reativado no console | fechada | 0bd76797 |
| 2 LojaDoUsuario | fechada (alterada na 6A) | 74f19f53 |
| 3 CalculoEntregas | fechada | 4188b0fd, 0f018eeb, 9bc56b61, 71bd4850, 702c2833, abab42ce |
| 4 LojasController (API admin) | fechada | ad13fff6, 50087522, 29aa36a7 |
| 5 PortalLojaController | fechada | 37535d6c, f3924d2d, 1bf0d2d2 |
| 6A ProtegerPortalLoja | aprovada (falta 1 Minor opcional) | 7b5f8e3d, 87c6b8f6, 40412c7a |
| 6B RegrasPortalLoja | **falta corrigir 1 Important** | f74e8f6e |
| 6C RestringirChaveDoApp | implementada, **falta revisão** | 16860aef |
| 7 Tela Lojas (Fleet-Ops) | aprovada (Minors opcionais) | f7b24744 |
| 8 Formulário do operador (coleta travada) | **a fazer** | — |
| 9 Portal: menu, telas ocultas, membros e login só leitura | implementada, **falta revisão** | 76e0f4ef, d037f5c5 |
| 10 Portal: novo pedido só com destino | **a fazer** | — |
| 11 Portal: acompanhamento | **a fazer** | — |
| 12 Portal: extrato | **a fazer** | — |
| 13 Build local do console | **a fazer** | — |
| 14 Teste de isolamento + deploy (com o usuário) | **a fazer** | — |
| 15 CLAUDE.md + memória | **a fazer** | — |

## Pendências das revisões

### 6B — corrigir antes do deploy (Important)
**Cancelamento × aceite do motoboy.**
- O `cancelOrder` do portal só faz `update(status=canceled)`. Não grava atividade, não dispara `OrderCanceled` e mantém `dispatched`/`adhoc` ligados.
- O `POST v1/orders/{id}/start` da API v1 (`fleetops Api/v1/OrderController.php` ~965-1021) não confere se o pedido foi cancelado. Um motoboy que recebeu o aviso ainda aceita o pedido cancelado, e o pedido "ressuscita": se for concluído, entra no pagamento e na cobrança.
- Também há corrida entre a nossa checagem e o update.

Correção sugerida (em `api/app`):
- Cancelar de forma atômica no `RegrasPortalLoja`: `Order::whereKey(..)->where('started', 0)->whereIn('status', ['created','dispatched'])->update(...)`, conferindo as linhas afetadas.
- Registrar a atividade e o evento (`$pedido->cancel()`) e desligar `dispatched`/`adhoc`.
- Num middleware global, barrar `POST v1/orders/{id}/start` de pedido cancelado (o código do Composer não pode ser editado).

Minors da 6B (avaliar):
- Incluir `order_canceled` em `STATUS_ENCERRADOS`.
- Considerar descartar `order_config`/`type`, `pod_required`/`pod_method` e `internal_id` no `POST orders`.
- A resposta do `POST orders` sai antes do despacho; o front recarrega.
- Marcar falha de despacho no `meta` do pedido (ex.: `entregas.despacho_falhou`).
- Corrida teórica no `POST places` (dá para resolver com `Cache::lock`).

### 6A — Minor opcional
O `loginDesativado` usa `exists()` sobre qualquer usuário de cliente com o mesmo e-mail ou telefone. Se houver um usuário de cliente pendente com o mesmo e-mail, uma loja ativa toma 401 no login. A correção é voltar ao `first()` sem filtro (a mesma consulta do login do portal) e depois conferir `type === 'customer' && status !== 'active'`, tratando status nulo como desativado.

### 6C — revisar e decidir quando ligar
- **Variável:** `ENTREGAS_CHAVE_APP_MOTOBOY`. Vazia é o padrão e mantém o comportamento de hoje.
- **O que ela faz quando ligada:** a chave pública do APK só passa em quatro rotas, e o resto devolve 403:
  - `POST v1/drivers/login-with-sms`
  - `POST v1/drivers/verify-code` (sem `for` ou com `for=driver_login`)
  - `POST v1/drivers/{id}/register-device`
  - `GET v1/drivers/{id}/organizations`
- **Comparação da chave:** a coluna `api_credentials.key` é `utf8mb4_unicode_ci`, então o middleware compara exato e confere de novo no banco.
- **Para ligar, depois do deploy e com teste no celular:**
  1. Confirme que o iFood usa outra chave. Se for a mesma do APK, crie uma chave nova para o iFood antes.
  2. `bash deploy/atualizar.sh api`.
  3. Preencha `ENTREGAS_CHAVE_APP_MOTOBOY=flb_live_…` no `stack.env`, igual ao `FLEETBASE_KEY` do app. No Portainer, faça Update the stack com Re-pull desligado; se precisar, rode `docker service update --force entregas_application`.
  4. `curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer <chave>" https://entregas-api.restaurantepro.com.br/v1/orders` tem de dar 403.
  5. No celular: saia, entre de novo por SMS, receba um pedido de teste, aceite e conclua.
  6. `docker service logs entregas_application 2>&1 | grep "chave do app"`.
  7. Para desligar, apague o valor e faça Update the stack.
- **Recomendação para o app** (repo `entregas-navigator`): no `createDriverSession`, criar o Driver com o token do motoboy. Assim `register-device` e `organizations` saem da lista.

### 7 — Minors opcionais (uma linha cada)
1. `salvarLoja` só fecha o painel se ele ainda for o da mesma loja.
2. `limpar()` faz `this.carregar.cancelAll()`.
3. O painel "Trocar senha" ganha `did-update` pelo usuário.
4. Lat/lng sem `inputmode="decimal"`: o teclado decimal não tem "-".

### 9 — revisar
Peça a revisão de conformidade e de qualidade dos commits 76e0f4ef e d037f5c5. Decisões extras já aceitas:
- os atalhos da home levam a Novo pedido, Pedidos e Extrato, e o texto da home mudou;
- a data de nascimento saiu do perfil;
- o perfil mostra e-mail e telefone só leitura;
- a aba Membros fica só leitura;
- o painel da home ficou em w6/w6/w12.

## Notas para as tasks que faltam

- **Task 8:** seguir o plano. Mexe em `packages/fleetops` (formulário do pedido + YAML). A tela Lojas (Task 7) já está fechada, então não há conflito.
- **Task 10:**
  - O `POST customer-portal/int/v1/places` **precisa sempre mandar `name`**, mesmo nulo. Sem ele, o `PlaceController` dá 500.
  - Sem edição de endereço salvo: o servidor devolve 403 para `PATCH`/`DELETE`.
  - Coordenadas obrigatórias, pelo mapa.
  - `mapCenter` = coleta da loja.
  - Mostrar ao usuário o 422 de endereço repetido.
  - O `minha-loja` sem loja dá 404.
- **Task 11:**
  - Remover a ação de suporte em `addon/components/portal/order/details.js`.
  - `ENCERRADOS` com `expired`.
  - O polling tem de tolerar 429 (`throttle:60,1` nas rotas `loja/*`).
  - A posição do motoboy só vem com o pedido aceito há no máximo 4 h; fora disso, lat/lng vêm nulos e o mapa não aparece.
- **Task 12:** o extrato devolve 422 "Escolha um período de até 1 ano." para período com mais de 366 dias.
- **Task 13:** `cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production`. Depois, conferir se o build mudou `console/app/router.js` (o portal precisa estar lá) e commitar se mudou.
- **Task 14:**
  - Itens a acrescentar ao teste (`scripts/teste-isolamento-lojas.mjs`, ver o plano):
    - cancelar e depois tentar aceitar no Navigator: o pedido não pode ressuscitar depois da correção da 6B;
    - `POST v1/customers/orders` com `Customer-Token` de loja → 403;
    - `service-quotes/preliminary` → 403;
    - upload de foto: só PNG/JPG até 5 MB;
    - login de usuário desativado → 401;
    - usuário em duas lojas → 403.
  - Testar o portal em **janela anônima**: o console guarda a lista de extensões no `localStorage` por 1 h.
  - O papel "Fleet-Ops Customer" precisa existir. O `deploy.sh` roda `fleetbase:create-permissions`.
  - O teste dispara um aviso real aos motoboys perto da loja de teste.
  - Merge na `main` e push **só com o aval do usuário**. O deploy é `cd ~/entregas && bash deploy/atualizar.sh` (API + console) e depois `docker service update --force entregas_queue`.
- **Task 15:** além do que o plano pede, documentar no CLAUDE.md:
  - Se a central corrige uma coordenada errada da loja, nasce um Local novo. Os pedidos antigos ficam com a coordenada antiga e o km deles tem de ser acertado à mão.
  - Os Locais antigos ficam sem dono, mas com o nome da loja na lista de Locais.
  - Throttle de 60/min nas rotas `loja/*`.
  - Posição do motoboy só com o pedido aceito há no máximo 4 h.
  - Como ligar a 6C.
  - Riscos conhecidos:
    - o socket não autentica canais `company.*`;
    - a chave `flb_live_` do APK enquanto a 6C estiver desligada;
    - o upload aceita `disk`/`path` de usuários que não são de loja (comportamento do core).
