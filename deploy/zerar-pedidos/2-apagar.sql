-- Zerar os pedidos de teste (2026-10-19), passos 5 e 6 do LEIAME.md. Apaga DE VERDADE (DELETE, não o deleted_at do console):
--   - TODOS os pedidos e o que depende deles (carga, paradas, rastreio, comprovantes, posições do pedido, valores
--     congelados, dados iFood e da integração do pedido, eventos iFood, distribuições e ofertas, atividades, comentários,
--     arquivos, avisos gravados, eventos de webhook de pedido, transações) e o api_request_logs inteiro (guarda o corpo
--     das chamadas da API v1, inclusive o código de entrega);
--   - o Financeiro inteiro: lançamentos, pagamentos e recebimentos, faturas (os arquivos dos comprovantes saem no passo 7);
--   - os contatos de clientes dos pedidos (com o login de cliente deles) e os locais que sobram sem uso: tudo que não é
--     o Local de uma loja ou fornecedor, da empresa ou de um contato que fica.
-- Fica: lojas, logins do portal, motoboys, conversas, vínculos iFood e da integração, faixas de km, usuários da central,
-- ciclo de cobrança das lojas, chave Pix dos motoboys e as configurações do Financeiro (data de corte, ciclo padrão).
--
-- Sem @executar = 1 é só REVISÃO: apaga dentro de uma transação, mostra quantas linhas sairiam de cada tabela e desfaz
-- (ROLLBACK). Com @executar = 1, confirma (COMMIT).
--   DB=$(docker ps -q -f name=entregas_database)
--   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t fleetbase' < deploy/zerar-pedidos/2-apagar.sql | tee ~/zerar-revisao.txt
--   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1" fleetbase' < deploy/zerar-pedidos/2-apagar.sql | tee ~/zerar-execucao.txt
--
-- Roda com FOREIGN_KEY_CHECKS = 0 (só nesta sessão): no InnoDB, assim os ON DELETE CASCADE do Fleetbase não disparam
-- (apagar um pedido apagaria o motoboy pelo drivers.current_job_uuid; apagar um local apagaria a loja, o contato ou a
-- empresa). Por isso cada tabela é apagada aqui, uma a uma. Tabela ou coluna que não existe nesta versão é pulada e
-- aparece no resumo como "pulado". Base: deploy/limpeza/2-apagar.sql (2026-10-08).

SET NAMES utf8mb4;

-- resumo em MEMORY: não volta no ROLLBACK da revisão
DROP TEMPORARY TABLE IF EXISTS limpeza_resumo;
CREATE TEMPORARY TABLE limpeza_resumo (passo INT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(64), linhas BIGINT NULL, comando VARCHAR(160)) ENGINE = MEMORY;

-- As tabelas temporárias são criadas com CREATE ... SELECT, sem declarar a coluna: herdam a collation da coluna de origem
-- (o banco do container pode ter outra padrão, e comparar collations diferentes dá "Illegal mix of collations").
-- São montadas ANTES de apagar os pedidos, porque dependem deles.

-- contatos de clientes (a mesma regra do 1-inventario.sql, item 7)
DROP TEMPORARY TABLE IF EXISTS limpeza_contatos;
CREATE TEMPORARY TABLE limpeza_contatos
  SELECT c.uuid FROM contacts c
  WHERE (c.uuid IN (SELECT customer_uuid FROM orders WHERE customer_uuid IS NOT NULL)
      OR c.uuid IN (SELECT facilitator_uuid FROM orders WHERE facilitator_uuid IS NOT NULL)
      OR c.uuid IN (SELECT customer_uuid FROM entities WHERE customer_uuid IS NOT NULL)
      OR c.uuid IN (SELECT customer_uuid FROM waypoints WHERE customer_uuid IS NOT NULL))
    AND c.uuid NOT IN (SELECT contact_uuid FROM vendor_personnels WHERE contact_uuid IS NOT NULL)
    AND (c.user_uuid IS NULL OR (c.user_uuid NOT IN (SELECT user_uuid FROM drivers WHERE user_uuid IS NOT NULL)
                                 AND c.user_uuid NOT IN (SELECT owner_uuid FROM companies WHERE owner_uuid IS NOT NULL)
                                 AND c.user_uuid IN (SELECT uuid FROM users WHERE type = 'customer')));

