# Corridas do sistema (relatório do dono)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Controle do Edgard para cobrar das centrais de motoqueiros (cada central = uma organização nova na mesma instalação) um valor fixo por corrida finalizada. Spec: `docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md`; plano: `docs/superpowers/plans/2026-10-22-corridas-do-sistema.md` (executado com ajustes de revisão: **o código é a referência**). Tela: Fleet-Ops → Financeiro → Corridas do sistema (`packages/fleetops/CLAUDE.md`, "Financeiro"). Só o relatório: os recebimentos das centrais ficam fora (ele controla fora por enquanto).

## Quem vê

- Só o **administrador do sistema**: `users.type = 'admin'` (`User::isAdmin()`; no console, `currentUser.isAdmin`, que vem do `is_admin`). O Fleetbase só dá esse tipo ao **primeiro usuário** da instalação (`OnboardController::createAccount`: `$isAdmin = !User::exists()`); quem cria organização depois entra como `user`, mesmo sendo "Administrator" da própria organização. Em 2026-10-22, o único é o Edgard Junior (organização Restaurante Pro).
- API: `CorridasDoSistemaController::negarSeNaoDonoDoSistema` em todas as rotas (403 "Somente o administrador do sistema pode ver esta tela."). **Não** troque pelo `negarSeNaoAdmin` do Financeiro: aquele vai passar a ser o administrador da organização (ver "Só administradores" em `api/app/Support/Entregas/Financeiro/CLAUDE.md`).
- Console: item do menu com `visible: currentUser.isAdmin === true` próprio e guarda própria na rota (`routes/financeiro/corridas-do-sistema.js`), independentes das do resto do Financeiro.

## Registro permanente (`CorridasFinalizadas`)

- Tabela `entregas_corridas_finalizadas` (migration `2026_10_22_100000_create_entregas_sistema_tables.php`): uma linha por pedido (`order_uuid` único), sem `updated_at` e sem `deleted_at`. **O código nunca atualiza nem apaga:** cancelar, excluir o pedido ou o `deploy/zerar-pedidos` não tiram a corrida.
- Colunas: `company_uuid` (a central), `pedido` (`public_id`), `numero` (`internal_id`), `loja_uuid`/`loja_nome` (o Vendor dono do pedido; cliente que não é loja fica vazio), `motoboy_uuid`/`motoboy_nome` (o `driver_assigned_uuid` e o nome do User dele), `finalizada_em` (Brasília, `'Y-m-d H:i:s'`), `origem` (`conclusao`, `conferencia`, `historico`), `created_at`. Os nomes ficam gravados na hora; o nome da central é lido no relatório em `companies`, com as excluídas, e cai no uuid curto (8 caracteres) se a organização não existir mais.
- **Finalizada** = `orders.status` em `completed` ou `done` (`CorridasFinalizadas::CONCLUIDOS` = `ObservadorFinanceiroDosPedidos::CONCLUIDOS`), **ou** um `COMPLETED` no `tracking_statuses` do pedido (o cancelado depois de finalizado entra). O cancelado pago pelo iFood ou pela integração sem `COMPLETED` **não** entra.
- `finalizada_em`: no observador, agora; na conferência e no histórico, o primeiro `tracking_statuses.created_at` com `code = 'COMPLETED'` (inclusive status excluídos), senão o `orders.updated_at`.
- Consultas de `orders` só com `CorridasFinalizadas::COLUNAS_DO_PEDIDO` (o `id` pagina o histórico). Quem ler outra coluna do pedido precisa acrescentá-la ali.
- **Quem grava** (sempre `insertOrIgnore` pelo `order_uuid`: fica a primeira linha):
  1. `App\Listeners\Entregas\ObservadorDasCorridasDoSistema` (`Order::updated`, `AppServiceProvider::registrarCorridasDoSistema`): status mudou para concluído (aceita maiúsculas da API v1) → origem `conclusao`. **Relê o pedido no banco** (`DB::table`, só `COLUNAS_DO_PEDIDO`), porque o model pode ter vindo de um select parcial; sem a linha, usa o model. Sem chave nenhuma. Nunca lança (log `[entregas] sistema: falha ao registrar a corrida finalizada`, só o pedido e a classe do erro).
  2. `entregas:corridas-conferir` (`CorridasConferir`, a cada 5 min, `withoutOverlapping(30)`, **sem** `when`: não depende da `ENTREGAS_FINANCEIRO`): pedidos concluídos (status atualizado nos últimos 2 dias) ou com `COMPLETED` nos últimos 2 dias, de todas as organizações, excluídos também → origem `conferencia`.
  3. Histórico: o mesmo comando importa tudo o que está no banco, sem limite de data, em lotes de 500 pelo id → origem `historico`, **enquanto não existir a marca** `entregas.sistema.historico_importado` (tabela `settings`, `CorridasConferir::MARCA_DO_HISTORICO`, com a hora de Brasília). A marca só é gravada quando a importação termina sem erro: se cair no meio, a rodada seguinte importa de novo. À mão: `php artisan entregas:corridas-conferir --historico` reimporta mesmo com a marca (idempotente) e a regrava.
