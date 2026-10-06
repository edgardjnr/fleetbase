-- Fuso (horário de Brasília), passo 7 do LEIAME.md: desloca 3 h para trás as colunas DATETIME (e o users.last_login,
-- VARCHAR) dos schemas fleetbase, fleetbase_sandbox e fleetbase_storefront (este só se existir). Elas guardam a hora
-- "de parede" sem fuso e foram gravadas em UTC; com a sessão em -03:00, passam a ser lidas como hora de Brasília. As
-- TIMESTAMP não mudam (o MySQL guarda em UTC e converte na leitura).
--
-- SÓ com application, queue e scheduler em 0 réplicas e depois do backup (passos 5 e 6 do LEIAME.md).
--
--   DB=$(docker ps -q -f name=entregas_database)
--   1) revisar (padrão, sem variável: não altera nada; lista cada UPDATE e quantas linhas ele mexe):
--      docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t' < deploy/fuso/2-converter.sql
--   2) converter (-3 h):
--      docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -t --init-command="SET @executar = 1"' < deploy/fuso/2-converter.sql
--   Desfazer (+3 h; ver "Desfazer" no LEIAME.md): revisar com --init-command="SET @desfazer = 1" e executar com
--      --init-command="SET @executar = 1, @desfazer = 1"
--
-- Trava: a conversão grava a linha 1 em fleetbase.entregas_fuso_convertido na mesma transação dos UPDATEs; converter de
-- novo falha com "Duplicate entry" e desfaz tudo (o cliente mysql para no primeiro erro). O desfazer exige a linha e a
-- apaga na mesma transação. Sem a variável @executar = 1, nada é alterado (a tabela da trava é criada vazia, se faltar).

