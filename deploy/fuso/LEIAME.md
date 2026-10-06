# Troca do servidor para o horário de Brasília: roteiro de produção

O código (ramo `fuso-brasilia`) põe o PHP em `America/Sao_Paulo` e a sessão do MySQL em `-03:00`. Ver CLAUDE.md, "Fuso
(horário de Brasília)". No banco:

- as colunas **TIMESTAMP** (`created_at`, `updated_at`, `deleted_at`, as tabelas `entregas_*`…) não mudam. O MySQL
  guarda em UTC e converte na leitura;
- as colunas **DATETIME** (`orders.dispatched_at`, `started_at`, `scheduled_at` e mais umas 15) e o `users.last_login`
  (VARCHAR) guardam a hora sem fuso, gravada em UTC. Elas precisam andar **3 h para trás**, uma vez só, com nada
  gravando.

**Desta vez não use o `atualizar.sh`.** Ele atualiza os serviços e só depois roda o `deploy.sh`, e qualquer DATETIME
gravado pela versão nova antes da conversão andaria 6 h.

Antes de começar:

- tudo o que estava pendente de deploy já está em produção e estável. Esta troca vai sozinha;
- escolha um horário de pouco movimento (ex.: 04:00–06:00). São uns 10–15 min fora do ar: console, portal e app
  recebem erro, e o polling do iFood fica parado;
- os comandos são rodados na VPS, dentro de `~/entregas`.

## Passos

**1. Atualizar o clone e guardar a imagem atual (para desfazer)**

```bash
cd ~/entregas && git pull --ff-only
docker tag entregas-api:latest entregas-api:antes-fuso
```

**2. Buildar a imagem nova, sem atualizar os serviços**

```bash
docker build -t entregas-api:latest -f docker/Dockerfile --target app-release .
```

**3. Inventário (só leitura)**

```bash
DB=$(docker ps -q -f name=entregas_database)
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/1-inventario.sql | tee ~/fuso-inventario.txt
```

Confira:

- (2) os schemas são `fleetbase` e `fleetbase_sandbox`, e talvez `fleetbase_storefront`. Apareceu outro `fleetbase_*`?
  Pare;
- (4) a lista de DATETIME bate com a esperada;
- (5) e (6) vêm vazios;
- (7) dá `0`.

Guarde a "foto" de (8), (9) e (10).

**4. Números para comparar depois**

- Console → Fleet-Ops → Recursos → Pagamento e cobrança, **mês passado**: anote os totais (a pagar, a cobrar e o
  número de entregas) ou baixe o CSV.
- No app de um motoboy (ou pela central), os ganhos de um dia fechado.

**5. Parar quem grava no banco**

```bash
docker service scale entregas_application=0 entregas_queue=0 entregas_scheduler=0
docker ps -f name=entregas_application -f name=entregas_queue -f name=entregas_scheduler   # repita até vir vazio
```

**6. Backup**

```bash
BANCOS="fleetbase fleetbase_sandbox"   # acrescente fleetbase_storefront se ele apareceu no inventário
docker exec "$DB" sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers --set-gtid-purged=OFF --databases $BANCOS" > ~/backup-fuso-$(date +%F-%H%M).sql
ls -lh ~/backup-fuso-*.sql && tail -c 200 ~/backup-fuso-*.sql   # tem de terminar com "-- Dump completed"
```

**7. Converter as DATETIME**

Primeiro revise. Este comando não altera nada: lista cada UPDATE e quantas linhas ele mexe.

```bash
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/2-converter.sql | tee ~/fuso-revisao.txt
```

As colunas listadas são as de (4) no inventário. A última consulta mostra a média criado → despacho ~180 min acima da
foto, o que é esperado antes da conversão.

Depois converta:

```bash
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1"' < deploy/fuso/2-converter.sql | tee ~/fuso-conversao.txt
```

Confira na saída:

- `colunas_deslocadas` igual ao número de linhas de (4);
- a média criado → despacho **igual à foto** do passo 3 (8);
- os últimos pedidos com `dispatched_at`/`scheduled_at` 3 h antes da foto. O `created_at` também aparece 3 h antes,
  porque agora a sessão está em -03:00;
- a linha em `entregas_fuso_convertido`.

Rodar a conversão duas vezes falha com `Duplicate entry` e não altera nada.

**8. Subir a versão nova**

