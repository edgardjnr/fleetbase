# Troca do servidor para o horário de Brasília: roteiro de produção

O código (ramo `fuso-brasilia`) põe o PHP em `America/Sao_Paulo` e a sessão do MySQL em `-03:00`. Ver CLAUDE.md, "Fuso
(horário de Brasília)". No banco:

- as colunas **TIMESTAMP** (`created_at`, `updated_at`, `deleted_at`, as tabelas `entregas_*`…) não mudam. O MySQL
  guarda em UTC e converte na leitura;
- as colunas **DATETIME** (`orders.dispatched_at`, `started_at`, `scheduled_at` e mais umas 15) e o `users.last_login`
  (VARCHAR) guardam a hora sem fuso, gravada em UTC. Elas precisam andar **3 h para trás**, uma vez só, com nada
  gravando.

**Desta vez não use o `atualizar.sh`.** Ele atualiza os serviços e só depois roda o `deploy.sh`, e qualquer DATETIME
gravado pela versão nova antes da conversão andaria 6 h. A imagem nova é buildada como `entregas-api:fuso` e só vira
`entregas-api:latest` depois da conversão, com tudo parado.

## Pré-requisitos

- O ramo `fuso-brasilia` foi mergeado na `main` e enviado (`git push`). Na VPS, o `git pull` traz o código novo.
- Tudo o que estava pendente de deploy já está em produção e estável. Esta troca vai sozinha.
- O **ensaio** abaixo foi feito na VPS, com sucesso, há pouco tempo.
- O horário é de pouco movimento (ex.: 04:00–06:00). São uns 10–15 min fora do ar: console, portal e app recebem erro,
  o polling do iFood fica parado, e agendados que caem na janela precisam de despacho à mão (passo 10).
- Os comandos rodam na VPS, dentro de `~/entregas`.

## Ensaio (obrigatório, antes da janela)

Ele roda num MySQL descartável na própria VPS, com um dump recente, e não mexe na produção. O dump é feito com
`--single-transaction`, sem parar nada.

```bash
cd ~/entregas && git pull --ff-only
DB=$(docker ps -q -f name=entregas_database)
BANCOS="fleetbase fleetbase_sandbox"   # acrescente fleetbase_storefront se ele existir
docker exec "$DB" sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers --set-gtid-purged=OFF --databases $BANCOS" > ~/ensaio-fuso.sql
tail -c 100 ~/ensaio-fuso.sql   # tem de terminar com "-- Dump completed"

docker run -d --name ensaio-fuso -e MYSQL_ROOT_PASSWORD=ensaio mysql:8.0-oracle
until docker logs ensaio-fuso 2>&1 | grep -q 'ready for connections.*port: 3306'; do sleep 3; done
docker exec -i ensaio-fuso sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < ~/ensaio-fuso.sql

# inventário antes
docker exec -i ensaio-fuso sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/1-inventario.sql > ~/ensaio-1-antes.txt
# revisão (não altera nada)
docker exec -i ensaio-fuso sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/2-converter.sql > ~/ensaio-2-revisao.txt
# converter
docker exec -i ensaio-fuso sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1"' < deploy/fuso/2-converter.sql > ~/ensaio-3-conversao.txt
# converter de novo: TEM de falhar com "Duplicate entry '1'" (e não altera nada)
docker exec -i ensaio-fuso sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1"' < deploy/fuso/2-converter.sql; echo "saída: $? (esperado: diferente de 0)"
# desfazer
docker exec -i ensaio-fuso sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1, @desfazer = 1"' < deploy/fuso/2-converter.sql > ~/ensaio-4-desfazer.txt
# inventário depois do desfazer
docker exec -i ensaio-fuso sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/1-inventario.sql > ~/ensaio-5-depois.txt
```

Confira:

- `ensaio-2-revisao.txt`:
  - as colunas listadas são as do item (4) de `ensaio-1-antes.txt`, cada uma com a contagem de linhas;
  - nenhum aviso de outras conexões;
  - `modo` = "só listado".
