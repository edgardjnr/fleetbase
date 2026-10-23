-- Apagar pedidos escolhidos pelo public_id, "como se não tivessem existido" (2026-10-20). Molde: deploy/zerar-pedidos/
-- 2-apagar.sql, mas filtrado: só os pedidos de @pedidos e o que depende deles. Apaga DE VERDADE (DELETE).
--
-- Sai: o pedido, carga, paradas, itens, rastreio e atividades, comprovantes, posições do pedido, valores congelados,
-- dados iFood e da integração do pedido (e os eventos iFood dele), distribuição e ofertas, o Financeiro DO PEDIDO
-- (corridas do motoboy e da loja e os estornos delas), atividades, comentários, campos extras, arquivos (só o registro;
-- o arquivo no disco fica), avisos gravados e eventos de webhook que citam o pedido, transações do pedido e os locais de
-- entrega que só esse pedido usava. Também sai a corrida do relatório do dono (entregas_corridas_finalizadas, desde
-- 2026-10-23): com o pedido apagado do banco, a conferência não a grava de novo. Fica: o Local da loja, lojas, motoboys,
-- contatos e qualquer local usado por outro registro. Para escolher pelo motoboy: inventario-motoboy.sql.
--
-- Para (sem apagar nada) se um lançamento do pedido já estiver numa fatura ou num pagamento: aí o acerto é pelo
-- Financeiro, não por aqui.
--
-- Sem @executar = 1 é só REVISÃO: apaga dentro de uma transação, mostra quantas linhas sairiam e desfaz (ROLLBACK).
--   DB=$(docker ps -q -f name=entregas_database)
--   docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -t --init-command="SET @pedidos = '\''order_aaa,order_bbb'\''" fleetbase' < deploy/apagar-pedidos/apagar.sql
--   (execução: --init-command="SET @pedidos = '...', @executar = 1")

SET NAMES utf8mb4;
SET time_zone = '-03:00';

DROP TEMPORARY TABLE IF EXISTS ap_resumo;
CREATE TEMPORARY TABLE ap_resumo (passo INT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(64), linhas BIGINT NULL, comando VARCHAR(160)) ENGINE = MEMORY;

-- os pedidos (CREATE ... SELECT herda a collation das colunas de origem)
DROP TEMPORARY TABLE IF EXISTS ap_pedidos;
CREATE TEMPORARY TABLE ap_pedidos SELECT uuid, public_id, payload_uuid, transaction_uuid FROM orders WHERE FIND_IN_SET(public_id, @pedidos) > 0;

DROP TEMPORARY TABLE IF EXISTS ap_payloads;
CREATE TEMPORARY TABLE ap_payloads SELECT DISTINCT payload_uuid AS uuid FROM ap_pedidos WHERE payload_uuid IS NOT NULL;

DROP TEMPORARY TABLE IF EXISTS ap_waypoints;
CREATE TEMPORARY TABLE ap_waypoints SELECT w.uuid, w.place_uuid, w.tracking_number_uuid FROM waypoints w JOIN ap_payloads p ON p.uuid = w.payload_uuid;

DROP TEMPORARY TABLE IF EXISTS ap_entidades;
CREATE TEMPORARY TABLE ap_entidades SELECT e.uuid, e.tracking_number_uuid FROM entities e JOIN ap_payloads p ON p.uuid = e.payload_uuid;

-- tudo que é "sujeito" de atividade, comentário, arquivo ou rastreio
DROP TEMPORARY TABLE IF EXISTS ap_sujeitos;
CREATE TEMPORARY TABLE ap_sujeitos SELECT uuid FROM ap_pedidos;
INSERT INTO ap_sujeitos SELECT uuid FROM ap_payloads;
INSERT INTO ap_sujeitos SELECT uuid FROM ap_waypoints;
INSERT INTO ap_sujeitos SELECT uuid FROM ap_entidades;

DROP TEMPORARY TABLE IF EXISTS ap_rastreios;
CREATE TEMPORARY TABLE ap_rastreios SELECT t.uuid FROM tracking_numbers t JOIN ap_sujeitos s ON s.uuid = t.owner_uuid;
INSERT INTO ap_rastreios SELECT tracking_number_uuid FROM ap_waypoints WHERE tracking_number_uuid IS NOT NULL;
INSERT INTO ap_rastreios SELECT tracking_number_uuid FROM ap_entidades WHERE tracking_number_uuid IS NOT NULL;

DROP TEMPORARY TABLE IF EXISTS ap_comprovantes;
CREATE TEMPORARY TABLE ap_comprovantes SELECT pr.uuid FROM proofs pr JOIN ap_pedidos o ON o.uuid = pr.order_uuid;