-- logins desses contatos: só de cliente, nunca o de um contato do portal de uma loja
DROP TEMPORARY TABLE IF EXISTS limpeza_usuarios;
CREATE TEMPORARY TABLE limpeza_usuarios
  SELECT DISTINCT u.uuid FROM users u JOIN contacts c ON c.user_uuid = u.uuid JOIN limpeza_contatos l ON l.uuid = c.uuid
  WHERE u.type = 'customer'
    AND u.uuid NOT IN (SELECT owner_uuid FROM companies WHERE owner_uuid IS NOT NULL)
    AND u.uuid NOT IN (SELECT user_uuid FROM drivers WHERE user_uuid IS NOT NULL)
    AND u.uuid NOT IN (SELECT c2.user_uuid FROM contacts c2 JOIN vendor_personnels vp ON vp.contact_uuid = c2.uuid WHERE c2.user_uuid IS NOT NULL);

DROP TEMPORARY TABLE IF EXISTS limpeza_vinculos;
CREATE TEMPORARY TABLE limpeza_vinculos
  SELECT cu.uuid FROM company_users cu JOIN limpeza_usuarios u ON u.uuid = cu.user_uuid;

-- transações dos pedidos (preenchida dentro do procedimento, com a checagem de tabela)
DROP TEMPORARY TABLE IF EXISTS limpeza_transacoes;
CREATE TEMPORARY TABLE limpeza_transacoes SELECT transaction_uuid AS uuid FROM orders WHERE 1 = 0;

DROP PROCEDURE IF EXISTS limpeza_rodar;
DROP PROCEDURE IF EXISTS limpeza_executar;

DELIMITER //

-- roda o comando se a coluna existe na tabela (versões diferentes do Fleet-Ops); registra as linhas no resumo
CREATE PROCEDURE limpeza_rodar(IN p_tabela VARCHAR(64), IN p_coluna VARCHAR(64), IN p_sql TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND COLUMN_NAME = p_coluna) THEN
    SET @limpeza_sql = p_sql;
    PREPARE comando FROM @limpeza_sql;
    EXECUTE comando;
    SET @limpeza_linhas = ROW_COUNT();
    DEALLOCATE PREPARE comando;
    INSERT INTO limpeza_resumo (tabela, linhas, comando) VALUES (p_tabela, @limpeza_linhas, LEFT(p_sql, 160));
  ELSE
    INSERT INTO limpeza_resumo (tabela, linhas, comando) VALUES (p_tabela, NULL, CONCAT('pulado: sem ', p_tabela, '.', p_coluna));
  END IF;
END //

