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
    regiao         VARCHAR(100) NULL,
    url_evento     VARCHAR(500) NULL,
    fonte          VARCHAR(100) NOT NULL,
    url_fonte      VARCHAR(500) NULL,
    hash_dedup     CHAR(40) NOT NULL,
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hash_dedup (hash_dedup),
    KEY idx_data_prova (data_prova)
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

-- Utilizadores registados (por email/password local, e/ou por login Google).
CREATE TABLE IF NOT EXISTS utilizadores (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome           VARCHAR(150) NOT NULL,
    email          VARCHAR(255) NOT NULL,
    password_hash  VARCHAR(255) NULL,
    google_id      VARCHAR(64) NULL,
    criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email),
    UNIQUE KEY uq_google_id (google_id)
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