INSERT INTO ap_sujeitos SELECT uuid FROM ap_rastreios;
INSERT INTO ap_sujeitos SELECT uuid FROM ap_comprovantes;
INSERT INTO ap_sujeitos SELECT ts.uuid FROM tracking_statuses ts JOIN ap_rastreios r ON r.uuid = ts.tracking_number_uuid;

-- locais candidatos (coleta, entrega, retorno e paradas); o Local da loja e os usados por outros registros são tirados
DROP TEMPORARY TABLE IF EXISTS ap_locais;
CREATE TEMPORARY TABLE ap_locais SELECT pl.pickup_uuid AS uuid FROM payloads pl JOIN ap_payloads p ON p.uuid = pl.uuid WHERE pl.pickup_uuid IS NOT NULL;
INSERT INTO ap_locais SELECT pl.dropoff_uuid FROM payloads pl JOIN ap_payloads p ON p.uuid = pl.uuid WHERE pl.dropoff_uuid IS NOT NULL;
INSERT INTO ap_locais SELECT pl.return_uuid FROM payloads pl JOIN ap_payloads p ON p.uuid = pl.uuid WHERE pl.return_uuid IS NOT NULL;
INSERT INTO ap_locais SELECT place_uuid FROM ap_waypoints WHERE place_uuid IS NOT NULL;
DELETE FROM ap_locais WHERE uuid IN (SELECT place_uuid FROM vendors WHERE place_uuid IS NOT NULL)
                         OR uuid IN (SELECT place_uuid FROM companies WHERE place_uuid IS NOT NULL)
                         OR uuid IN (SELECT place_uuid FROM contacts WHERE place_uuid IS NOT NULL);
DROP TEMPORARY TABLE IF EXISTS ap_payloads2;
CREATE TEMPORARY TABLE ap_payloads2 SELECT uuid FROM ap_payloads;
-- um comando por consulta: o MySQL não deixa citar a mesma tabela temporária duas vezes no mesmo comando
DELETE FROM ap_locais WHERE uuid IN (SELECT pickup_uuid FROM payloads WHERE uuid NOT IN (SELECT uuid FROM ap_payloads2) AND pickup_uuid IS NOT NULL);
DELETE FROM ap_locais WHERE uuid IN (SELECT dropoff_uuid FROM payloads WHERE uuid NOT IN (SELECT uuid FROM ap_payloads2) AND dropoff_uuid IS NOT NULL);
DELETE FROM ap_locais WHERE uuid IN (SELECT return_uuid FROM payloads WHERE uuid NOT IN (SELECT uuid FROM ap_payloads2) AND return_uuid IS NOT NULL);
DELETE FROM ap_locais WHERE uuid IN (SELECT place_uuid FROM waypoints WHERE payload_uuid NOT IN (SELECT uuid FROM ap_payloads2) AND place_uuid IS NOT NULL);
DELETE FROM ap_locais WHERE uuid IN (SELECT destination_uuid FROM entities WHERE (payload_uuid IS NULL OR payload_uuid NOT IN (SELECT uuid FROM ap_payloads2)) AND destination_uuid IS NOT NULL);

-- Financeiro do pedido: as corridas e os estornos delas
DROP TEMPORARY TABLE IF EXISTS ap_lancamentos;
CREATE TEMPORARY TABLE ap_lancamentos SELECT l.id, l.fatura_uuid, l.pagamento_uuid FROM entregas_lancamentos l JOIN ap_pedidos o ON o.uuid = l.order_uuid;
DROP TEMPORARY TABLE IF EXISTS ap_lancamentos2;
CREATE TEMPORARY TABLE ap_lancamentos2 SELECT id FROM ap_lancamentos;
-- os estornos dessas corridas (estorno tem estorno_de preenchido; corrida não: não há repetição)
INSERT INTO ap_lancamentos SELECT l.id, l.fatura_uuid, l.pagamento_uuid FROM entregas_lancamentos l
  WHERE l.estorno_de IN (SELECT id FROM ap_lancamentos2) AND (l.order_uuid IS NULL OR l.order_uuid NOT IN (SELECT uuid FROM ap_pedidos));

DROP TEMPORARY TABLE IF EXISTS ap_pedidos_ifood;
CREATE TEMPORARY TABLE ap_pedidos_ifood SELECT i.pedido_ifood_id FROM entregas_ifood_pedidos i JOIN ap_pedidos o ON o.uuid = i.order_uuid;