CREATE PROCEDURE limpeza_executar(IN p_executar INT)
BEGIN
  -- tipos das tabelas polimórficas que pertencem ao pedido ("_" casa com a barra invertida do nome da classe); @col é
  -- trocado pela coluna. Literais: adotam a collation da coluna.
  DECLARE v_tipos TEXT DEFAULT '(@col LIKE ''Fleetbase_FleetOps_Models_Order'' OR @col LIKE ''Fleetbase_FleetOps_Models_Waypoint'' OR @col LIKE ''Fleetbase_FleetOps_Models_Entity'' OR @col LIKE ''Fleetbase_FleetOps_Models_Proof'' OR @col LIKE ''Fleetbase_FleetOps_Models_Payload'' OR @col LIKE ''Fleetbase_FleetOps_Models_TrackingNumber'' OR @col LIKE ''Fleetbase_FleetOps_Models_TrackingStatus'' OR @col LIKE ''Fleetbase_FleetOps_Models_Route'' OR @col IN (''fleet-ops:order'', ''fleet-ops:waypoint'', ''fleet-ops:entity'', ''fleet-ops:proof'', ''fleet-ops:payload''))';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET FOREIGN_KEY_CHECKS = 1;
    RESIGNAL;
  END;

  SET FOREIGN_KEY_CHECKS = 0;
  START TRANSACTION;

  -- 1. pedidos: o que aponta para eles
  CALL limpeza_rodar('drivers', 'current_job_uuid', 'UPDATE drivers SET current_job_uuid = NULL WHERE current_job_uuid IS NOT NULL');
  CALL limpeza_rodar('entregas_ofertas', 'id', 'DELETE FROM entregas_ofertas');
  CALL limpeza_rodar('entregas_distribuicoes', 'id', 'DELETE FROM entregas_distribuicoes');
  CALL limpeza_rodar('entregas_valores_pedido', 'order_uuid', 'DELETE FROM entregas_valores_pedido');
  CALL limpeza_rodar('entregas_ifood_eventos', 'id', 'DELETE FROM entregas_ifood_eventos');
  CALL limpeza_rodar('entregas_ifood_pedidos', 'id', 'DELETE FROM entregas_ifood_pedidos');
  CALL limpeza_rodar('entregas_integracao_pedidos', 'id', 'DELETE FROM entregas_integracao_pedidos');
  CALL limpeza_rodar('positions', 'order_uuid', 'DELETE FROM positions WHERE order_uuid IS NOT NULL');
  CALL limpeza_rodar('geofence_events_log', 'order_uuid', 'DELETE FROM geofence_events_log WHERE order_uuid IS NOT NULL');
  CALL limpeza_rodar('issues', 'order_uuid', 'UPDATE issues SET order_uuid = NULL WHERE order_uuid IS NOT NULL');
  CALL limpeza_rodar('fuel_provider_transactions', 'order_uuid', 'UPDATE fuel_provider_transactions SET order_uuid = NULL WHERE order_uuid IS NOT NULL');

  -- 2. Financeiro inteiro (ficam entregas_cobranca_lojas, entregas_motoboys_financeiro e os settings)
  CALL limpeza_rodar('entregas_lancamentos', 'id', 'DELETE FROM entregas_lancamentos');
  CALL limpeza_rodar('entregas_pagamentos', 'uuid', 'DELETE FROM entregas_pagamentos');
  CALL limpeza_rodar('entregas_faturas', 'uuid', 'DELETE FROM entregas_faturas');

  -- 3. tabelas polimórficas, só os tipos do pedido
  CALL limpeza_rodar('activity', 'subject_type', CONCAT('DELETE FROM activity WHERE ', REPLACE(v_tipos, '@col', 'subject_type')));
  CALL limpeza_rodar('comments', 'subject_type', CONCAT('DELETE FROM comments WHERE ', REPLACE(v_tipos, '@col', 'subject_type')));
  CALL limpeza_rodar('custom_field_values', 'subject_type', CONCAT('DELETE FROM custom_field_values WHERE ', REPLACE(v_tipos, '@col', 'subject_type')));
  CALL limpeza_rodar('files', 'subject_type', CONCAT('DELETE FROM files WHERE ', REPLACE(v_tipos, '@col', 'subject_type')));
  CALL limpeza_rodar('notifications', 'type', 'DELETE FROM notifications WHERE type LIKE ''Fleetbase_FleetOps_Notifications_%'' OR type LIKE ''App_Notifications_Entregas_%''');
  CALL limpeza_rodar('webhook_request_logs', 'api_event_uuid', 'DELETE w FROM webhook_request_logs w JOIN api_events e ON e.uuid = w.api_event_uuid WHERE e.event LIKE ''order.%'' OR e.event LIKE ''waypoint.%'' OR e.event LIKE ''entity.%''');
  CALL limpeza_rodar('api_events', 'event', 'DELETE FROM api_events WHERE event LIKE ''order.%'' OR event LIKE ''waypoint.%'' OR event LIKE ''entity.%''');
  CALL limpeza_rodar('api_request_logs', 'uuid', 'DELETE FROM api_request_logs');

  -- 4. transações dos pedidos (e o ledger, se existir)
  CALL limpeza_rodar('orders', 'transaction_uuid', 'INSERT INTO limpeza_transacoes SELECT transaction_uuid FROM orders WHERE transaction_uuid IS NOT NULL');
  CALL limpeza_rodar('transactions', 'subject_type', 'INSERT INTO limpeza_transacoes SELECT uuid FROM transactions WHERE subject_type LIKE ''Fleetbase_FleetOps_Models_Order'' OR context_type LIKE ''Fleetbase_FleetOps_Models_Order'' OR context_type LIKE ''Fleetbase_FleetOps_Models_PurchaseRate''');
  CALL limpeza_rodar('ledger_invoice_items', 'invoice_uuid', 'DELETE i FROM ledger_invoice_items i JOIN ledger_invoices f ON f.uuid = i.invoice_uuid WHERE f.order_uuid IS NOT NULL OR f.transaction_uuid IN (SELECT uuid FROM limpeza_transacoes)');
  CALL limpeza_rodar('ledger_invoices', 'order_uuid', 'DELETE FROM ledger_invoices WHERE order_uuid IS NOT NULL OR transaction_uuid IN (SELECT uuid FROM limpeza_transacoes)');
  CALL limpeza_rodar('ledger_journals', 'transaction_uuid', 'DELETE FROM ledger_journals WHERE transaction_uuid IN (SELECT uuid FROM limpeza_transacoes)');
  CALL limpeza_rodar('ledger_gateway_transactions', 'transaction_uuid', 'DELETE FROM ledger_gateway_transactions WHERE transaction_uuid IN (SELECT uuid FROM limpeza_transacoes)');
  CALL limpeza_rodar('transaction_items', 'transaction_uuid', 'DELETE FROM transaction_items WHERE transaction_uuid IN (SELECT uuid FROM limpeza_transacoes)');
  CALL limpeza_rodar('transactions', 'uuid', 'DELETE FROM transactions WHERE uuid IN (SELECT uuid FROM limpeza_transacoes)');

  -- 5. os pedidos e as tabelas que só existem para eles
  CALL limpeza_rodar('tracking_statuses', 'uuid', 'DELETE FROM tracking_statuses');
  CALL limpeza_rodar('tracking_numbers', 'uuid', 'DELETE FROM tracking_numbers');
  CALL limpeza_rodar('proofs', 'uuid', 'DELETE FROM proofs');
  CALL limpeza_rodar('routes', 'uuid', 'DELETE FROM routes');
  CALL limpeza_rodar('manifest_stops', 'uuid', 'DELETE FROM manifest_stops');
  CALL limpeza_rodar('manifests', 'uuid', 'DELETE FROM manifests');
  CALL limpeza_rodar('orders', 'uuid', 'DELETE FROM orders');
  CALL limpeza_rodar('service_quote_items', 'uuid', 'DELETE FROM service_quote_items');
  CALL limpeza_rodar('purchase_rates', 'uuid', 'DELETE FROM purchase_rates');
  CALL limpeza_rodar('service_quotes', 'uuid', 'DELETE FROM service_quotes');
  CALL limpeza_rodar('entities', 'uuid', 'DELETE FROM entities');
  CALL limpeza_rodar('waypoints', 'uuid', 'DELETE FROM waypoints');
  CALL limpeza_rodar('payloads', 'uuid', 'DELETE FROM payloads');

  -- 6. contatos de clientes e os logins de cliente deles
  CALL limpeza_rodar('personal_access_tokens', 'tokenable_id', 'DELETE FROM personal_access_tokens WHERE tokenable_id IN (SELECT uuid FROM limpeza_usuarios)');
  CALL limpeza_rodar('user_devices', 'user_uuid', 'DELETE FROM user_devices WHERE user_uuid IN (SELECT uuid FROM limpeza_usuarios)');
  CALL limpeza_rodar('group_users', 'user_uuid', 'DELETE FROM group_users WHERE user_uuid IN (SELECT uuid FROM limpeza_usuarios)');
  CALL limpeza_rodar('notifications', 'notifiable_id', 'DELETE FROM notifications WHERE notifiable_id IN (SELECT uuid FROM limpeza_usuarios)');
  CALL limpeza_rodar('settings', 'key', 'DELETE s FROM settings s JOIN limpeza_usuarios u ON s.`key` LIKE CONCAT(''user.'', u.uuid, ''.%'')');
  CALL limpeza_rodar('model_has_roles', 'model_uuid', 'DELETE FROM model_has_roles WHERE model_uuid IN (SELECT uuid FROM limpeza_vinculos)');
  CALL limpeza_rodar('model_has_roles', 'model_uuid', 'DELETE FROM model_has_roles WHERE model_uuid IN (SELECT uuid FROM limpeza_usuarios)');
  CALL limpeza_rodar('model_has_permissions', 'model_uuid', 'DELETE FROM model_has_permissions WHERE model_uuid IN (SELECT uuid FROM limpeza_vinculos)');
  CALL limpeza_rodar('model_has_permissions', 'model_uuid', 'DELETE FROM model_has_permissions WHERE model_uuid IN (SELECT uuid FROM limpeza_usuarios)');
  CALL limpeza_rodar('company_users', 'uuid', 'DELETE FROM company_users WHERE uuid IN (SELECT uuid FROM limpeza_vinculos)');
  CALL limpeza_rodar('users', 'uuid', 'DELETE FROM users WHERE uuid IN (SELECT uuid FROM limpeza_usuarios)');
  CALL limpeza_rodar('contacts', 'uuid', 'DELETE FROM contacts WHERE uuid IN (SELECT uuid FROM limpeza_contatos)');

  -- 7. locais sem uso (por último: depende dos contatos que ficaram)
  CALL limpeza_rodar('places', 'uuid', 'DELETE FROM places WHERE uuid NOT IN (SELECT place_uuid FROM vendors WHERE place_uuid IS NOT NULL) AND uuid NOT IN (SELECT place_uuid FROM companies WHERE place_uuid IS NOT NULL) AND uuid NOT IN (SELECT place_uuid FROM contacts WHERE place_uuid IS NOT NULL)');

  IF p_executar = 1 THEN
    COMMIT;
    INSERT INTO limpeza_resumo (tabela, comando) VALUES ('-', 'CONFIRMADO (COMMIT)');
  ELSE
    ROLLBACK;
    INSERT INTO limpeza_resumo (tabela, comando) VALUES ('-', 'REVISÃO: nada foi apagado (ROLLBACK). Para valer, rode com SET @executar = 1');
  END IF;
  SET FOREIGN_KEY_CHECKS = 1;
