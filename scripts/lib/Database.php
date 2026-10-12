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

    /** Campos que uma fonte secundária pode preencher quando a fonte principal os deixou vazios. */
    private const CAMPOS_COMPLEMENTARES = ['local', 'distancias', 'tipo', 'regiao', 'url_evento', 'imagem_url'];

    /**
     * Grava uma prova vinda de uma fonte:
     *  - se esta fonte já a tinha publicado (mesmo hash_dedup), atualiza-a;
     *  - senão, se outra fonte já tem a mesma prova (mesma data, nome
     *    equivalente — ver NomeProvaHelper::mesmaProva), junta esta fonte à
     *    prova existente em vez de criar uma repetida;
     *  - senão, cria uma prova nova.
     *
     * A fonte que criou a prova ("fonte principal", corridas.fonte) define os
     * dados; as restantes só preenchem campos que ela deixou vazios.
     *
     * @return 'inserted'|'updated'|'merged'
     */
    public function upsertCorrida(Corrida $corrida): string
    {
        $hash = $corrida->hashDedup();

        $verifica = $this->pdo->prepare(
            'SELECT corrida_id FROM corridas_fontes WHERE fonte = :fonte AND hash_dedup = :hash'
        );
        $verifica->execute(['fonte' => $corrida->fonte, 'hash' => $hash]);
        $existenteId = $verifica->fetchColumn();

        if ($existenteId !== false) {
            $this->atualizarComFonte((int) $existenteId, $corrida);
            $this->registarFonte((int) $existenteId, $corrida, $hash);

            return 'updated';
        }

        $equivalenteId = $this->encontrarProvaEquivalente($corrida);
        if ($equivalenteId !== null) {
            $this->atualizarComFonte($equivalenteId, $corrida);
            $this->registarFonte($equivalenteId, $corrida, $hash);

            return 'merged';
        }

        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO corridas
                (nome, data_prova, local, distancias, tipo, regiao, url_evento, fonte, url_fonte, imagem_url, hash_dedup)
            VALUES
                (:nome, :data_prova, :local, :distancias, :tipo, :regiao, :url_evento, :fonte, :url_fonte, :imagem_url, :hash_dedup)
            SQL);
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
            'imagem_url' => $corrida->imagemUrl,
            'hash_dedup' => $hash,
        ]);
        $corridaId = (int) $this->pdo->lastInsertId();

        $this->registarFonte($corridaId, $corrida, $hash);
        $this->sincronizarDistancias($corridaId, $corrida->distancias);

        return 'inserted';
    }

    /**
     * Procura, na mesma data, uma prova de outra fonte com nome equivalente.
     * Provas que já têm esta fonte ficam de fora: se a fonte publica duas
     * entradas parecidas no mesmo dia, são provas distintas.
     */
    private function encontrarProvaEquivalente(Corrida $corrida): ?int
    {
        // Nome, data e local exatamente iguais aos da fonte principal de uma prova existente.
        $igual = $this->pdo->prepare('SELECT id FROM corridas WHERE hash_dedup = :hash');
        $igual->execute(['hash' => $corrida->hashDedup()]);
        $igualId = $igual->fetchColumn();
        if ($igualId !== false) {
            return (int) $igualId;
        }

        if ($corrida->dataProva === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(<<<SQL
            SELECT c.id, c.nome, c.local FROM corridas c
            WHERE c.data_prova = :data
              AND NOT EXISTS (
                  SELECT 1 FROM corridas_fontes cf WHERE cf.corrida_id = c.id AND cf.fonte = :fonte
              )
            ORDER BY c.id
            SQL);
        $stmt->execute(['data' => $corrida->dataProva, 'fonte' => $corrida->fonte]);

        foreach ($stmt->fetchAll() as $candidata) {
            if (NomeProvaHelper::mesmaProva($corrida->nome, $candidata['nome'], $corrida->local, $candidata['local'])) {
                return (int) $candidata['id'];
            }
        }

        return null;
    }

    /**
     * Atualiza a prova com os dados desta fonte: substitui tudo se for a fonte
     * principal, senão só preenche campos vazios.
     */
    private function atualizarComFonte(int $corridaId, Corrida $corrida): void
    {
        $atual = $this->obterCorrida($corridaId);

        $novos = [
            'local' => $corrida->local,
            'distancias' => $corrida->distancias,
            'tipo' => $corrida->tipo,
            'regiao' => $corrida->regiao,
            'url_evento' => $corrida->urlEvento,
        ];

        if ($atual['fonte'] === $corrida->fonte) {
            $valores = $novos + [
                'nome' => $corrida->nome,
                'data_prova' => $corrida->dataProva,
                'url_fonte' => $corrida->urlFonte,
            ];
        } else {
            $valores = [];
            foreach ($novos as $campo => $valor) {
                $valores[$campo] = ($atual[$campo] === null || $atual[$campo] === '') ? $valor : $atual[$campo];
            }
        }

        // A imagem nunca é apagada por uma fonte que não a tem: pode ter sido
        // encontrada noutra fonte ou na página do evento (provasParaProcurarImagem).
        if ($corrida->imagemUrl !== null && ($atual['fonte'] === $corrida->fonte || empty($atual['imagem_url']))) {
            $valores['imagem_url'] = $corrida->imagemUrl;
        }

        $this->atualizarCampos($corridaId, $valores);
        $this->sincronizarDistancias($corridaId, $valores['distancias']);
    }

    private function registarFonte(int $corridaId, Corrida $corrida, string $hash): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO corridas_fontes (corrida_id, fonte, url_fonte, url_evento, hash_dedup)
            VALUES (:corrida_id, :fonte, :url_fonte, :url_evento, :hash)
            ON DUPLICATE KEY UPDATE
                corrida_id = VALUES(corrida_id),
                url_fonte = VALUES(url_fonte),
                url_evento = VALUES(url_evento),
                visto_em = CURRENT_TIMESTAMP
            SQL);
        $stmt->execute([
            'corrida_id' => $corridaId,
            'fonte' => $corrida->fonte,
            'url_fonte' => $corrida->urlFonte,
            'url_evento' => $corrida->urlEvento,
            'hash' => $hash,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function obterCorrida(int $corridaId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM corridas WHERE id = :id');
        $stmt->execute(['id' => $corridaId]);

        return $stmt->fetch();
    }

    /**
     * @param array<string,mixed> $valores coluna => valor (colunas fixas, nunca vindas do exterior)
     */
    private function atualizarCampos(int $corridaId, array $valores): void
    {
        $atribuicoes = implode(', ', array_map(fn (string $c) => "{$c} = :{$c}", array_keys($valores)));
        $stmt = $this->pdo->prepare("UPDATE corridas SET {$atribuicoes} WHERE id = :id");
        $stmt->execute($valores + ['id' => $corridaId]);
    }

    /**
     * Junta provas repetidas que já estejam na base de dados (mesma data, nome
     * equivalente, sem fontes em comum) — por exemplo as gravadas antes de
     * existir a deteção entre fontes. A prova mais antiga fica; a outra passa-lhe
     * as fontes e os favoritos dos utilizadores, e é apagada.
     *
     * @return int número de provas juntadas
     */
    public function consolidarRepetidas(): int
    {
        $linhas = $this->pdo->query(<<<SQL
            SELECT c.id, c.nome, c.local, c.data_prova, GROUP_CONCAT(cf.fonte SEPARATOR '\n') AS fontes
            FROM corridas c
            LEFT JOIN corridas_fontes cf ON cf.corrida_id = c.id
            WHERE c.data_prova IS NOT NULL
            GROUP BY c.id
            ORDER BY c.data_prova, c.id
            SQL)->fetchAll();

        $porData = [];
        foreach ($linhas as $linha) {
            $linha['fontes'] = $linha['fontes'] !== null ? explode("\n", $linha['fontes']) : [];
            $porData[$linha['data_prova']][] = $linha;
        }

        $juntadas = 0;

        foreach ($porData as $provas) {
            $total = count($provas);
            for ($i = 0; $i < $total; $i++) {
                if ($provas[$i] === null) {
                    continue;
                }
                for ($j = $i + 1; $j < $total; $j++) {
                    if ($provas[$j] === null
                        || array_intersect($provas[$i]['fontes'], $provas[$j]['fontes']) !== []
                        || !NomeProvaHelper::mesmaProva(
                            $provas[$i]['nome'],
                            $provas[$j]['nome'],
                            $provas[$i]['local'],
                            $provas[$j]['local']
                        )
                    ) {
                        continue;
                    }

                    $this->juntarProvas((int) $provas[$i]['id'], (int) $provas[$j]['id']);
                    $provas[$i]['fontes'] = array_merge($provas[$i]['fontes'], $provas[$j]['fontes']);
                    $provas[$j] = null;
                    $juntadas++;
                }
            }
        }

        return $juntadas;
    }

    private function juntarProvas(int $ficaId, int $saiId): void
    {
        $this->pdo->beginTransaction();

        try {
            $fica = $this->obterCorrida($ficaId);
            $sai = $this->obterCorrida($saiId);

            $valores = [];
            foreach (self::CAMPOS_COMPLEMENTARES as $campo) {
                $valores[$campo] = ($fica[$campo] === null || $fica[$campo] === '') ? $sai[$campo] : $fica[$campo];
            }
            $this->atualizarCampos($ficaId, $valores);

            $this->pdo->prepare('UPDATE corridas_fontes SET corrida_id = :fica WHERE corrida_id = :sai')
                ->execute(['fica' => $ficaId, 'sai' => $saiId]);

            // Quem tinha a prova repetida no seu calendário passa a ter a que fica.
            $this->pdo->prepare(<<<SQL
                INSERT IGNORE INTO favoritos (utilizador_id, corrida_id, criado_em)
                SELECT utilizador_id, :fica, criado_em FROM favoritos WHERE corrida_id = :sai
                SQL)->execute(['fica' => $ficaId, 'sai' => $saiId]);

            $this->pdo->prepare('DELETE FROM corridas WHERE id = :sai')->execute(['sai' => $saiId]);

            $this->sincronizarDistancias($ficaId, $valores['distancias']);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Faz o link do evento de cada prova apontar para a página de uma fonte
     * organizadora (ver FonteOrganizadora), quando a prova foi encontrada numa
     * delas — mesmo que tenha sido criada por outra fonte. Corre no fim de cada
     * execução, depois das junções, para valer também para provas juntadas e
     * para links que a fonte principal voltou a escrever.
     * Se a prova estiver em várias organizadoras, ganha a primeira da lista.
     *
     * @param string[] $fontes nomes das fontes organizadoras, por prioridade
     * @return int número de provas cujo link mudou (repete-se em cada execução
     *             para as provas cuja fonte principal voltou a escrever o seu link)
     */
    public function aplicarLinksOrganizadores(array $fontes): int
    {
        if ($fontes === []) {
            return 0;
        }

        $marcadores = implode(',', array_fill(0, count($fontes), '?'));
        $stmt = $this->pdo->prepare(<<<SQL
            UPDATE corridas c
            INNER JOIN (
                SELECT cf.corrida_id, cf.url_evento,
                       ROW_NUMBER() OVER (
                           PARTITION BY cf.corrida_id
                           ORDER BY FIELD(cf.fonte, {$marcadores}), cf.visto_em DESC
                       ) AS ordem
                FROM corridas_fontes cf
                WHERE cf.fonte IN ({$marcadores}) AND cf.url_evento IS NOT NULL AND cf.url_evento <> ''
            ) org ON org.corrida_id = c.id AND org.ordem = 1
            SET c.url_evento = org.url_evento
            WHERE NOT (c.url_evento <=> org.url_evento)
            SQL);
        $stmt->execute([...$fontes, ...$fontes]);

        return $stmt->rowCount();
    }

    /**
     * Páginas de provas futuras (de qualquer fonte) que começam por $prefixo e
     * em que ainda não se procurou o link oficial; depois destas, as que foram
     * lidas há mais de um dia sem se encontrar link (o organizador pode tê-lo
     * acrescentado entretanto, ou a leitura da página pode ter melhorado).
     *
     * @return string[]
     */
    public function paginasSemLinkOficial(string $prefixo, int $limite): array
    {
        $stmt = $this->pdo->prepare(sprintf(<<<'SQL'
            SELECT DISTINCT cf.url_evento
            FROM corridas_fontes cf
            INNER JOIN corridas c ON c.id = cf.corrida_id
            LEFT JOIN links_oficiais l ON l.hash_pagina = SHA1(cf.url_evento)
            WHERE cf.url_evento LIKE :prefixo AND c.data_prova >= CURDATE()
              AND (l.hash_pagina IS NULL
                   OR (l.url_oficial IS NULL AND l.procurado_em < NOW() - INTERVAL 1 DAY))
            ORDER BY l.hash_pagina IS NOT NULL, c.data_prova
            LIMIT %d
            SQL, $limite));
        $stmt->execute(['prefixo' => addcslashes($prefixo, '%_') . '%']);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Guarda o link oficial encontrado numa página (ou NULL, se não tinha), para
     * não voltar a ler essa página.
     */
    public function gravarLinkOficial(string $urlPagina, ?string $urlOficial): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO links_oficiais (hash_pagina, url_pagina, url_oficial)
            VALUES (SHA1(:pagina), :pagina2, :oficial)
            ON DUPLICATE KEY UPDATE url_oficial = VALUES(url_oficial), procurado_em = CURRENT_TIMESTAMP
            SQL);
        $stmt->execute(['pagina' => $urlPagina, 'pagina2' => $urlPagina, 'oficial' => $urlOficial]);
    }

    /**
     * Usa a imagem encontrada numa página de fonte (ex. All4Running) nas provas
     * dessa página que ainda não têm imagem — evita voltar a pedir a página
     * só para procurar a imagem.
     */
    public function gravarImagemDaPagina(string $urlPagina, string $imagemUrl): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE corridas c
            INNER JOIN corridas_fontes cf ON cf.corrida_id = c.id
            SET c.imagem_url = :imagem, c.imagem_procurada_em = NOW()
            WHERE cf.url_evento = :pagina AND c.imagem_url IS NULL
            SQL);
        $stmt->execute(['imagem' => $imagemUrl, 'pagina' => $urlPagina]);
    }

    /**
     * Troca o link das provas que apontam para a página de uma fonte (ex. a
     * página da prova na All4Running) pelo link oficial encontrado nessa página.
     * Corre no fim de cada execução, antes de aplicarLinksOrganizadores() — que
     * continua a ter a última palavra.
     *
     * @return int número de provas cujo link mudou
     */
    public function aplicarLinksOficiais(): int
    {
        return (int) $this->pdo->exec(<<<'SQL'
            UPDATE corridas c
            INNER JOIN links_oficiais l ON l.hash_pagina = SHA1(c.url_evento)
            SET c.url_evento = l.url_oficial
            WHERE l.url_oficial IS NOT NULL AND c.url_evento <> l.url_oficial
            SQL);
    }

    /**
     * Recalcula a categoria (estrada, trail, corta-mato, pista) das provas futuras a
     * partir do nome e do tipo — corre no fim de cada execução, depois das
     * junções entre fontes, que podem ter mudado o tipo.
     *
     * @return int número de provas cuja categoria mudou
     */
    public function atualizarCategorias(): int
    {
        $linhas = $this->pdo->query(
            'SELECT id, nome, tipo, categoria FROM corridas WHERE data_prova >= CURDATE() OR data_prova IS NULL'
        )->fetchAll();

        $atualizar = $this->pdo->prepare('UPDATE corridas SET categoria = :categoria WHERE id = :id');
        $mudadas = 0;

        foreach ($linhas as $linha) {
            $categoria = NomeProvaHelper::categoria($linha['nome'], $linha['tipo']);
            if ($categoria !== $linha['categoria']) {
                $atualizar->execute(['categoria' => $categoria, 'id' => $linha['id']]);
                $mudadas++;
            }
        }

        return $mudadas;
    }

    /**
     * Provas futuras ainda sem imagem e em que ainda não se procurou, com as
     * páginas de evento onde procurar (de todas as fontes da prova, sem PDFs).
     *
     * @return array<int,string[]> id da prova => URLs das páginas do evento
     */
    public function provasParaProcurarImagem(int $limite): array
    {
        $stmt = $this->pdo->prepare(sprintf(<<<'SQL'
            SELECT c.id, c.url_evento, cf.url_evento AS url_evento_fonte
            FROM (
                SELECT id, url_evento FROM corridas
                WHERE imagem_url IS NULL AND imagem_procurada_em IS NULL AND data_prova >= CURDATE()
                ORDER BY data_prova, id
                LIMIT %d
            ) c
            LEFT JOIN corridas_fontes cf ON cf.corrida_id = c.id
            ORDER BY c.id, cf.id
            SQL, $limite));
        $stmt->execute();

        $paginas = [];
        foreach ($stmt->fetchAll() as $linha) {
            $id = (int) $linha['id'];
            $paginas[$id] ??= [];
            foreach ([$linha['url_evento'], $linha['url_evento_fonte']] as $url) {
                if (is_string($url) && preg_match('#^https?://#i', $url) && !preg_match('/\.pdf($|\?)/i', $url)
                    && !in_array($url, $paginas[$id], true)
                ) {
                    $paginas[$id][] = $url;
                }
            }
        }

        return $paginas;
    }

    /**
     * Regista o resultado da procura de imagem (encontrada ou não), para não
     * voltar a procurar na próxima execução.
     */
    public function gravarImagemProcurada(int $corridaId, ?string $imagemUrl): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE corridas SET imagem_url = COALESCE(imagem_url, :imagem), imagem_procurada_em = NOW() WHERE id = :id'
        );
        $stmt->execute(['imagem' => $imagemUrl, 'id' => $corridaId]);
    }

    /**
     * Provas futuras onde procurar prémios monetários: as nunca vistas
     * primeiro (pela data da prova), depois as que ficaram por saber há mais
     * de 7 dias (o regulamento costuma sair mais tarde) e as que não tinham
     * prémios há mais de 30 dias (o regulamento pode mudar).
     *
     * Para cada prova, as páginas a ler: os links do evento da prova e de
     * cada fonte e, das fontes cujo link da fonte é a página da própria prova
     * (ex. All4Running, $prefixosPaginaProva), também esse.
     *
     * @param string[] $prefixosPaginaProva
     * @return array<int,string[]> id da prova => endereços
     */
    public function provasParaProcurarPremios(int $limite, array $prefixosPaginaProva = []): array
    {
        $stmt = $this->pdo->prepare(sprintf(<<<'SQL'
            SELECT c.id, c.url_evento, cf.url_evento AS url_evento_fonte, cf.url_fonte
            FROM (
                SELECT id, url_evento, premios_procurado_em FROM corridas
                WHERE data_prova >= CURDATE()
                  AND (premios_procurado_em IS NULL
                       OR (premios_monetarios IS NULL AND premios_procurado_em < NOW() - INTERVAL 7 DAY)
                       OR (premios_monetarios = 0 AND premios_procurado_em < NOW() - INTERVAL 30 DAY))
                ORDER BY (premios_procurado_em IS NOT NULL), data_prova, id
                LIMIT %d
            ) c
            LEFT JOIN corridas_fontes cf ON cf.corrida_id = c.id
            ORDER BY (c.premios_procurado_em IS NOT NULL), c.id, cf.id
            SQL, $limite));
        $stmt->execute();

        $paginas = [];
        foreach ($stmt->fetchAll() as $linha) {
            $id = (int) $linha['id'];
            $paginas[$id] ??= [];
            $candidatas = [$linha['url_evento'], $linha['url_evento_fonte']];
            foreach ($prefixosPaginaProva as $prefixo) {
                if (is_string($linha['url_fonte']) && str_starts_with($linha['url_fonte'], $prefixo)) {
                    $candidatas[] = $linha['url_fonte'];
                }
            }
            foreach ($candidatas as $url) {
                if (is_string($url) && preg_match('#^https?://#i', $url) && !in_array($url, $paginas[$id], true)) {
                    $paginas[$id][] = $url;
                }
            }
        }

        return $paginas;
    }

    /**
     * Grava o resultado da procura de prémios monetários (true/false, ou null
     * se não deu para saber), com o excerto e o endereço onde foi lido.
     */
    public function gravarPremios(int $corridaId, ?bool $monetarios, ?string $excerto, ?string $url): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE corridas SET premios_monetarios = :monetarios, premios_excerto = :excerto, premios_url = :url,'
            . ' premios_procurado_em = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'monetarios' => $monetarios === null ? null : (int) $monetarios,
            'excerto' => $excerto !== null ? mb_substr($excerto, 0, 300) : null,
            'url' => $url !== null ? mb_substr($url, 0, 500) : null,
            'id' => $corridaId,
        ]);
    }

    /**
     * Apaga imagens usadas por 3 ou mais provas: quase sempre o logótipo do
     * site (og:image por omissão), não o cartaz da prova.
     *
     * @return int número de provas que ficaram sem imagem
     */
    public function descartarImagensGenericas(): int
    {
        return (int) $this->pdo->exec(<<<'SQL'
            UPDATE corridas SET imagem_url = NULL
            WHERE imagem_url IN (
                SELECT imagem_url FROM (
                    SELECT imagem_url FROM corridas
                    WHERE imagem_url IS NOT NULL
                    GROUP BY imagem_url
                    HAVING COUNT(*) >= 3
                ) repetidas
            )
            SQL);
    }

    /**
     * Provas futuras que uma fonte tem publicadas na base de dados — usado para
     * avisar quando uma fonte que costumava ter provas deixa de devolver alguma.
     */
    public function contarProvasFuturasPorFonte(string $fonte): int
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT COUNT(DISTINCT c.id)
            FROM corridas c
            INNER JOIN corridas_fontes cf ON cf.corrida_id = c.id
            WHERE cf.fonte = :fonte AND c.data_prova >= CURDATE()
            SQL);
        $stmt->execute(['fonte' => $fonte]);

        return (int) $stmt->fetchColumn();
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
     * Lista provas para apresentação, com filtros opcionais, a partir de hoje
     * (as provas sem data vão no fim). $distanciaFaixa é um par [min, max] em
     * km (ver DistanciaHelper::FAIXAS); $categoria é "estrada", "trail",
     * "corta-mato" ou "pista". Com $limite, devolve só essa página.
     *
     * @param array{0:float,1:float}|null $distanciaFaixa
     * @return array<int,array<string,mixed>>
     */
    public function listarCorridas(
        ?int $mes = null,
        ?string $fonte = null,
        ?string $procura = null,
        ?array $distanciaFaixa = null,
        ?int $utilizadorIdParaFavoritos = null,
        ?int $limite = null,
        int $deslocamento = 0,
        ?string $categoria = null,
        ?bool $premios = null
    ): array {
        [$where, $params] = $this->filtrosCorridas($mes, $fonte, $procura, $distanciaFaixa, $categoria, $premios);

        $camposFavorito = '';
        $juncoes = '';
        if ($utilizadorIdParaFavoritos !== null) {
            $camposFavorito = ', (f.id IS NOT NULL) AS e_favorito';
            $juncoes = ' LEFT JOIN favoritos f ON f.corrida_id = c.id AND f.utilizador_id = :utilizador_id';
            $params['utilizador_id'] = $utilizadorIdParaFavoritos;
        }

        $sql = "SELECT c.*{$camposFavorito} FROM corridas c{$juncoes} WHERE {$where}"
            . ' ORDER BY (c.data_prova IS NULL) ASC, c.data_prova ASC, c.nome ASC, c.id ASC';
        if ($limite !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $limite, max(0, $deslocamento));
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->anexarFontes($stmt->fetchAll());
    }

    /**
     * Total de provas com os mesmos filtros de listarCorridas().
     *
     * @param array{0:float,1:float}|null $distanciaFaixa
     */
    public function contarCorridas(
        ?int $mes = null,
        ?string $fonte = null,
        ?string $procura = null,
        ?array $distanciaFaixa = null,
        ?string $categoria = null,
        ?bool $premios = null
    ): int {
        [$where, $params] = $this->filtrosCorridas($mes, $fonte, $procura, $distanciaFaixa, $categoria, $premios);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM corridas c WHERE {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{0:float,1:float}|null $distanciaFaixa
     * @return array{0:string,1:array<string,mixed>} condição WHERE e parâmetros
     */
    private function filtrosCorridas(
        ?int $mes,
        ?string $fonte,
        ?string $procura,
        ?array $distanciaFaixa,
        ?string $categoria,
        ?bool $premios = null
    ): array {
        $condicoes = ['(c.data_prova >= CURDATE() OR c.data_prova IS NULL)'];
        $params = [];

        if ($mes !== null) {
            $condicoes[] = 'MONTH(c.data_prova) = :mes';
            $params['mes'] = $mes;
        }

        if ($fonte !== null && $fonte !== '') {
            $condicoes[] = 'EXISTS (SELECT 1 FROM corridas_fontes cf WHERE cf.corrida_id = c.id AND cf.fonte = :fonte)';
            $params['fonte'] = $fonte;
        }

        if ($procura !== null && $procura !== '') {
            $condicoes[] = '(c.nome LIKE :procura OR c.local LIKE :procura_local)';
            $params['procura'] = '%' . $procura . '%';
            $params['procura_local'] = '%' . $procura . '%';
        }

        if ($distanciaFaixa !== null) {
            $condicoes[] = 'EXISTS (SELECT 1 FROM corridas_distancias cd '
                . 'WHERE cd.corrida_id = c.id AND cd.distancia_km BETWEEN :dist_min AND :dist_max)';
            $params['dist_min'] = $distanciaFaixa[0];
            $params['dist_max'] = $distanciaFaixa[1];
        }

        if ($categoria !== null && $categoria !== '') {
            $condicoes[] = 'c.categoria = :categoria';
            $params['categoria'] = $categoria;
        }

        // Prémios monetários: true só as que têm; false as que o regulamento diz que não têm.
        if ($premios !== null) {
            $condicoes[] = 'c.premios_monetarios = :premios';
            $params['premios'] = $premios ? 1 : 0;
        }

        return [implode(' AND ', $condicoes), $params];
    }

    /**
     * Acrescenta a cada prova a lista das fontes onde foi encontrada, em
     * "fontes" => [['fonte' => ..., 'url_fonte' => ...], ...], com a fonte
     * principal primeiro.
     *
     * @param array<int,array<string,mixed>> $corridas
     * @return array<int,array<string,mixed>>
     */
    private function anexarFontes(array $corridas): array
    {
        if ($corridas === []) {
            return [];
        }

        $ids = array_map(fn (array $c) => (int) $c['id'], $corridas);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT corrida_id, fonte, url_fonte FROM corridas_fontes WHERE corrida_id IN ({$marcadores}) ORDER BY id"
        );
        $stmt->execute($ids);

        $porCorrida = [];
        foreach ($stmt->fetchAll() as $linha) {
            // Uma fonte pode ter mais do que uma entrada para a mesma prova; mostra-se uma vez.
            $porCorrida[(int) $linha['corrida_id']][$linha['fonte']] ??= [
                'fonte' => $linha['fonte'],
                'url_fonte' => $linha['url_fonte'],
            ];
        }

        foreach ($corridas as &$corrida) {
            $fontes = $porCorrida[(int) $corrida['id']] ?? [];
            $principal = $fontes[$corrida['fonte']] ?? ['fonte' => $corrida['fonte'], 'url_fonte' => $corrida['url_fonte']];
            unset($fontes[$corrida['fonte']]);
            $corrida['fontes'] = array_merge([$principal], array_values($fontes));
        }
        unset($corrida);

        return $corridas;
    }

    /**
     * Nomes das fontes distintas já recolhidas, para preencher o filtro.
     *
     * @return string[]
     */
    public function listarFontes(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT fonte FROM corridas_fontes ORDER BY fonte');

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
    // Link de subscrição do "meu calendário" (.ics)
    // ---------------------------------------------------------------------

    /**
     * Código secreto do link de subscrição do utilizador; cria-o se ainda não existir.
     */
    public function obterOuCriarTokenIcs(int $utilizadorId): string
    {
        $utilizador = $this->encontrarUtilizadorPorId($utilizadorId);
        if ($utilizador !== null && !empty($utilizador['ics_token'])) {
            return $utilizador['ics_token'];
        }

        return $this->renovarTokenIcs($utilizadorId);
    }

    /**
     * Gera um código novo — o link antigo deixa de funcionar.
     */
    public function renovarTokenIcs(int $utilizadorId): string
    {
        $token = bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare('UPDATE utilizadores SET ics_token = :token WHERE id = :id');
        $stmt->execute(['token' => $token, 'id' => $utilizadorId]);

        return $token;
    }

    public function encontrarUtilizadorPorTokenIcs(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM utilizadores WHERE ics_token = :token');
        $stmt->execute(['token' => $token]);
        $utilizador = $stmt->fetch();

        return $utilizador !== false ? $utilizador : null;
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

        return $this->anexarFontes($stmt->fetchAll());
    }
}