DROP TEMPORARY TABLE IF EXISTS ap_eventos;
-- collation explícita dos dois lados: o JSON vira texto com a collation da conexão, que pode ser outra
CREATE TEMPORARY TABLE ap_eventos SELECT e.uuid FROM api_events e JOIN ap_pedidos o
  ON (CAST(e.data AS CHAR) COLLATE utf8mb4_general_ci LIKE CONVERT(CONCAT('%', o.uuid, '%') USING utf8mb4) COLLATE utf8mb4_general_ci
   OR CAST(e.data AS CHAR) COLLATE utf8mb4_general_ci LIKE CONVERT(CONCAT('%', o.public_id, '%') USING utf8mb4) COLLATE utf8mb4_general_ci);

DROP TEMPORARY TABLE IF EXISTS ap_transacoes;
CREATE TEMPORARY TABLE ap_transacoes SELECT transaction_uuid AS uuid FROM ap_pedidos WHERE transaction_uuid IS NOT NULL;

DROP PROCEDURE IF EXISTS ap_rodar;
DROP PROCEDURE IF EXISTS ap_executar;

DELIMITER //

CREATE PROCEDURE ap_rodar(IN p_tabela VARCHAR(64), IN p_coluna VARCHAR(64), IN p_sql TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND COLUMN_NAME = p_coluna) THEN
    SET @ap_sql = p_sql;
    PREPARE comando FROM @ap_sql;
    EXECUTE comando;
    SET @ap_linhas = ROW_COUNT();
    DEALLOCATE PREPARE comando;
    INSERT INTO ap_resumo (tabela, linhas, comando) VALUES (p_tabela, @ap_linhas, LEFT(p_sql, 160));
  ELSE
    INSERT INTO ap_resumo (tabela, linhas, comando) VALUES (p_tabela, NULL, CONCAT('pulado: sem ', p_tabela, '.', p_coluna));
  END IF;
END //

