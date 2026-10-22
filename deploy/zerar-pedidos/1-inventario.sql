-- Zerar os pedidos de teste (2026-10-19), passo 2 do LEIAME.md: inventário. Só leitura; rode ANTES do 2-apagar.sql e
-- guarde a saída.
--
--   DB=$(docker ps -q -f name=entregas_database)
--   docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --force fleetbase' < deploy/zerar-pedidos/1-inventario.sql | tee ~/zerar-inventario.txt
--
-- As regras de "contato de cliente" e de "local que sai" são as mesmas do 2-apagar.sql.

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
UNION ALL SELECT 'entregas_integracao_pedidos', COUNT(*) FROM entregas_integracao_pedidos
UNION ALL SELECT 'entregas_distribuicoes', COUNT(*) FROM entregas_distribuicoes
UNION ALL SELECT 'entregas_ofertas', COUNT(*) FROM entregas_ofertas
UNION ALL SELECT 'drivers com current_job', COUNT(*) FROM drivers WHERE current_job_uuid IS NOT NULL
UNION ALL SELECT 'api_request_logs (todos)', COUNT(*) FROM api_request_logs
UNION ALL SELECT 'api_events de pedido', COUNT(*) FROM api_events WHERE event LIKE 'order.%' OR event LIKE 'waypoint.%' OR event LIKE 'entity.%';

-- 4) Financeiro: TUDO sai (lançamentos, pagamentos com comprovante, faturas). Ficam o ciclo das lojas, a chave Pix dos
--    motoboys e as configurações (data de corte, ciclo padrão).
SELECT conta_tipo, tipo, COUNT(*) AS lancamentos, SUM(valor) AS soma FROM entregas_lancamentos GROUP BY conta_tipo, tipo ORDER BY conta_tipo, tipo;
SELECT 'entregas_pagamentos' AS tabela, COUNT(*) AS linhas, SUM(comprovante_path IS NOT NULL) AS com_comprovante FROM entregas_pagamentos
UNION ALL SELECT 'entregas_faturas', COUNT(*), NULL FROM entregas_faturas
UNION ALL SELECT 'entregas_cobranca_lojas (fica)', COUNT(*), NULL FROM entregas_cobranca_lojas
UNION ALL SELECT 'entregas_motoboys_financeiro (fica)', COUNT(*), NULL FROM entregas_motoboys_financeiro;
-- corridas do sistema (relatório do dono) e preços das centrais: ficam. Consulta separada: antes do deploy da API com a
-- migration as tabelas não existem, e o erro (o --force segue) não leva junto a contagem acima.
SELECT 'entregas_corridas_finalizadas (fica)' AS tabela, COUNT(*) AS linhas FROM entregas_corridas_finalizadas
UNION ALL SELECT 'entregas_precos_centrais (fica)', COUNT(*) FROM entregas_precos_centrais;

-- 5) tipos gravados nas tabelas polimórficas: os de pedido (Order, Waypoint, Entity, Proof, Payload, TrackingNumber,
--    TrackingStatus, Route do Fleet-Ops) saem; os outros ficam
SELECT 'activity' AS tabela, subject_type, COUNT(*) AS linhas FROM activity GROUP BY subject_type
UNION ALL SELECT 'comments', subject_type, COUNT(*) FROM comments GROUP BY subject_type
UNION ALL SELECT 'files', subject_type, COUNT(*) FROM files GROUP BY subject_type
UNION ALL SELECT 'custom_field_values', subject_type, COUNT(*) FROM custom_field_values GROUP BY subject_type
ORDER BY tabela, linhas DESC;

-- 6) avisos gravados (notifications): saem os do Fleet-Ops e os nossos (App\Notifications\Entregas)
SELECT type, COUNT(*) AS linhas FROM notifications GROUP BY type ORDER BY linhas DESC;

-- 7) contatos de clientes que saem: cliente de um pedido, carga ou parada, fora do portal de uma loja (vendor_personnels),
--    sem ser motoboy nem dono da empresa, e sem login ou com login de cliente. Esperado: poucos ou nenhum (o cliente dos
--    pedidos do iFood e da integração é a loja). Algum que precisa ficar? Pare e me mande o public_id.
SELECT c.public_id, c.name, c.email, c.phone, c.type, c.created_at
FROM contacts c
WHERE (c.uuid IN (SELECT customer_uuid FROM orders WHERE customer_uuid IS NOT NULL)
    OR c.uuid IN (SELECT facilitator_uuid FROM orders WHERE facilitator_uuid IS NOT NULL)
    OR c.uuid IN (SELECT customer_uuid FROM entities WHERE customer_uuid IS NOT NULL)
    OR c.uuid IN (SELECT customer_uuid FROM waypoints WHERE customer_uuid IS NOT NULL))
  AND c.uuid NOT IN (SELECT contact_uuid FROM vendor_personnels WHERE contact_uuid IS NOT NULL)
  AND (c.user_uuid IS NULL OR (c.user_uuid NOT IN (SELECT user_uuid FROM drivers WHERE user_uuid IS NOT NULL)
                               AND c.user_uuid NOT IN (SELECT owner_uuid FROM companies WHERE owner_uuid IS NOT NULL)
                               AND c.user_uuid IN (SELECT uuid FROM users WHERE type = 'customer')))
ORDER BY c.created_at;

-- 8) locais que FICAM: o Local de cada loja ou fornecedor, o da empresa e o dos contatos que ficam
SELECT p.public_id, p.name, p.street1, p.city, 'local da loja' AS por_que FROM places p JOIN vendors v ON v.place_uuid = p.uuid
UNION ALL
SELECT p.public_id, p.name, p.street1, p.city, 'local da empresa' FROM places p JOIN companies co ON co.place_uuid = p.uuid
UNION ALL
SELECT p.public_id, p.name, p.street1, p.city, 'local de contato' FROM places p JOIN contacts c ON c.place_uuid = p.uuid;

-- 9) locais que SAEM: todos os outros (endereços de entrega dos pedidos, destinos salvos). O local de um contato do (7)
--    sai junto. Tem algum que precisa ficar? Pare e me mande o public_id.
SELECT COUNT(*) AS locais_que_saem FROM places p
WHERE p.uuid NOT IN (SELECT place_uuid FROM vendors WHERE place_uuid IS NOT NULL)
  AND p.uuid NOT IN (SELECT place_uuid FROM companies WHERE place_uuid IS NOT NULL)
  AND p.uuid NOT IN (SELECT place_uuid FROM contacts WHERE place_uuid IS NOT NULL);

SELECT p.public_id, p.name, p.street1, p.city, p.owner_type, p.created_at FROM places p
WHERE p.uuid NOT IN (SELECT place_uuid FROM vendors WHERE place_uuid IS NOT NULL)
  AND p.uuid NOT IN (SELECT place_uuid FROM companies WHERE place_uuid IS NOT NULL)
  AND p.uuid NOT IN (SELECT place_uuid FROM contacts WHERE place_uuid IS NOT NULL)
ORDER BY p.created_at;

-- 10) o que fica: motoboys, lojas, vínculos iFood e integrações
SELECT (SELECT COUNT(*) FROM drivers) AS motoboys, (SELECT COUNT(*) FROM vendors) AS fornecedores,
       (SELECT COUNT(*) FROM entregas_ifood_lojas WHERE situacao = 'vinculada') AS lojas_ifood_vinculadas,
       (SELECT COUNT(*) FROM entregas_integracao_lojas) AS lojas_integracao;