- `ensaio-3-conversao.txt`:
  - `colunas_deslocadas` igual ao número de linhas do item (4);
  - "depois da conversão" com a média criado → despacho **igual** à do item (8) de `ensaio-1-antes.txt`;
  - os últimos pedidos com `dispatched_at`/`scheduled_at` 3 h antes;
  - a linha 1 em `entregas_fuso_convertido`.
- A segunda conversão terminou com erro `Duplicate entry '1'`.
- `diff ~/ensaio-1-antes.txt ~/ensaio-5-depois.txt`:
  - só pode mudar a linha do `NOW()`/`UTC_TIMESTAMP()` do item (1);
  - o item (7) volta a 0, mas agora com "a tabela da trava existe";
  - a média e as contagens do item (8) e os pedidos dos itens (9) e (10) ficam **iguais**.

Remova o container e os arquivos:

```bash
docker rm -f -v ensaio-fuso && rm -f ~/ensaio-fuso.sql ~/ensaio-*.txt
```

O `-v` apaga junto o volume anônimo do container descartável.

## Passos

**1. Atualizar o clone e marcar a imagem em uso como `antes-fuso` (para desfazer)**

```bash
cd ~/entregas && git pull --ff-only && git log --oneline -1
APP=$(docker ps -q -f name=entregas_application -f status=running | head -n1)
docker tag "$(docker inspect -f '{{.Image}}' "$APP")" entregas-api:antes-fuso
docker image ls entregas-api
```

**2. Buildar a imagem nova como `entregas-api:fuso` (os serviços continuam na atual)**

```bash
cd ~/entregas && docker build -t entregas-api:fuso -f docker/Dockerfile --target app-release .
```

**3. Conferir a imagem nova**

```bash
docker run --rm --entrypoint grep entregas-api:fuso -n America/Sao_Paulo /fleetbase/api/config/app.php
# esperado: uma linha com 'timezone' => 'America/Sao_Paulo'
```

**4. Inventário (só leitura)**

```bash
cd ~/entregas
DB=$(docker ps -q -f name=entregas_database)
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/1-inventario.sql | tee ~/fuso-inventario.txt
```

Confira:

- (2) os schemas são `fleetbase` e `fleetbase_sandbox`, e talvez `fleetbase_storefront`. Apareceu outro `fleetbase_*`?
  Pare;
- (4) a lista bate com a do ensaio;
- (5) e (6) vêm vazios;
- (7) dá 0;
- (7b) dá 0.

Guarde a foto de (8), a lista de agendados de (9) e (10).

**5. Números para comparar depois**

- Console → Fleet-Ops → Recursos → Pagamento e cobrança, **mês passado**: anote os totais (a pagar, a cobrar e o
  número de entregas) ou baixe o CSV.
- Os ganhos de um motoboy num dia fechado.
- Anote a **hora de início** da janela, para o passo 10.

**6. Parar quem grava no banco**

```bash
docker service scale entregas_application=0 entregas_queue=0 entregas_scheduler=0
docker ps -f name=entregas_application -f name=entregas_queue -f name=entregas_scheduler   # repita até vir vazio
```

**7. Backup**

```bash
DB=$(docker ps -q -f name=entregas_database)
BANCOS="fleetbase fleetbase_sandbox"   # acrescente fleetbase_storefront se ele apareceu no inventário
docker exec "$DB" sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers --set-gtid-purged=OFF --databases $BANCOS" > ~/backup-fuso-$(date +%F-%H%M).sql
ls -lh ~/backup-fuso-*.sql && tail -c 100 "$(ls -t ~/backup-fuso-*.sql | head -n1)"   # tem de terminar com "-- Dump completed"
```

**8. Converter as DATETIME**

Primeiro revise. Este comando não altera nada. Ele lista cada UPDATE e quantas linhas ele mexe, e avisa se ainda há
outra conexão nos bancos `fleetbase*`.

