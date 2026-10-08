-- Limpeza dos testes, passo 2 do LEIAME.md: inventário. Só leitura; rode ANTES do 2-apagar.sql e guarde a saída.
--
--   DB=$(docker ps -q -f name=entregas_database)
--   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --force fleetbase' < deploy/limpeza/1-inventario.sql | tee ~/limpeza-inventario.txt
--
-- Lojas do teste de isolamento = Fornecedor de loja (type customer) com "teste" no nome, menos o Terraço Pizza Bar
-- (vendor_8jifurzqqg), que fica com o vínculo iFood para a homologação. A mesma regra está no 2-apagar.sql.

-- 1) pedidos que saem (TODOS), por status, contando os já apagados pelo console (deleted_at)
SELECT status, COUNT(*) AS pedidos, SUM(deleted_at IS NOT NULL) AS ja_apagados, MIN(created_at) AS primeiro, MAX(created_at) AS ultimo
FROM orders GROUP BY status ORDER BY pedidos DESC;

-- 2) pedidos por loja (cliente do pedido). Confira que não há pedido que precise ficar: TODOS saem.
SELECT COALESCE(v.name, c.name, '(sem loja)') AS loja, COUNT(*) AS pedidos
FROM orders o LEFT JOIN vendors v ON v.uuid = o.customer_uuid LEFT JOIN contacts c ON c.uuid = o.customer_uuid
GROUP BY loja ORDER BY pedidos DESC;

-- 3) dados ligados aos pedidos (tudo isto sai)
SELECT 'payloads' AS tabela, COUNT(*) AS linhas FROM payloads
UNION ALL SELECT 'waypoints', COUNT(*) FROM waypoints
UNION ALL SELECT 'entities', COUNT(*) FROM entities
UNION ALL SELECT 'tracking_numbers', COUNT(*) FROM tracking_numbers
UNION ALL SELECT 'tracking_statuses', COUNT(*) FROM tracking_statuses
UNION ALL SELECT 'proofs', COUNT(*) FROM proofs
UNION ALL SELECT 'positions com pedido', COUNT(*) FROM positions WHERE order_uuid IS NOT NULL
UNION ALL SELECT 'entregas_valores_pedido', COUNT(*) FROM entregas_valores_pedido
UNION ALL SELECT 'entregas_ifood_pedidos', COUNT(*) FROM entregas_ifood_pedidos
UNION ALL SELECT 'entregas_ifood_eventos', COUNT(*) FROM entregas_ifood_eventos
UNION ALL SELECT 'entregas_distribuicoes', COUNT(*) FROM entregas_distribuicoes
UNION ALL SELECT 'entregas_ofertas', COUNT(*) FROM entregas_ofertas
UNION ALL SELECT 'drivers com current_job', COUNT(*) FROM drivers WHERE current_job_uuid IS NOT NULL
UNION ALL SELECT 'api_request_logs (todos)', COUNT(*) FROM api_request_logs
UNION ALL SELECT 'api_events de pedido', COUNT(*) FROM api_events WHERE event LIKE 'order.%' OR event LIKE 'waypoint.%' OR event LIKE 'entity.%';

-- 4) tipos gravados nas tabelas polimórficas: os de pedido (Order, Waypoint, Entity, Proof, Payload, TrackingNumber,
--    TrackingStatus, Route do Fleet-Ops) saem; os outros ficam
SELECT 'activity' AS tabela, subject_type, COUNT(*) AS linhas FROM activity GROUP BY subject_type
UNION ALL SELECT 'comments', subject_type, COUNT(*) FROM comments GROUP BY subject_type
UNION ALL SELECT 'files', subject_type, COUNT(*) FROM files GROUP BY subject_type
UNION ALL SELECT 'custom_field_values', subject_type, COUNT(*) FROM custom_field_values GROUP BY subject_type
ORDER BY tabela, linhas DESC;

-- 5) avisos gravados (notifications): saem os do Fleet-Ops e os nossos (App\Notifications\Entregas)
SELECT type, COUNT(*) AS linhas FROM notifications GROUP BY type ORDER BY linhas DESC;

-- 6) lojas do teste de isolamento que saem (com Local, usuários e conversas)
SELECT v.public_id, v.name, v.place_uuid, (SELECT COUNT(*) FROM vendor_personnels vp WHERE vp.vendor_uuid = v.uuid) AS usuarios
FROM vendors v
WHERE v.type = 'customer' AND LOWER(v.name) LIKE '%teste%' AND v.public_id <> 'vendor_8jifurzqqg';

-- 6b) os logins dessas lojas que saem (esperado: todos type customer)
SELECT u.public_id, u.name, u.email, u.type
FROM users u
JOIN contacts c ON c.user_uuid = u.uuid
JOIN vendor_personnels vp ON vp.contact_uuid = c.uuid
JOIN vendors v ON v.uuid = vp.vendor_uuid
WHERE v.type = 'customer' AND LOWER(v.name) LIKE '%teste%' AND v.public_id <> 'vendor_8jifurzqqg';