CREATE TABLE IF NOT EXISTS fleetbase.entregas_fuso_convertido (
    id       TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    feito_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DROP PROCEDURE IF EXISTS fleetbase.entregas_fuso_deslocar;
DROP PROCEDURE IF EXISTS fleetbase.entregas_fuso_converter;

DELIMITER //
-- horas: -3 converte (UTC → Brasília), +3 desfaz. executar: 0 só lista, 1 altera (sem transação própria: quem chama abre)
CREATE PROCEDURE fleetbase.entregas_fuso_deslocar(IN horas INT, IN executar TINYINT)
BEGIN
    DECLARE fim INT DEFAULT 0;
    DECLARE esquema, tabela, coluna, tipo VARCHAR(64);
    DECLARE colunas INT DEFAULT 0;
    DECLARE alvo CURSOR FOR
        SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA IN ('fleetbase', 'fleetbase_sandbox', 'fleetbase_storefront')
          AND (DATA_TYPE = 'datetime' OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login' AND DATA_TYPE IN ('varchar', 'char')))
        ORDER BY TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fim = 1;

    IF horas NOT IN (-3, 3) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entregas_fuso_deslocar: horas deve ser -3 (converter) ou 3 (desfazer)';
    END IF;

    -- "on update CURRENT_TIMESTAMP" numa dessas tabelas faria o UPDATE mexer em outra coluna
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS a
        JOIN information_schema.COLUMNS b ON b.TABLE_SCHEMA = a.TABLE_SCHEMA AND b.TABLE_NAME = a.TABLE_NAME
        WHERE a.TABLE_SCHEMA IN ('fleetbase', 'fleetbase_sandbox', 'fleetbase_storefront') AND a.DATA_TYPE = 'datetime'
          AND b.EXTRA LIKE '%on update%'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entregas_fuso_deslocar: tabela com "on update CURRENT_TIMESTAMP"; veja o passo 5 do inventario';
    END IF;

    OPEN alvo;
    laco: LOOP
        FETCH alvo INTO esquema, tabela, coluna, tipo;
        IF fim = 1 THEN
            LEAVE laco;
        END IF;

        IF tipo = 'datetime' THEN
            -- só datas reais (a zero, '0000-00-00', fica de fora)
            SET @filtro  = CONCAT('`', coluna, '` >= ''1900-01-01''');
            SET @comando = CONCAT('UPDATE `', esquema, '`.`', tabela, '` SET `', coluna, '` = `', coluna, '` + INTERVAL ', horas, ' HOUR WHERE ', @filtro);
        ELSE
            -- users.last_login: texto 'AAAA-MM-DD HH:MM:SS'
            SET @filtro  = CONCAT('`', coluna, '` REGEXP ''^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$''');
            SET @comando = CONCAT('UPDATE `', esquema, '`.`', tabela, '` SET `', coluna, '` = DATE_FORMAT(STR_TO_DATE(`', coluna,
                '`, ''%Y-%m-%d %H:%i:%s'') + INTERVAL ', horas, ' HOUR, ''%Y-%m-%d %H:%i:%s'') WHERE ', @filtro);
        END IF;

        IF executar = 1 THEN
            PREPARE passo FROM @comando;
            EXECUTE passo;
            DEALLOCATE PREPARE passo;
        ELSE
            SET @contagem = CONCAT('SELECT ''', esquema, '.', tabela, '.', coluna, ''' AS coluna, COUNT(*) AS linhas, ',
                QUOTE(@comando), ' AS comando FROM `', esquema, '`.`', tabela, '` WHERE ', @filtro);
            PREPARE passo FROM @contagem;
            EXECUTE passo;
            DEALLOCATE PREPARE passo;
        END IF;

        SET colunas = colunas + 1;
    END LOOP;
    CLOSE alvo;

    SELECT colunas AS colunas_deslocadas, horas AS horas, IF(executar = 1, 'executado', 'só listado (nada mudou)') AS modo;
END//

-- a conversão (ou o desfazer) em si: só com executar = 1, numa transação com a trava
CREATE PROCEDURE fleetbase.entregas_fuso_converter(IN executar TINYINT, IN desfazer TINYINT)
BEGIN
    DECLARE convertido INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT COUNT(*) INTO convertido FROM fleetbase.entregas_fuso_convertido WHERE id = 1;

    IF desfazer = 1 AND convertido = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nada a desfazer: fleetbase.entregas_fuso_convertido sem a linha 1';
    END IF;
    IF desfazer = 0 AND convertido = 1 AND executar = 0 THEN
        SELECT 'ATENÇÃO: já convertido (fleetbase.entregas_fuso_convertido tem a linha 1); converter de novo vai falhar' AS aviso;
    END IF;

    IF executar = 1 THEN
        START TRANSACTION;
        IF desfazer = 1 THEN
            DELETE FROM fleetbase.entregas_fuso_convertido WHERE id = 1;
            CALL fleetbase.entregas_fuso_deslocar(3, 1);
        ELSE
            -- falha com "Duplicate entry '1'" se já foi convertido: o handler desfaz e o cliente para aqui
            INSERT INTO fleetbase.entregas_fuso_convertido (id) VALUES (1);
            CALL fleetbase.entregas_fuso_deslocar(-3, 1);
        END IF;
        COMMIT;
    ELSE
        CALL fleetbase.entregas_fuso_deslocar(IF(desfazer = 1, 3, -3), 0);
    END IF;
END//
DELIMITER ;

SET time_zone = '+00:00';
CALL fleetbase.entregas_fuso_converter(IF(@executar = 1, 1, 0), IF(@desfazer = 1, 1, 0));

DROP PROCEDURE fleetbase.entregas_fuso_converter;
DROP PROCEDURE fleetbase.entregas_fuso_deslocar;

-- conferência, já com a sessão no fuso novo. Depois da execução: a média criado → despacho igual à "foto" do
-- inventário (passo 8) e nenhuma diferença acima de 150 min além das que já havia. Antes dela (na revisão), nesta
-- sessão, a média dá ~180 min a mais.
SET time_zone = '-03:00';
SELECT CASE WHEN @executar = 1 AND @desfazer = 1 THEN 'depois do desfazer (sessão em -03:00: a média volta a ~180 min a mais)'
            WHEN @executar = 1 THEN 'depois da conversão'
            ELSE 'revisão: nada foi alterado' END AS momento,
       COUNT(*) AS pedidos_despachados,
       ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, dispatched_at)), 1) AS media_min_criado_ate_despacho,
       SUM(ABS(TIMESTAMPDIFF(MINUTE, created_at, dispatched_at)) > 150) AS diferencas_acima_de_150_min
FROM fleetbase.orders WHERE dispatched_at IS NOT NULL AND deleted_at IS NULL;
SELECT public_id, status, created_at, dispatched_at, started_at, scheduled_at, updated_at
FROM fleetbase.orders ORDER BY id DESC LIMIT 5;
SELECT * FROM fleetbase.entregas_fuso_convertido;
