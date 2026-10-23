-- Inventário (só leitura) das corridas de um motoboy, para apagar pelo apagar.sql (2026-10-23). Não muda nada.
-- @motoboy é um pedaço do nome do User do motoboy (LIKE, sem olhar caixa nem acento). Os pedidos excluídos (deleted_at) entram.
--   DB=$(docker ps -q -f name=entregas_database)
--   docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -t --force --init-command="SET @motoboy = '\''junior'\''" fleetbase' < deploy/apagar-pedidos/inventario-motoboy.sql

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET SESSION group_concat_max_len = 1000000;

DROP TEMPORARY TABLE IF EXISTS im_motoboys;
CREATE TEMPORARY TABLE im_motoboys
  SELECT d.uuid, d.public_id, d.company_uuid, u.name, u.phone, d.deleted_at
  FROM drivers d JOIN users u ON u.uuid = d.user_uuid
  WHERE u.name COLLATE utf8mb4_general_ci LIKE CONVERT(CONCAT('%', @motoboy, '%') USING utf8mb4) COLLATE utf8mb4_general_ci;

SELECT '(1) motoboys com esse nome: confira que é só o Junior de teste' AS passo;
SELECT m.public_id, m.name, m.phone, c.name AS central, m.deleted_at,
       (SELECT COUNT(*) FROM orders o WHERE o.driver_assigned_uuid = m.uuid) AS pedidos
FROM im_motoboys m LEFT JOIN companies c ON c.uuid = m.company_uuid;

DROP TEMPORARY TABLE IF EXISTS im_pedidos;
CREATE TEMPORARY TABLE im_pedidos
  SELECT o.uuid, o.public_id, o.internal_id, o.status, o.customer_uuid, o.created_at, o.deleted_at
  FROM orders o WHERE o.driver_assigned_uuid IN (SELECT uuid FROM im_motoboys);

SELECT '(2) pedidos do motoboy, por loja e status' AS passo;
SELECT COALESCE(v.name, '(cliente sem loja)') AS loja, p.status, COUNT(*) AS pedidos,
       MIN(p.created_at) AS primeiro, MAX(p.created_at) AS ultimo, SUM(p.deleted_at IS NOT NULL) AS ja_excluidos
FROM im_pedidos p LEFT JOIN vendors v ON v.uuid = p.customer_uuid
GROUP BY loja, p.status ORDER BY loja, p.status;

SELECT '(3) lista dos pedidos' AS passo;
SELECT p.public_id, p.internal_id, p.status, COALESCE(v.name, '-') AS loja, p.created_at, p.deleted_at
FROM im_pedidos p LEFT JOIN vendors v ON v.uuid = p.customer_uuid ORDER BY p.created_at;

SELECT '(4) Financeiro desses pedidos (corridas do motoboy e da loja). Com fatura ou pagamento, o apagar.sql para' AS passo;
SELECT l.conta_tipo, l.tipo, COUNT(*) AS lancamentos, SUM(l.valor) AS total,
       SUM(l.fatura_uuid IS NOT NULL) AS em_fatura, SUM(l.pagamento_uuid IS NOT NULL) AS em_pagamento
FROM entregas_lancamentos l WHERE l.order_uuid IN (SELECT uuid FROM im_pedidos)
GROUP BY l.conta_tipo, l.tipo;

SELECT '(5) faturas e pagamentos que têm lançamento desses pedidos' AS passo;
SELECT 'fatura' AS o_que, f.uuid, f.numero, COALESCE(v.name, '-') AS conta, f.situacao, f.total AS valor, f.periodo_inicio AS de, f.periodo_fim AS ate
FROM entregas_faturas f LEFT JOIN vendors v ON v.uuid = f.vendor_uuid
WHERE f.uuid IN (SELECT fatura_uuid FROM entregas_lancamentos WHERE order_uuid IN (SELECT uuid FROM im_pedidos) AND fatura_uuid IS NOT NULL);
-- separado: o MySQL não deixa citar a mesma tabela temporária duas vezes no mesmo comando (nem num UNION)
SELECT 'pagamento' AS o_que, g.uuid, g.conta_tipo AS conta, IF(g.estornado_em IS NULL, 'ativo', 'estornado') AS situacao, g.valor, g.data
FROM entregas_pagamentos g
WHERE g.uuid IN (SELECT pagamento_uuid FROM entregas_lancamentos WHERE order_uuid IN (SELECT uuid FROM im_pedidos) AND pagamento_uuid IS NOT NULL);

SELECT '(6) lançamentos do motoboy SEM pedido (ajuste, bônus, diária, pagamento...): o apagar.sql não mexe' AS passo;
SELECT l.tipo, COUNT(*) AS lancamentos, SUM(l.valor) AS total, MIN(l.data_referencia) AS de, MAX(l.data_referencia) AS ate
FROM entregas_lancamentos l
WHERE l.conta_tipo = 'motoboy' AND l.conta_uuid IN (SELECT uuid FROM im_motoboys) AND l.order_uuid IS NULL
GROUP BY l.tipo;
SELECT 'pagamentos ao motoboy' AS o_que, COUNT(*) AS quantos, SUM(valor) AS total
FROM entregas_pagamentos WHERE conta_tipo = 'motoboy' AND conta_uuid IN (SELECT uuid FROM im_motoboys);

SELECT '(7) corridas do sistema (relatório do dono) desses pedidos' AS passo;
SELECT COUNT(*) AS corridas_finalizadas FROM entregas_corridas_finalizadas WHERE order_uuid IN (SELECT uuid FROM im_pedidos);

SELECT '(8) a lista para o apagar.sql (@pedidos)' AS passo;
SELECT COUNT(*) AS pedidos, GROUP_CONCAT(public_id ORDER BY created_at SEPARATOR ',') AS pedidos_para_apagar FROM im_pedidos;
