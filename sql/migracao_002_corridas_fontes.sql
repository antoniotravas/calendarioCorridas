-- Migração 002: várias fontes por prova (tabela corridas_fontes).
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. no phpMyAdmin, ou `mysql -u UTILIZADOR -p NOME_BD < ficheiro`).
-- É seguro correr mais do que uma vez: não apaga nada nem duplica linhas.
--
-- Depois disto, a próxima execução do scraper junta automaticamente as provas
-- repetidas que já existam (mesma data, nome equivalente).

CREATE TABLE IF NOT EXISTS corridas_fontes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    corrida_id     INT UNSIGNED NOT NULL,
    fonte          VARCHAR(100) NOT NULL,
    url_fonte      VARCHAR(500) NULL,
    url_evento     VARCHAR(500) NULL,
    hash_dedup     CHAR(40) NOT NULL,
    visto_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fonte_hash (fonte, hash_dedup),
    KEY idx_corrida (corrida_id),
    CONSTRAINT fk_fontes_corrida FOREIGN KEY (corrida_id)
        REFERENCES corridas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada prova existente passa a ter a sua fonte original registada.
INSERT IGNORE INTO corridas_fontes (corrida_id, fonte, url_fonte, url_evento, hash_dedup, visto_em)
SELECT id, fonte, url_fonte, url_evento, hash_dedup, atualizado_em
FROM corridas;