-- 6c) TEM DE DAR 0 nas três colunas: motoboy ligado a uma loja de teste, login de loja de teste que é dono da empresa e
--     login que não é de loja. Deu mais que 0? Pare e me mande a saída.
SELECT
  (SELECT COUNT(*) FROM drivers d JOIN vendors v ON v.uuid = d.vendor_uuid
     WHERE v.type = 'customer' AND LOWER(v.name) LIKE '%teste%' AND v.public_id <> 'vendor_8jifurzqqg') AS motoboys_da_loja,
  (SELECT COUNT(*) FROM companies co JOIN contacts c ON c.user_uuid = co.owner_uuid JOIN vendor_personnels vp ON vp.contact_uuid = c.uuid JOIN vendors v ON v.uuid = vp.vendor_uuid
     WHERE v.type = 'customer' AND LOWER(v.name) LIKE '%teste%' AND v.public_id <> 'vendor_8jifurzqqg') AS dono_da_empresa,
  (SELECT COUNT(*) FROM users u JOIN contacts c ON c.user_uuid = u.uuid JOIN vendor_personnels vp ON vp.contact_uuid = c.uuid JOIN vendors v ON v.uuid = vp.vendor_uuid
     WHERE v.type = 'customer' AND LOWER(v.name) LIKE '%teste%' AND v.public_id <> 'vendor_8jifurzqqg' AND u.type <> 'customer') AS login_que_nao_e_de_loja;

-- 7) conversas de teste que saem (loja de teste ou loja do isolamento × motoboy)
SELECT ch.public_id, ch.name, ch.created_at,
       (SELECT COUNT(*) FROM chat_messages m WHERE m.chat_channel_uuid = ch.uuid) AS mensagens
FROM chat_channels ch
JOIN vendors v ON CAST(v.uuid AS BINARY) = CAST(CASE WHEN JSON_VALID(ch.meta) THEN JSON_UNQUOTE(JSON_EXTRACT(ch.meta, '$.entregas_conversa_loja')) END AS BINARY)
WHERE v.public_id = 'vendor_8jifurzqqg' OR (v.type = 'customer' AND LOWER(v.name) LIKE '%teste%');

-- 8) locais que FICAM: o Local de cada loja ou fornecedor, o da empresa e o dos contatos que ficam
SELECT p.public_id, p.name, p.street1, p.city, 'local da loja' AS por_que
FROM places p JOIN vendors v ON v.place_uuid = p.uuid
WHERE NOT (v.type = 'customer' AND LOWER(v.name) LIKE '%teste%' AND v.public_id <> 'vendor_8jifurzqqg')
UNION ALL
SELECT p.public_id, p.name, p.street1, p.city, 'local da empresa' FROM places p JOIN companies co ON co.place_uuid = p.uuid
UNION ALL
SELECT p.public_id, p.name, p.street1, p.city, 'local de contato' FROM places p JOIN contacts c ON c.place_uuid = p.uuid;

-- 9) locais que SAEM: todos os outros (endereços de entrega, destinos salvos, "RUA TESTE", Locais antigos das lojas).
--    Tem algum que precisa ficar? Pare e me mande o public_id.
SELECT COUNT(*) AS locais_que_saem FROM places p
WHERE p.uuid NOT IN (SELECT place_uuid FROM vendors WHERE place_uuid IS NOT NULL
                       AND NOT (type = 'customer' AND LOWER(name) LIKE '%teste%' AND public_id <> 'vendor_8jifurzqqg'))
  AND p.uuid NOT IN (SELECT place_uuid FROM companies WHERE place_uuid IS NOT NULL)
  AND p.uuid NOT IN (SELECT place_uuid FROM contacts WHERE place_uuid IS NOT NULL);

SELECT p.public_id, p.name, p.street1, p.city, p.owner_type, p.created_at FROM places p
WHERE p.uuid NOT IN (SELECT place_uuid FROM vendors WHERE place_uuid IS NOT NULL
                       AND NOT (type = 'customer' AND LOWER(name) LIKE '%teste%' AND public_id <> 'vendor_8jifurzqqg'))
  AND p.uuid NOT IN (SELECT place_uuid FROM companies WHERE place_uuid IS NOT NULL)
  AND p.uuid NOT IN (SELECT place_uuid FROM contacts WHERE place_uuid IS NOT NULL)
ORDER BY p.created_at;

-- 10) o que fica da integração iFood (esperado: o Terraço Pizza Bar vinculado)
SELECT v.name, l.merchant_id, l.nome_ifood, l.situacao FROM entregas_ifood_lojas l LEFT JOIN vendors v ON v.uuid = l.vendor_uuid;
