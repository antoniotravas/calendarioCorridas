-- Migração 017: radar dos organizadores (página /organizadores.php, só administradores).
--
-- Correr UMA vez numa base de dados criada antes desta alteração, com a base de
-- dados já selecionada (ex. `mariadb -u UTILIZADOR -p NOME_BD < ficheiro`).
-- Pode ser corrida mais do que uma vez (IF NOT EXISTS).
--
-- A tabela é toda calculada a partir das provas (RadarOrganizadores), no fim de
-- cada execução do scraper. Para a preencher logo, sem esperar pelo scraper:
--   php scripts/atualizar_organizadores.php

CREATE TABLE IF NOT EXISTS organizadores (
    dominio        VARCHAR(190) NOT NULL,      -- ex. sinctime.com, facebook.com/pagina
    nome           VARCHAR(255) NULL,          -- nome do organizador mais usado nas provas deste site
    site_url       VARCHAR(500) NOT NULL,
    provas_ano     INT UNSIGNED NOT NULL DEFAULT 0, -- desde há um ano (inclui as futuras)
    provas_futuras INT UNSIGNED NOT NULL DEFAULT 0,
    provas_total   INT UNSIGNED NOT NULL DEFAULT 0,
    fontes         VARCHAR(500) NULL,          -- fontes onde as provas deste site foram encontradas
    tem_scraper    TINYINT(1) NOT NULL DEFAULT 0, -- o site já é uma fonte do scraper
    exemplo_corrida_id INT UNSIGNED NULL,
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (dominio),
    KEY idx_provas_ano (provas_ano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