- **Não use "tabela vazia" como gatilho do histórico:** o observador grava a primeira corrida antes da primeira rodada do comando, e o histórico nunca seria importado.
- O comando roda **uma etapa por try** (histórico e conferência, como o `tentar()` do `FinanceiroConferir`): falha no histórico não impede a conferência. Sai com FAILURE se alguma falhou; a rodada seguinte tenta de novo.
- Lê pelo `DB::table` (sem escopos do Eloquent): pedido e organização excluídos entram sem `withTrashed`.
- Logs no `docker service logs entregas_scheduler`: `[entregas] sistema: histórico das corridas importado` (com a contagem), `... conferência gravou corridas` (com a contagem), `... conferência das corridas falhou` (com a `etapa` e a classe do erro).

## Preço por central (`PrecosDasCentrais`) e relatório (`RelatorioDasCorridas`)

- Tabela `entregas_precos_centrais`: `company_uuid`, `valor` (R$ 0,00 a 1000,00, até 2 casas), `vale_a_partir_de` (dia em Brasília), `criado_por`, `created_at`; único (`company_uuid`, `vale_a_partir_de`).
- Validação (`PrecosDasCentrais::valor` e `::data`): o valor em texto aceita só dígitos com vírgula ou ponto decimal (sem notação científica como `1e2` nem milhar como `1.000,00`); datas de 2020 a 2100 (`PRIMEIRO_ANO`/`ULTIMO_ANO`), também no período do relatório. Data repetida para a central vira 422, inclusive quando dois cadastros iguais chegam juntos e o índice único barra o segundo (`QueryException` 23000).
- O preço de uma corrida é o da central com a maior data ≤ dia da `finalizada_em`, **calculado na hora do relatório**: um preço novo não muda o que veio antes da data dele; um preço com data antiga precifica o histórico (é assim que se dá preço ao que veio da importação). Antes do primeiro preço, a corrida vale R$ 0,00 e conta em "sem preço".
- Não se edita: cadastra-se outro com outra data. Apagar existe (correção de digitação), com confirmação na tela, porque muda os valores do período dele.
- Relatório (`RelatorioDasCorridas::montar`): período de até 366 dias, `finalizada_em` de `inicio 00:00:00` a `fim 23:59:59`. Lê as corridas pelo `cursor()` (não monta o período inteiro em memória) e a soma é a função pura `agregar`. Centrais por nome, **sem olhar acento nem caixa** (`compararNomes`, "Área Sul" antes de "Zeta"), com `precos` = valores distintos usados (o console mostra "variado" quando há mais de um); lista em ordem de finalização, cortada em 20.000 linhas (`LIMITE_DA_LISTA`, `lista_cortada`), com os totais sempre completos. Contas em centavos.

## Rotas

Grupo `int/v1/entregas` (`fleetbase.protected`), todas com `negarSeNaoDonoDoSistema`. Erros em `{"errors": ["…"]}`.