```bash
cd ~/entregas
DB=$(docker ps -q -f name=entregas_database)
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/2-converter.sql | tee ~/fuso-revisao.txt
```

Depois converta. A execução se recusa a rodar se houver outra conexão nos bancos `fleetbase*` ou se já tiver sido
convertido.

```bash
cd ~/entregas
DB=$(docker ps -q -f name=entregas_database)
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1"' < deploy/fuso/2-converter.sql | tee ~/fuso-conversao.txt
```

Confira o mesmo que no ensaio:

- `colunas_deslocadas`;
- a média criado → despacho igual à foto do passo 4 (8);
- os últimos pedidos 3 h antes. O `created_at` também aparece 3 h antes, porque a sessão agora está em -03:00;
- a linha da trava.

**9. Trocar a imagem e subir (application primeiro)**

Com tudo ainda em 0 réplicas:

```bash
docker tag entregas-api:fuso entregas-api:latest
docker service update --force --replicas 1 --quiet entregas_application >/dev/null
APP=""; for i in $(seq 1 60); do APP=$(docker ps -q -f name=entregas_application -f status=running | head -n1); [ -n "$APP" ] && break; sleep 3; done; echo "application: $APP"
docker exec "$APP" php artisan cache:clear
docker exec "$APP" ./deploy.sh
docker service update --force --replicas 1 --quiet entregas_queue >/dev/null
docker service update --force --replicas 1 --quiet entregas_scheduler >/dev/null
SCH=""; for i in $(seq 1 60); do SCH=$(docker ps -q -f name=entregas_scheduler -f status=running | head -n1); [ -n "$SCH" ] && break; sleep 3; done
docker exec "$SCH" php artisan schedule:clear-cache
```

- O `cache:clear` tira do Redis as travas `withoutOverlapping` deixadas pelo agendador parado no meio e o cache antigo.
- O `deploy.sh` grava o `config:cache` com o fuso novo.

**10. Agendados que caíram na janela**

O `fleetops:dispatch-orders` só despacha quem está a ±1 min do `scheduled_at`. Um pedido agendado pelo console para
dentro da janela (lista do passo 4, item 9) fica parado.

```bash
DB=$(docker ps -q -f name=entregas_database)
docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' <<'SQL'
SET time_zone = '-03:00';
SELECT public_id, status, scheduled_at, NOW() AS agora FROM fleetbase.orders
WHERE scheduled_at BETWEEN NOW() - INTERVAL 1 HOUR AND NOW()   -- ajuste o 1 HOUR para cobrir desde o início da janela
  AND dispatched = 0 AND deleted_at IS NULL AND status IN ('pending', 'created')
ORDER BY scheduled_at;
SQL
```

Despache cada um à mão no console (Fleet-Ops → pedido → Despachar).

Agendados do iFood (`despachar_em`) voltam sozinhos se a janela durou menos de 30 min. Depois disso, o agendador
desiste e avisa a central ("sem motoboy").

**11. Verificar**