CREATE PROCEDURE ap_executar(IN p_executar INT)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET FOREIGN_KEY_CHECKS = 1;
    RESIGNAL;
  END;

  IF (SELECT COUNT(*) FROM ap_pedidos) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nenhum pedido encontrado em @pedidos: nada a fazer';
  END IF;
  IF EXISTS (SELECT 1 FROM ap_lancamentos WHERE fatura_uuid IS NOT NULL OR pagamento_uuid IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lançamento do pedido já está numa fatura ou pagamento: acerte pelo Financeiro; nada foi apagado';
  END IF;

  SET FOREIGN_KEY_CHECKS = 0;
  START TRANSACTION;

  -- 1. o que aponta para os pedidos
  CALL ap_rodar('drivers', 'current_job_uuid', 'UPDATE drivers SET current_job_uuid = NULL WHERE current_job_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('entregas_ofertas', 'pedido_uuid', 'DELETE FROM entregas_ofertas WHERE pedido_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('entregas_distribuicoes', 'pedido_uuid', 'DELETE FROM entregas_distribuicoes WHERE pedido_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('entregas_corridas_finalizadas', 'order_uuid', 'DELETE FROM entregas_corridas_finalizadas WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('entregas_valores_pedido', 'order_uuid', 'DELETE FROM entregas_valores_pedido WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('entregas_ifood_eventos', 'pedido_ifood_id', 'DELETE FROM entregas_ifood_eventos WHERE pedido_ifood_id IN (SELECT pedido_ifood_id FROM ap_pedidos_ifood)');
  CALL ap_rodar('entregas_ifood_pedidos', 'order_uuid', 'DELETE FROM entregas_ifood_pedidos WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('entregas_integracao_pedidos', 'order_uuid', 'DELETE FROM entregas_integracao_pedidos WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('positions', 'order_uuid', 'DELETE FROM positions WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('geofence_events_log', 'order_uuid', 'DELETE FROM geofence_events_log WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('issues', 'order_uuid', 'UPDATE issues SET order_uuid = NULL WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('fuel_provider_transactions', 'order_uuid', 'UPDATE fuel_provider_transactions SET order_uuid = NULL WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');

  -- 2. Financeiro do pedido (corridas e estornos)
  CALL ap_rodar('entregas_lancamentos', 'id', 'DELETE FROM entregas_lancamentos WHERE id IN (SELECT id FROM ap_lancamentos)');

  -- 3. atividades, comentários, campos, arquivos, avisos e eventos de webhook
  CALL ap_rodar('activity', 'subject_id', 'DELETE FROM activity WHERE subject_id IN (SELECT uuid FROM ap_sujeitos)');
  CALL ap_rodar('comments', 'subject_uuid', 'DELETE FROM comments WHERE subject_uuid IN (SELECT uuid FROM ap_sujeitos)');
  CALL ap_rodar('custom_field_values', 'subject_uuid', 'DELETE FROM custom_field_values WHERE subject_uuid IN (SELECT uuid FROM ap_sujeitos)');
  CALL ap_rodar('files', 'subject_uuid', 'DELETE FROM files WHERE subject_uuid IN (SELECT uuid FROM ap_sujeitos)');
  CALL ap_rodar('notifications', 'data', 'DELETE n FROM notifications n JOIN ap_pedidos o ON (CONVERT(n.data USING utf8mb4) COLLATE utf8mb4_general_ci LIKE CONVERT(CONCAT(''%'', o.uuid, ''%'') USING utf8mb4) COLLATE utf8mb4_general_ci OR CONVERT(n.data USING utf8mb4) COLLATE utf8mb4_general_ci LIKE CONVERT(CONCAT(''%'', o.public_id, ''%'') USING utf8mb4) COLLATE utf8mb4_general_ci)');
  CALL ap_rodar('webhook_request_logs', 'api_event_uuid', 'DELETE FROM webhook_request_logs WHERE api_event_uuid IN (SELECT uuid FROM ap_eventos)');
  CALL ap_rodar('api_events', 'uuid', 'DELETE FROM api_events WHERE uuid IN (SELECT uuid FROM ap_eventos)');

  -- 4. transações dos pedidos
  CALL ap_rodar('transaction_items', 'transaction_uuid', 'DELETE FROM transaction_items WHERE transaction_uuid IN (SELECT uuid FROM ap_transacoes)');
  CALL ap_rodar('transactions', 'uuid', 'DELETE FROM transactions WHERE uuid IN (SELECT uuid FROM ap_transacoes)');

  -- 5. rastreio, comprovantes, rota, os pedidos e a carga
  CALL ap_rodar('tracking_statuses', 'tracking_number_uuid', 'DELETE FROM tracking_statuses WHERE tracking_number_uuid IN (SELECT uuid FROM ap_rastreios)');
  CALL ap_rodar('tracking_numbers', 'uuid', 'DELETE FROM tracking_numbers WHERE uuid IN (SELECT uuid FROM ap_rastreios)');
  CALL ap_rodar('proofs', 'uuid', 'DELETE FROM proofs WHERE uuid IN (SELECT uuid FROM ap_comprovantes)');
  CALL ap_rodar('routes', 'order_uuid', 'DELETE FROM routes WHERE order_uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('orders', 'uuid', 'DELETE FROM orders WHERE uuid IN (SELECT uuid FROM ap_pedidos)');
  CALL ap_rodar('entities', 'uuid', 'DELETE FROM entities WHERE uuid IN (SELECT uuid FROM ap_entidades)');
  CALL ap_rodar('waypoints', 'uuid', 'DELETE FROM waypoints WHERE uuid IN (SELECT uuid FROM ap_waypoints)');
  CALL ap_rodar('payloads', 'uuid', 'DELETE FROM payloads WHERE uuid IN (SELECT uuid FROM ap_payloads)');

  -- 6. locais que só esses pedidos usavam (nunca o Local da loja)
  CALL ap_rodar('places', 'uuid', 'DELETE FROM places WHERE uuid IN (SELECT uuid FROM ap_locais)');

  IF p_executar = 1 THEN
    COMMIT;
    INSERT INTO ap_resumo (tabela, comando) VALUES ('-', 'CONFIRMADO (COMMIT)');
  ELSE
    ROLLBACK;
    INSERT INTO ap_resumo (tabela, comando) VALUES ('-', 'REVISÃO: nada foi apagado (ROLLBACK). Para valer, rode com @executar = 1');
  END IF;
  SET FOREIGN_KEY_CHECKS = 1;
END //

DELIMITER ;

SELECT public_id, uuid FROM ap_pedidos;
CALL ap_executar(IFNULL(@executar, 0));
SELECT passo, tabela, linhas, comando FROM ap_resumo ORDER BY passo;

-- conferência: depois do COMMIT, 0 nos três
SELECT (SELECT COUNT(*) FROM orders WHERE FIND_IN_SET(public_id, @pedidos) > 0) AS pedidos_restantes,
       (SELECT COUNT(*) FROM entregas_lancamentos l WHERE l.order_uuid IN (SELECT uuid FROM ap_pedidos)) AS lancamentos_restantes,
       (SELECT COUNT(*) FROM entregas_corridas_finalizadas c WHERE c.order_uuid IN (SELECT uuid FROM ap_pedidos)) AS corridas_restantes;

DROP PROCEDURE IF EXISTS ap_rodar;
DROP PROCEDURE IF EXISTS ap_executar;