```bash
for s in application queue scheduler; do docker service update --force --replicas 1 --quiet "entregas_$s" >/dev/null; done
APP=""; for i in $(seq 1 60); do APP=$(docker ps -q -f name=entregas_application -f status=running | head -n1); [ -n "$APP" ] && break; sleep 3; done; echo "application: $APP"
docker exec "$APP" ./deploy.sh
docker exec "$(docker ps -q -f name=entregas_scheduler | head -n1)" php artisan schedule:clear-cache
```

O `deploy.sh` grava o `config:cache` com o fuso novo e limpa o cache.

**9. Verificar**

```bash
APP=$(docker ps -q -f name=entregas_application -f status=running | head -n1)
docker exec "$APP" php artisan tinker --execute='echo config("app.timezone"), " | ", DB::selectOne("select @@session.time_zone t")->t, " | ", DB::connection("sandbox")->selectOne("select @@session.time_zone t")->t, PHP_EOL;'
# esperado: America/Sao_Paulo | -03:00 | -03:00
docker exec "$APP" php artisan tinker --execute='$o = new Fleetbase\FleetOps\Models\Order; $o->scheduled_at = "2026-10-06T01:10:00.000Z"; echo $o->getAttributes()["scheduled_at"], PHP_EOL;'
# esperado: 2026-10-05 22:10:00   (o ISO com Z do console vira a hora de Brasília)
SCH=$(docker ps -q -f name=entregas_scheduler | head -n1)
docker exec "$SCH" php artisan tinker --execute='echo date_default_timezone_get(), " | ", get_class(app(Fleetbase\FleetOps\Console\Commands\DispatchOrders::class)), " | ", get_class(app(Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders::class)), PHP_EOL;'
# esperado: America/Sao_Paulo | App\Console\Commands\Entregas\Fuso\DespacharPedidosAgendados | App\Console\Commands\Entregas\ReenviarPedidosAbertos
docker service logs --since 10m entregas_application 2>&1 | grep -iE "error|exception" | tail
```

No uso, com a loja de testes Terraço Pizza Bar:

- crie pelo console um pedido **agendado** para daqui a 10 min. Ele tem de ser despachado no minuto marcado, nem 3 h
  antes nem 3 h depois;
- crie um pedido aberto e não aceite. Os reenvios saem aos ~4, 8 e 12 min, e a central é avisada ("sem motoboy") aos
  12 min;
- depois das 21h, a lista "Hoje" do app do motoboy mostra os pedidos do dia (era o bug do `on`);
- o Pagamento e cobrança do mês passado bate com o passo 4, e os ganhos do dia fechado também;
- os horários na linha do tempo do pedido (console) e no portal da loja estão certos;
- se o iFood estiver ligado: `SELECT despachar_em, NOW() FROM fleetbase.entregas_ifood_pedidos ORDER BY id DESC LIMIT 5;`
  (com `SET time_zone='-03:00'` antes) mostra os agendados na hora de Brasília.

**Opcional, pode ficar para depois: o MySQL inteiro em -03:00.** A aplicação já define o fuso em cada conexão. Isto só
faz as sessões manuais (`mysql` no container) também verem a hora de Brasília. Em Stacks → entregas → Editor, no
serviço `database`, acrescente `command: ["--default-time-zone=-03:00"]` e faça Update the stack com "Re-pull image"
**desligado**. O MySQL reinicia (~30 s). Sem isso, rode `SET time_zone='-03:00';` antes de qualquer consulta manual.
Sem ele, o TIMESTAMP sai em UTC e o DATETIME em Brasília.

## Desfazer

Se algo der errado depois de subir:

1. Pare de novo: `docker service scale entregas_application=0 entregas_queue=0 entregas_scheduler=0`, até o
   `docker ps` vir vazio.
2. Volte as DATETIME 3 h para a frente: revise e depois execute. Isso vale também para o que a versão nova gravou,
   porque todas estão em hora de Brasília.
   ```bash
   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @desfazer = 1"' < deploy/fuso/2-converter.sql
   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1, @desfazer = 1"' < deploy/fuso/2-converter.sql
   ```
3. Volte a imagem e suba:
   ```bash
   docker tag entregas-api:antes-fuso entregas-api:latest
   for s in application queue scheduler; do docker service update --force --replicas 1 --quiet "entregas_$s" >/dev/null; done
   docker exec "$(docker ps -q -f name=entregas_application -f status=running | head -n1)" ./deploy.sh
   ```
4. No git, reverta os commits do fuso na `main` antes do próximo deploy.

Se a conversão em si saiu errada, logo no começo: restaure o backup do passo 6. Isso perde o que entrou depois dele.

```bash
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < ~/backup-fuso-AAAA-MM-DD-HHMM.sql
```
