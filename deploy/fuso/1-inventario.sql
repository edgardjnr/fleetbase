-- Fuso (horário de Brasília), passo 4 do LEIAME.md (e no ensaio): inventário. Só leitura; rode ANTES de qualquer
-- mudança e guarde a saída.
--
--   DB=$(docker ps -q -f name=entregas_database)
--   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/1-inventario.sql | tee ~/fuso-inventario.txt
--
-- (2) a (7b) leem o information_schema e as tabelas; nada é alterado. (8) a (10) usam SET time_zone, que vale só para
-- esta sessão.

-- 1) fuso do servidor e da sessão (hoje: SYSTEM/UTC) e se as tabelas de fuso nomeado estão carregadas
SELECT @@global.time_zone AS fuso_global, @@session.time_zone AS fuso_sessao, @@system_time_zone AS fuso_do_sistema,
       NOW() AS agora_na_sessao, UTC_TIMESTAMP() AS agora_utc,
       (SELECT COUNT(*) FROM mysql.time_zone_name) AS fusos_nomeados;

-- 2) schemas do Fleetbase (esperado: fleetbase e fleetbase_sandbox; fleetbase_storefront se o storefront criou o dele).
--    Apareceu outro schema fleetbase_*? Pare e confira antes de seguir: o 2-converter.sql só mexe nesses três.
SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'fleetbase%' ORDER BY SCHEMA_NAME;

-- 3) tipo REAL de cada coluna de data. DATETIME precisa de conversão; TIMESTAMP o MySQL converte sozinho; DATE e TIME
--    ficam como estão. users.last_login é VARCHAR com cast datetime no model: também é convertido.
SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA LIKE 'fleetbase%'
  AND (DATA_TYPE IN ('datetime', 'timestamp', 'date', 'time') OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login'))
ORDER BY FIELD(DATA_TYPE, 'datetime', 'varchar', 'timestamp', 'date', 'time'), TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME;

-- 4) só o que o 2-converter.sql vai deslocar 3 h para trás (a mesma consulta que ele usa). Esperado, em cada schema
--    (fleetbase e fleetbase_sandbox):
--      orders.dispatched_at, started_at, scheduled_at, time_window_start, time_window_end; waypoints.time_window_start,
--      time_window_end; issues.resolved_at; users.email_verified_at, phone_verified_at, last_login (varchar);
--      api_credentials.last_used_at, expires_at; invites.expires_at; verification_codes.expires_at;
--      webhook_request_logs.sent_at; monitored_scheduled_tasks.last_started_at, last_finished_at, last_failed_at,
--      last_skipped_at, registered_on_oh_dear_at, last_pinged_at.
--    No fleetbase_storefront (se existir): carts.expires_at.
--    Só tabelas de verdade (BASE TABLE, não views) e sem colunas geradas, como no converter.
SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE
FROM information_schema.COLUMNS c
JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
WHERE c.TABLE_SCHEMA IN ('fleetbase', 'fleetbase_sandbox', 'fleetbase_storefront')
  AND c.EXTRA NOT LIKE '%VIRTUAL GENERATED%' AND c.EXTRA NOT LIKE '%STORED GENERATED%'
  AND (c.DATA_TYPE = 'datetime' OR (c.TABLE_NAME = 'users' AND c.COLUMN_NAME = 'last_login' AND c.DATA_TYPE IN ('varchar', 'char')))
ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME;

-- 5) "on update CURRENT_TIMESTAMP" numa tabela da lista acima faria o UPDATE mexer em outra coluna: esperado vazio
--    (o 2-converter.sql se recusa a rodar se não estiver)
SELECT DISTINCT b.TABLE_SCHEMA, b.TABLE_NAME, b.COLUMN_NAME, b.EXTRA
FROM information_schema.COLUMNS a
JOIN information_schema.COLUMNS b ON b.TABLE_SCHEMA = a.TABLE_SCHEMA AND b.TABLE_NAME = a.TABLE_NAME
JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = a.TABLE_SCHEMA AND t.TABLE_NAME = a.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
WHERE a.TABLE_SCHEMA IN ('fleetbase', 'fleetbase_sandbox', 'fleetbase_storefront') AND a.DATA_TYPE = 'datetime'
  AND b.EXTRA LIKE '%on update%';

-- 6) triggers (esperado: nenhum)
SELECT TRIGGER_SCHEMA, TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA LIKE 'fleetbase%';

-- 7) já convertido? Conta a linha id = 1 da trava (esperado: 0). A tabela pode existir vazia: a revisão do
--    2-converter.sql a cria. Consulta montada na hora, porque a tabela pode não existir.
SET @trava = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'fleetbase' AND TABLE_NAME = 'entregas_fuso_convertido') = 0,
    'SELECT 0 AS ja_convertido, ''a tabela da trava não existe'' AS obs',
    'SELECT COUNT(*) AS ja_convertido, ''a tabela da trava existe'' AS obs FROM fleetbase.entregas_fuso_convertido WHERE id = 1');
PREPARE consulta FROM @trava;
EXECUTE consulta;
DEALLOCATE PREPARE consulta;

-- 7b) users.last_login preenchido fora do formato 'AAAA-MM-DD HH:MM:SS': o converter não mexe nessas linhas (esperado:
--     0 em cada schema; se não for, olhe os valores antes de seguir)
SET SESSION group_concat_max_len = 100000;
SELECT GROUP_CONCAT(CONCAT(
           'SELECT ''', TABLE_SCHEMA, ''' AS esquema, COUNT(*) AS last_login_fora_do_formato FROM `', TABLE_SCHEMA,
           '`.`users` WHERE last_login IS NOT NULL AND last_login <> '''' AND last_login NOT REGEXP ',
           '''^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$''')
       SEPARATOR ' UNION ALL ') INTO @formato
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA IN ('fleetbase', 'fleetbase_sandbox', 'fleetbase_storefront') AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login';
SET @formato = IFNULL(@formato, 'SELECT ''nenhum users.last_login'' AS obs');
PREPARE consulta FROM @formato;
EXECUTE consulta;
DEALLOCATE PREPARE consulta;

-- 8) "foto" para comparar depois da conversão (sessão em UTC, como a aplicação usa hoje): a média de minutos entre a
--    criação e o despacho tem de ficar igual depois, com a sessão em -03:00
SET time_zone = '+00:00';
SELECT COUNT(*) AS pedidos_despachados,
       ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, dispatched_at)), 1) AS media_min_criado_ate_despacho,
       SUM(ABS(TIMESTAMPDIFF(MINUTE, created_at, dispatched_at)) > 150) AS diferencas_acima_de_150_min
FROM fleetbase.orders WHERE dispatched_at IS NOT NULL AND deleted_at IS NULL;

-- 9) pedidos agendados ainda não despachados (conferir de novo depois: o scheduled_at tem de aparecer 3 h antes, na
--    hora de Brasília em que a central marcou). Os que caem dentro da janela de manutenção não são despachados sozinhos
--    (o fleetops:dispatch-orders só pega quem está a ±1 min): anote e veja o passo 10 do LEIAME.md.
SELECT public_id, status, created_at, scheduled_at
FROM fleetbase.orders
WHERE scheduled_at IS NOT NULL AND dispatched = 0 AND deleted_at IS NULL AND status NOT IN ('completed', 'canceled')
ORDER BY scheduled_at;

-- 10) os últimos pedidos, para comparar
SELECT public_id, status, created_at, dispatched_at, started_at, scheduled_at, updated_at
FROM fleetbase.orders ORDER BY id DESC LIMIT 5;
