<?php

declare(strict_types=1);

/**
 * Acesso à base de dados MySQL: liga por PDO e faz upsert de corridas.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['db_host'],
            $config['db_name'],
            $config['db_charset'] ?? 'utf8mb4'
        );

        $this->pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Insere a corrida, ou atualiza-a se já existir (mesmo hash_dedup), e
     * sincroniza as distâncias individuais extraídas do texto livre.
     *
     * @return 'inserted'|'updated'
     */
    public function upsertCorrida(Corrida $corrida): string
    {
        $hash = $corrida->hashDedup();

        $verifica = $this->pdo->prepare('SELECT id FROM corridas WHERE hash_dedup = :hash');
        $verifica->execute(['hash' => $hash]);
        $existente = $verifica->fetch();

        $sql = <<<SQL
            INSERT INTO corridas
                (nome, data_prova, local, distancias, tipo, regiao, url_evento, fonte, url_fonte, hash_dedup)
            VALUES
                (:nome, :data_prova, :local, :distancias, :tipo, :regiao, :url_evento, :fonte, :url_fonte, :hash_dedup)
            ON DUPLICATE KEY UPDATE
                nome = VALUES(nome),
                data_prova = VALUES(data_prova),
                local = VALUES(local),
                distancias = VALUES(distancias),
                tipo = VALUES(tipo),
                regiao = VALUES(regiao),
                url_evento = VALUES(url_evento),
                fonte = VALUES(fonte),
                url_fonte = VALUES(url_fonte)
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'nome' => $corrida->nome,
            'data_prova' => $corrida->dataProva,
            'local' => $corrida->local,
            'distancias' => $corrida->distancias,
            'tipo' => $corrida->tipo,
            'regiao' => $corrida->regiao,
            'url_evento' => $corrida->urlEvento,
            'fonte' => $corrida->fonte,
            'url_fonte' => $corrida->urlFonte,
            'hash_dedup' => $hash,
        ]);

        $corridaId = $existente !== false ? (int) $existente['id'] : (int) $this->pdo->lastInsertId();

        $this->sincronizarDistancias($corridaId, $corrida->distancias);

        return $existente !== false ? 'updated' : 'inserted';
    }

    private function sincronizarDistancias(int $corridaId, ?string $distanciasTexto): void
    {
        $apagar = $this->pdo->prepare('DELETE FROM corridas_distancias WHERE corrida_id = :id');
        $apagar->execute(['id' => $corridaId]);

        $valores = DistanciaHelper::extrairKm($distanciasTexto);
        if ($valores === []) {
            return;
        }

        $inserir = $this->pdo->prepare(
            'INSERT INTO corridas_distancias (corrida_id, distancia_km) VALUES (:corrida_id, :km)'
        );
        foreach ($valores as $km) {
            $inserir->execute(['corrida_id' => $corridaId, 'km' => $km]);
        }
    }

    /**
     * Lista corridas para apresentação, com filtros opcionais.
     * $distanciaFaixa é um par [min, max] em km (ver DistanciaFaixas na página pública).
     *
     * @param array{0:float,1:float}|null $distanciaFaixa
     * @return array<int,array<string,mixed>>
     */
    public function listarCorridas(
        ?int $mes = null,
        ?string $fonte = null,
        ?string $procura = null,
        ?array $distanciaFaixa = null,
        ?int $utilizadorIdParaFavoritos = null
    ): array {
        $condicoes = [];
        $params = [];
        $juncoes = '';

        if ($mes !== null) {
            $condicoes[] = 'MONTH(c.data_prova) = :mes';
            $params['mes'] = $mes;
        }

        if ($fonte !== null && $fonte !== '') {
            $condicoes[] = 'c.fonte = :fonte';
            $params['fonte'] = $fonte;
        }

        if ($procura !== null && $procura !== '') {
            $condicoes[] = '(c.nome LIKE :procura OR c.local LIKE :procura)';
            $params['procura'] = '%' . $procura . '%';
        }

        if ($distanciaFaixa !== null) {
            $condicoes[] = 'EXISTS (SELECT 1 FROM corridas_distancias cd '
                . 'WHERE cd.corrida_id = c.id AND cd.distancia_km BETWEEN :dist_min AND :dist_max)';
            $params['dist_min'] = $distanciaFaixa[0];
            $params['dist_max'] = $distanciaFaixa[1];
        }

        $camposFavorito = '';
        if ($utilizadorIdParaFavoritos !== null) {
            $camposFavorito = ', (f.id IS NOT NULL) AS e_favorito';
            $juncoes = ' LEFT JOIN favoritos f ON f.corrida_id = c.id AND f.utilizador_id = :utilizador_id';
            $params['utilizador_id'] = $utilizadorIdParaFavoritos;
        }

        $sql = "SELECT c.*{$camposFavorito} FROM corridas c{$juncoes}";
        if ($condicoes !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condicoes);
        }
        $sql .= ' ORDER BY (c.data_prova IS NULL) ASC, c.data_prova ASC, c.nome ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Nomes das fontes distintas já recolhidas, para preencher o filtro.
     *
     * @return string[]
     */
    public function listarFontes(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT fonte FROM corridas ORDER BY fonte');

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ---------------------------------------------------------------------
    // Utilizadores e autenticação
    // ---------------------------------------------------------------------

    public function criarUtilizadorLocal(string $nome, string $email, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO utilizadores (nome, email, password_hash) VALUES (:nome, :email, :hash)'
        );
        $stmt->execute(['nome' => $nome, 'email' => $email, 'hash' => $passwordHash]);

        return (int) $this->pdo->lastInsertId();
    }

    public function encontrarUtilizadorPorEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM utilizadores WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $utilizador = $stmt->fetch();

        return $utilizador !== false ? $utilizador : null;
    }

    public function encontrarUtilizadorPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM utilizadores WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $utilizador = $stmt->fetch();

        return $utilizador !== false ? $utilizador : null;
    }

    public function encontrarUtilizadorPorGoogleId(string $googleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM utilizadores WHERE google_id = :id');
        $stmt->execute(['id' => $googleId]);
        $utilizador = $stmt->fetch();

        return $utilizador !== false ? $utilizador : null;
    }

    /**
     * Associa um google_id a um utilizador existente (por email) ou cria um novo.
     */
    public function obterOuCriarUtilizadorGoogle(string $googleId, string $email, string $nome): array
    {
        $existente = $this->encontrarUtilizadorPorGoogleId($googleId);
        if ($existente !== null) {
            return $existente;
        }

        $porEmail = $this->encontrarUtilizadorPorEmail($email);
        if ($porEmail !== null) {
            $stmt = $this->pdo->prepare('UPDATE utilizadores SET google_id = :google_id WHERE id = :id');
            $stmt->execute(['google_id' => $googleId, 'id' => $porEmail['id']]);

            return $this->encontrarUtilizadorPorId((int) $porEmail['id']);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO utilizadores (nome, email, google_id) VALUES (:nome, :email, :google_id)'
        );
        $stmt->execute(['nome' => $nome, 'email' => $email, 'google_id' => $googleId]);

        return $this->encontrarUtilizadorPorId((int) $this->pdo->lastInsertId());
    }

    // ---------------------------------------------------------------------
    // Favoritos ("o meu calendário")
    // ---------------------------------------------------------------------

    public function alternarFavorito(int $utilizadorId, int $corridaId): bool
    {
        $verifica = $this->pdo->prepare(
            'SELECT id FROM favoritos WHERE utilizador_id = :uid AND corrida_id = :cid'
        );
        $verifica->execute(['uid' => $utilizadorId, 'cid' => $corridaId]);

        if ($verifica->fetch() !== false) {
            $apagar = $this->pdo->prepare(
                'DELETE FROM favoritos WHERE utilizador_id = :uid AND corrida_id = :cid'
            );
            $apagar->execute(['uid' => $utilizadorId, 'cid' => $corridaId]);

            return false;
        }

        $inserir = $this->pdo->prepare(
            'INSERT INTO favoritos (utilizador_id, corrida_id) VALUES (:uid, :cid)'
        );
        $inserir->execute(['uid' => $utilizadorId, 'cid' => $corridaId]);

        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listarCorridasFavoritas(int $utilizadorId): array
    {
        $sql = <<<SQL
            SELECT c.*, 1 AS e_favorito
            FROM corridas c
            INNER JOIN favoritos f ON f.corrida_id = c.id
            WHERE f.utilizador_id = :uid
            ORDER BY (c.data_prova IS NULL) ASC, c.data_prova ASC, c.nome ASC
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uid' => $utilizadorId]);

        return $stmt->fetchAll();
    }
}