```bash
APP=$(docker ps -q -f name=entregas_application -f status=running | head -n1)
docker exec "$APP" php artisan tinker --execute='echo config("app.timezone"), " | ", DB::selectOne("select @@session.time_zone t")->t, " | ", DB::connection("sandbox")->selectOne("select @@session.time_zone t")->t, PHP_EOL;'
# esperado: America/Sao_Paulo | -03:00 | -03:00
docker exec "$APP" php artisan tinker --execute='$o = new Fleetbase\FleetOps\Models\Order; $o->scheduled_at = "2026-10-06T01:10:00.000Z"; echo $o->getAttributes()["scheduled_at"], PHP_EOL;'
# esperado: 2026-10-05 22:10:00   (o ISO com Z do console vira a hora de Brasília)
SCH=$(docker ps -q -f name=entregas_scheduler -f status=running | head -n1)
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
- o Pagamento e cobrança do mês passado e os ganhos do dia fechado batem com o passo 5;
- os horários na linha do tempo do pedido (console) e no portal da loja estão certos;
- se o iFood estiver ligado: `SET time_zone='-03:00'; SELECT despachar_em, NOW() FROM fleetbase.entregas_ifood_pedidos ORDER BY id DESC LIMIT 5;`
  mostra os agendados na hora de Brasília.

**Opcional, pode ficar para depois: o MySQL inteiro em -03:00.** A aplicação já define o fuso em cada conexão. Isto só
faz as sessões manuais (`mysql` no container) também verem a hora de Brasília. Em Stacks → entregas → Editor, no
serviço `database`, acrescente `command: ["--default-time-zone=-03:00"]` e faça Update the stack com "Re-pull image"
**desligado**. O MySQL reinicia (~30 s). Sem isso, rode `SET time_zone='-03:00';` antes de qualquer consulta manual.
Sem ele, o TIMESTAMP sai em UTC e o DATETIME em Brasília.

## O que muda além das datas (aceito)

- As tarefas `daily()`, `twiceDaily(1, 13)` e `dailyAt()` do agendador (purges do core, `telemetry:ping`, manutenção do
  Fleet-Ops, `materialize-schedules`) passam a rodar na hora de Brasília.
- Os logs do Laravel saem em -03:00.
- Cliente externo da API v1 que manda ISO com `Z` em **filtro** de data (`created_at`, `on`…) erra 3 h. Ao gravar, a
  conversão cobre. Texto sem fuso passa a valer como hora de Brasília, quando antes valia como UTC.
- Telas ocultas (agenda/escalas, manutenção, orquestrador) e o `sandbox:sync` não foram revisados e podem errar 3 h.

## Desfazer

Se algo der errado depois de subir:

1. Pare de novo:
   ```bash
   docker service scale entregas_application=0 entregas_queue=0 entregas_scheduler=0
   docker ps -f name=entregas_application -f name=entregas_queue -f name=entregas_scheduler   # repita até vir vazio
   ```
2. Volte as DATETIME 3 h para a frente: revise e depois execute. Isso vale também para o que a versão nova gravou,
   porque está tudo em hora de Brasília.
   ```bash
   cd ~/entregas
   DB=$(docker ps -q -f name=entregas_database)
   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @desfazer = 1"' < deploy/fuso/2-converter.sql
   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1, @desfazer = 1"' < deploy/fuso/2-converter.sql
   ```
3. Volte a imagem e suba:
   ```bash
   docker tag entregas-api:antes-fuso entregas-api:latest
   for s in application queue scheduler; do docker service update --force --replicas 1 --quiet "entregas_$s" >/dev/null; done
   APP=""; for i in $(seq 1 60); do APP=$(docker ps -q -f name=entregas_application -f status=running | head -n1); [ -n "$APP" ] && break; sleep 3; done
   docker exec "$APP" php artisan cache:clear
   docker exec "$APP" ./deploy.sh
   ```
4. No git, reverta os commits do fuso na `main` antes do próximo deploy.

### Restaurar o backup

Use isto se a conversão em si saiu errada. Perde tudo o que entrou depois do backup do passo 7.

1. Pare os serviços (como no passo 1 de "Desfazer").
2. Se a imagem nova já subiu, volte a antiga: `docker tag entregas-api:antes-fuso entregas-api:latest`.
3. Restaure e apague a trava. O dump é de antes da conversão: sem a trava, um próximo `2-converter.sql` rodaria de
   novo, como deve.
   ```bash
   DB=$(docker ps -q -f name=entregas_database)
   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < ~/backup-fuso-AAAA-MM-DD-HHMM.sql
   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' <<'SQL'
   DROP TABLE IF EXISTS fleetbase.entregas_fuso_convertido;
   SQL
   ```
4. Suba como no passo 3 de "Desfazer" (com a imagem `antes-fuso`), ou repita a troca a partir do passo 8 (o passo 9
   marca de novo a `entregas-api:fuso` como `latest`).
