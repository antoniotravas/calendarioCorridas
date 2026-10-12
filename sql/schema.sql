-- Schema da base de dados do CalendarioCorridas
-- Correr este ficheiro no MySQL/phpMyAdmin antes de executar o scraper.

CREATE DATABASE IF NOT EXISTS calendario_corridas
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE calendario_corridas;

CREATE TABLE IF NOT EXISTS corridas (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome           VARCHAR(255) NOT NULL,
    data_prova     DATE NULL,
    local          VARCHAR(255) NULL,
    distancias     VARCHAR(255) NULL,
    tipo           VARCHAR(100) NULL,
    -- "estrada", "trail", "corta-mato" ou "pista" (NomeProvaHelper::categoria), para o filtro "Tipo".
    categoria      VARCHAR(20) NOT NULL DEFAULT 'estrada',
    regiao         VARCHAR(100) NULL,
    url_evento     VARCHAR(500) NULL,
    fonte          VARCHAR(100) NOT NULL,
    url_fonte      VARCHAR(500) NULL,
    -- Miniatura da prova: vem da listagem da fonte ou do og:image da página
    -- do evento (procurada uma vez; imagem_procurada_em evita repetir).
    imagem_url     VARCHAR(500) NULL,
    imagem_procurada_em DATETIME NULL,
    -- Prémios monetários para os mais bem classificados, segundo o regulamento
    -- ou a página da prova (PremiosHelper): 1 sim, 0 não, NULL por saber.
    -- O excerto e o endereço mostram onde foi lido.
    premios_monetarios TINYINT(1) NULL,
    premios_excerto VARCHAR(300) NULL,
    premios_url    VARCHAR(500) NULL,
    premios_procurado_em DATETIME NULL,
    hash_dedup     CHAR(40) NOT NULL,
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hash_dedup (hash_dedup),
    KEY idx_data_prova (data_prova),
    KEY idx_categoria (categoria),
    KEY idx_premios (premios_monetarios)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Distâncias individuais de cada prova (uma corrida pode ter várias: 10K, 5K, ...),
-- extraídas do texto livre em corridas.distancias, para permitir filtrar por distância.
CREATE TABLE IF NOT EXISTS corridas_distancias (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    corrida_id     INT UNSIGNED NOT NULL,
    distancia_km   DECIMAL(6,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_distancia_km (distancia_km),
    CONSTRAINT fk_distancias_corrida FOREIGN KEY (corrida_id)
        REFERENCES corridas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link oficial (site do organizador / inscrições) de provas que algumas fontes
-- só mostram na página de detalhe (ex. All4Running). Cada página é lida uma vez;
-- o scraper troca depois o link da prova pelo oficial (Database::aplicarLinksOficiais).
CREATE TABLE IF NOT EXISTS links_oficiais (
    hash_pagina    CHAR(40) NOT NULL,          -- sha1(url_pagina), para indexar URLs compridos
    url_pagina     VARCHAR(500) NOT NULL,      -- página da prova na fonte
    url_oficial    VARCHAR(500) NULL,          -- site oficial/inscrições; NULL se a página não o tiver
    procurado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (hash_pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fontes onde cada prova foi encontrada. A mesma prova pode aparecer em vários
-- sites (ex. All4Running e a associação distrital); fica uma só linha em
-- `corridas` e uma linha aqui por cada fonte. `hash_dedup` identifica a prova
-- tal como essa fonte a publica (nome + data + local), para a reencontrar na
-- próxima execução do scraper.
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

-- Utilizadores registados (por email/password local, e/ou por login Google).
CREATE TABLE IF NOT EXISTS utilizadores (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome           VARCHAR(150) NOT NULL,
    email          VARCHAR(255) NOT NULL,
    password_hash  VARCHAR(255) NULL,
    google_id      VARCHAR(64) NULL,
    -- Código secreto do link de subscrição do "meu calendário" (.ics), criado
    -- na primeira visita a /meu-calendario.php; pode ser renovado pelo utilizador.
    ics_token      CHAR(32) NULL,
    -- Administrador: gere os utilizadores em /gestao-utilizadores.php e vê
    -- tudo (definido com scripts/definir_admin.php).
    e_admin        TINYINT(1) NOT NULL DEFAULT 0,
    -- Pode ver os prémios monetários (ícone "€" e filtro); dado pelo administrador.
    ver_premios    TINYINT(1) NOT NULL DEFAULT 0,
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email),
    UNIQUE KEY uq_google_id (google_id),
    UNIQUE KEY uq_ics_token (ics_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Meu calendário": provas que cada utilizador marcou como suas.
CREATE TABLE IF NOT EXISTS favoritos (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilizador_id  INT UNSIGNED NOT NULL,
    corrida_id     INT UNSIGNED NOT NULL,
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_utilizador_corrida (utilizador_id, corrida_id),
    CONSTRAINT fk_favoritos_utilizador FOREIGN KEY (utilizador_id)
        REFERENCES utilizadores (id) ON DELETE CASCADE,
    CONSTRAINT fk_favoritos_corrida FOREIGN KEY (corrida_id)
        REFERENCES corridas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