END //

DELIMITER ;

CALL limpeza_executar(IFNULL(@executar, 0));

SELECT passo, tabela, linhas, comando FROM limpeza_resumo ORDER BY passo;

-- conferência (na revisão, mostra o estado de antes; depois do COMMIT, tudo 0 menos motoboys, lojas e vínculos)
SELECT (SELECT COUNT(*) FROM orders) AS pedidos, (SELECT COUNT(*) FROM payloads) AS cargas, (SELECT COUNT(*) FROM waypoints) AS paradas,
       (SELECT COUNT(*) FROM entregas_ifood_pedidos) AS pedidos_ifood, (SELECT COUNT(*) FROM entregas_integracao_pedidos) AS pedidos_integracao,
       (SELECT COUNT(*) FROM entregas_distribuicoes) AS distribuicoes, (SELECT COUNT(*) FROM entregas_lancamentos) AS lancamentos,
       (SELECT COUNT(*) FROM entregas_pagamentos) AS pagamentos, (SELECT COUNT(*) FROM entregas_faturas) AS faturas;
SELECT (SELECT COUNT(*) FROM drivers) AS motoboys, (SELECT COUNT(*) FROM vendors) AS fornecedores, (SELECT COUNT(*) FROM places) AS locais,
       (SELECT COUNT(*) FROM entregas_ifood_lojas WHERE situacao = 'vinculada') AS lojas_ifood_vinculadas,
       (SELECT COUNT(*) FROM entregas_integracao_lojas) AS lojas_integracao;

DROP PROCEDURE IF EXISTS limpeza_rodar;
DROP PROCEDURE IF EXISTS limpeza_executar;
