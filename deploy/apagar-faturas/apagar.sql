-- Apagar faturas de teste de uma loja, "como se não tivessem existido" (2026-10-23). Molde: deploy/apagar-pedidos/apagar.sql.
-- Apaga DE VERDADE (DELETE), fora da regra "nada se apaga" do Financeiro: só para fatura de teste. Para uma fatura real,
-- use o Cancelar do console (Financeiro → Faturas), que deixa o registro.
--
-- Escolha: faturas da loja cujo nome bate com @loja (LIKE; padrão 'Terra%o Pizza%') e cujo valor está em @valores
-- (padrão '294.50,37.50'; ponto antes dos centavos). Valor = o total gravado na emissão ou, no rascunho (sem total), a
-- soma dos itens. Rascunho, emitida e cancelada entram.
-- Para (sem apagar nada) se não achar exatamente uma fatura por valor (nenhuma, ou duas com o mesmo valor).
--
-- Sai: as faturas e os ajustes feitos DENTRO delas (Ajuste e "Não cobrar" da tela da fatura = tipo ajuste criado depois
-- da fatura) com os estornos desses ajustes. Volta a "sem fatura": as corridas e os outros lançamentos da loja que
-- estavam nelas (entram no próximo rascunho, como no Cancelar). Fica: recebimentos (não são ligados à fatura; o que foi
-- recebido continua como crédito da loja), corridas, pedidos. O número da emitida apagada fica livre: se for o maior,
-- a próxima emissão o reaproveita. E-mail já enviado à loja não volta.
--
-- Sem @executar = 1 é só REVISÃO: apaga dentro de uma transação, mostra quantas linhas sairiam e desfaz (ROLLBACK).
--   DB=$(docker ps -q -f name=entregas_database)
--   docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -t fleetbase' < deploy/apagar-faturas/apagar.sql
--   execução: o mesmo comando com mysql -uroot -t --init-command="SET @executar = 1" fleetbase
--   outra loja ou valores: --init-command="SET @loja = '\''Nome%'\'', @valores = '\''10.00,20.50'\''"

SET NAMES utf8mb4;
SET time_zone = '-03:00';
SET @loja = IFNULL(@loja, 'Terra%o Pizza%');
SET @valores = IFNULL(@valores, '294.50,37.50');

DROP TEMPORARY TABLE IF EXISTS af_resumo;
CREATE TEMPORARY TABLE af_resumo (passo INT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(64), linhas BIGINT NULL, comando VARCHAR(160)) ENGINE = MEMORY;

-- as faturas da loja, com o valor (total gravado ou soma dos itens)
DROP TEMPORARY TABLE IF EXISTS af_faturas;
CREATE TEMPORARY TABLE af_faturas
SELECT f.id, f.uuid, f.numero, f.situacao, f.periodo_inicio, f.periodo_fim, f.created_at, v.name AS loja,
       CAST(COALESCE(f.total, (SELECT SUM(l.valor) FROM entregas_lancamentos l WHERE l.fatura_uuid = f.uuid), 0) AS DECIMAL(10, 2)) AS valor
FROM entregas_faturas f
JOIN vendors v ON v.uuid = f.vendor_uuid
WHERE v.name LIKE @loja
  AND FIND_IN_SET(CAST(CAST(COALESCE(f.total, (SELECT SUM(l.valor) FROM entregas_lancamentos l WHERE l.fatura_uuid = f.uuid), 0) AS DECIMAL(10, 2)) AS CHAR), REPLACE(@valores, ' ', '')) > 0;

-- ajustes feitos dentro da fatura (criados depois dela) e os estornos deles
-- (o MySQL não deixa usar a mesma tabela temporária duas vezes numa consulta: daí as cópias af_*_2)
DROP TEMPORARY TABLE IF EXISTS af_ajustes;
CREATE TEMPORARY TABLE af_ajustes
SELECT l.id FROM entregas_lancamentos l JOIN af_faturas f ON f.uuid = l.fatura_uuid
WHERE l.tipo = 'ajuste' AND l.created_at >= f.created_at;
DROP TEMPORARY TABLE IF EXISTS af_ajustes_2;
CREATE TEMPORARY TABLE af_ajustes_2 SELECT id FROM af_ajustes;
INSERT INTO af_ajustes SELECT e.id FROM entregas_lancamentos e WHERE e.estorno_de IN (SELECT id FROM af_ajustes_2);
DROP TEMPORARY TABLE IF EXISTS af_faturas_2;
CREATE TEMPORARY TABLE af_faturas_2 SELECT uuid FROM af_faturas;