| Método e caminho | Controller@método |
|---|---|
| GET `sistema/corridas?inicio=Y-m-d&fim=Y-m-d` | `CorridasDoSistemaController@corridas` → `{inicio, fim, total: {corridas, a_cobrar, sem_preco}, centrais: [{company, nome, corridas, a_cobrar, sem_preco, precos}], corridas: [{finalizada_em (ISO -03:00), company, central, pedido, numero, loja_nome, motoboy_nome, preco, origem}], lista_cortada}`; período inválido → 422 |
| GET `sistema/precos` | `@precos` → `{centrais: [{company, nome, precos: [{id, valor, vale_a_partir_de}]}]}`: as organizações não excluídas e as excluídas que têm preço; preços do mais recente para o mais antigo |
| POST `sistema/precos` `{company, valor, vale_a_partir_de}` | `@criarPreco` → `{preco}`; 422 para valor fora da faixa ou mal escrito, data inválida ou fora de 2020–2100, organização inexistente ou excluída e data repetida para a central |
| DELETE `sistema/precos/{id}` | `@apagarPreco` → `{apagado: true}`; 404 se não existe |

## Implantação

API + console (`bash deploy/atualizar.sh` na VPS). A migration cria as duas tabelas no `deploy.sh`; a primeira conferência (até 5 min depois) importa o histórico e grava a marca. Depois, o Edgard cadastra o preço da Restaurante Pro (e de cada central nova) com a data a partir da qual quer cobrar.

## Fora do escopo (pendência antes de vender às centrais)

- O Financeiro inteiro e o painel da distribuição só abrem para o administrador do sistema: o `negarSeNaoAdmin` deles usa `isNotAdmin()` (`type !== 'admin'`). O administrador de cada central nova não vai conseguir usá-los na própria organização. Antes de vender às centrais, trocar pelo papel "Administrator" da organização (ver "Só administradores" em `api/app/Support/Entregas/Financeiro/CLAUDE.md`). Esta tela fica de fora dessa troca.

## Testes

- `scripts/teste-php/corridas-do-sistema.php` (migrations, registro idempotente, conferência com excluído e cancelado depois do `COMPLETED`, histórico pela marca: primeira rodada, marca gravada só no fim, `--historico` com e sem a marca, histórico que para no meio; preço pela vigência e "variado", validações do valor e da data, totais, cursor e lista cortada, observador, comando, agenda, 403 para quem não é `admin`, validações do POST, rotas); `ifood-agendador.php` tira o comando da lista dos do iFood; `imports-dos-providers.php`.
- Front: `scripts/teste-portal/corridas-do-sistema.test.mjs` (`packages/fleetops/addon/utils/corridas-do-sistema.js`).

## Armadilhas

- O `insertOrIgnore` do MySQL engole outros erros além da chave repetida (dado truncado): por isso os textos são cortados antes (`pedido` em 64, nomes e número em 191).
- O `deploy/zerar-pedidos` **não** apaga esta tabela (de propósito: a corrida nunca sai). O `deploy/apagar-pedidos/apagar.sql` apaga a corrida dos pedidos que ele apaga (desde 2026-10-23: "como se não tivessem existido"); com o pedido fora do banco, a conferência e o `--historico` não a gravam de novo. Se um dia for preciso, apague à mão e rode `php artisan entregas:corridas-conferir --historico` (só apagar a tabela não basta: com a marca gravada, o histórico não é reimportado sozinho).
- Pedido concluído sem `orders.updated_at` recente e sem `COMPLETED` no rastreio (ex.: status trocado no banco à mão com data antiga) só entra pelo `--historico`.

## Ao atualizar o fleetops-api ou o core-api, confira

- `Order::updated` ainda dispara na conclusão (o app conclui pela API v1) e o `status` muda para `completed`/`done`.
- `tracking_statuses.code = 'COMPLETED'` e o vínculo `orders.tracking_number_uuid` → `tracking_statuses.tracking_number_uuid`.
- As colunas de `CorridasFinalizadas::COLUNAS_DO_PEDIDO` em `orders`, `drivers.user_uuid` → `users.name` (nome do motoboy), `vendors.name`, `companies.name` e `companies.deleted_at`.
- `Setting::lookup`/`Setting::configure` (marca do histórico).
- `User::isAdmin()` (`type === 'admin'`) e o `OnboardController` (só o primeiro usuário é admin); no console, `currentUser.isAdmin`.