DROP PROCEDURE IF EXISTS af_rodar;
DROP PROCEDURE IF EXISTS af_executar;

DELIMITER //

CREATE PROCEDURE af_rodar(IN p_tabela VARCHAR(64), IN p_sql TEXT)
BEGIN
  SET @af_sql = p_sql;
  PREPARE comando FROM @af_sql;
  EXECUTE comando;
  SET @af_linhas = ROW_COUNT();
  DEALLOCATE PREPARE comando;
  INSERT INTO af_resumo (tabela, linhas, comando) VALUES (p_tabela, @af_linhas, LEFT(p_sql, 160));
END //

CREATE PROCEDURE af_executar(IN p_executar INT)
BEGIN
  DECLARE v_pedidas INT;
  DECLARE v_achadas INT;
  DECLARE v_distintas INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  SET v_pedidas = 1 + LENGTH(REPLACE(@valores, ' ', '')) - LENGTH(REPLACE(REPLACE(@valores, ' ', ''), ',', ''));
  SELECT COUNT(*), COUNT(DISTINCT valor) INTO v_achadas, v_distintas FROM af_faturas;
  IF v_achadas = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nenhuma fatura da loja com esses valores: nada a fazer';
  END IF;
  IF v_achadas <> v_pedidas OR v_distintas <> v_pedidas THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não achei exatamente uma fatura por valor (veja a lista acima): nada foi apagado';
  END IF;

  START TRANSACTION;
  CALL af_rodar('entregas_lancamentos', 'DELETE FROM entregas_lancamentos WHERE id IN (SELECT id FROM af_ajustes)');
  CALL af_rodar('entregas_lancamentos', 'UPDATE entregas_lancamentos SET fatura_uuid = NULL WHERE fatura_uuid IN (SELECT uuid FROM af_faturas)');
  CALL af_rodar('entregas_faturas', 'DELETE FROM entregas_faturas WHERE id IN (SELECT id FROM af_faturas)');

  IF p_executar = 1 THEN
    COMMIT;
    INSERT INTO af_resumo (tabela, comando) VALUES ('-', 'CONFIRMADO (COMMIT)');
  ELSE
    ROLLBACK;
    INSERT INTO af_resumo (tabela, comando) VALUES ('-', 'REVISÃO: nada foi apagado (ROLLBACK). Para valer, rode com @executar = 1');
  END IF;
END //

DELIMITER ;

SELECT loja, uuid, IF(numero IS NULL, '-', CONCAT('FAT-', LPAD(numero, 4, '0'))) AS numero, situacao, periodo_inicio, periodo_fim, valor,
       (SELECT COUNT(*) FROM entregas_lancamentos l WHERE l.fatura_uuid = af_faturas.uuid) AS itens
FROM af_faturas ORDER BY valor DESC;
SELECT l.id, l.tipo, l.valor, l.motivo, l.created_at FROM entregas_lancamentos l WHERE l.id IN (SELECT id FROM af_ajustes);

CALL af_executar(IFNULL(@executar, 0));
SELECT passo, tabela, linhas, comando FROM af_resumo ORDER BY passo;

-- conferência: depois do COMMIT, 0 nos dois
SELECT (SELECT COUNT(*) FROM entregas_faturas WHERE uuid IN (SELECT uuid FROM af_faturas)) AS faturas_restantes,
       (SELECT COUNT(*) FROM entregas_lancamentos WHERE fatura_uuid IN (SELECT uuid FROM af_faturas_2)) AS lancamentos_ainda_ligados;

DROP PROCEDURE IF EXISTS af_rodar;
DROP PROCEDURE IF EXISTS af_executar;
