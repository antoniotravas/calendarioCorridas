<?php

declare(strict_types=1);

/**
 * Radar dos organizadores: agrupa as provas pelo site para onde apontam (site
 * do organizador, link da prova e links de cada fonte) e conta quantas provas
 * cada site tem. Serve para escolher a que organizadores e plataformas vale a
 * pena fazer um scraper próprio: os que têm pelo menos MINIMO_PROVAS provas no
 * último ano (e futuras) e ainda não são fonte. Corre no fim de cada execução
 * do scraper e grava na tabela `organizadores` (página /organizadores.php).
 */
final class RadarOrganizadores
{
    /** Provas por ano a partir das quais um site sem scraper é candidato a fonte. */
    public const MINIMO_PROVAS = 3;

    /** Sites que não dizem quem organiza: documentos, encurtadores, formulários... */
    private const IGNORADOS = [
        'google.com', 'forms.gle', 'goo.gl', 'tinyurl.com', 'bit.ly', 'is.gd', 'cutt.ly', 'rb.gy', 't.ly',
        'l1nq.com', 'short.do', 'youtube.com', 'youtu.be', 'wa.me', 'linktr.ee', 'dropbox.com', '1drv.ms', 'live.com',
        'issuu.com', 'calameo.com', 'wetransfer.com', 'canva.com', 'canva.site',
    ];

    /** Redes sociais: cada página (facebook.com/pagina) é um organizador diferente. */
    private const REDES_SOCIAIS = ['facebook.com', 'fb.com', 'instagram.com'];

    /** Alojamentos em que cada subdomínio é um site diferente (não se junta pelo domínio). */
    private const ALOJAMENTOS = ['wixsite.com', 'netlify.app', 'blogspot.com', 'wordpress.com', 'github.io', 'webnode.pt', 'negocio.site'];

    /** Domínios de segundo nível (organizador.com.pt e não com.pt). */
    private const SEGUNDO_NIVEL = ['com.pt', 'org.pt', 'gov.pt', 'edu.pt', 'co.uk', 'com.br', 'com.es'];

    /**
     * Chave de um endereço no radar: o domínio sem "www." nem subdomínios
     * (beta.fpacompeticoes.pt → fpacompeticoes.pt; corrida2026.statusmarathon.run
     * → statusmarathon.run), exceto alojamentos (aavilareal.wixsite.com) e redes
     * sociais (facebook.com/pagina). Null para endereços que não identificam
     * ninguém (IGNORADOS) ou inválidos.
     */
    public static function chave(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = (string) preg_replace('/^(www\d?|m)\./', '', $host);
        if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        $partes = explode('.', $host);
        $niveis = in_array(implode('.', array_slice($partes, -2)), self::SEGUNDO_NIVEL, true) ? 3 : 2;
        $dominio = implode('.', array_slice($partes, -$niveis));

        if (in_array($dominio, self::IGNORADOS, true)) {
            return null;
        }
        if (in_array($dominio, self::ALOJAMENTOS, true)) {
            return $host;
        }
        if (in_array($dominio, self::REDES_SOCIAIS, true)) {
            $pagina = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))[0];
            if ($pagina === '' || in_array(strtolower($pagina), ['events', 'groups', 'pages', 'p', 'share', 'profile.php', 'watch'], true)) {
                return null; // links de eventos/partilhas: não dizem de que página são
            }

            return ($dominio === 'fb.com' ? 'facebook.com' : $dominio) . '/' . strtolower($pagina);
        }

        return $dominio;
    }

    /**
     * Calcula o radar a partir das linhas de Database::linhasParaRadarOrganizadores()
     * (uma por prova e fonte).
     *
     * @param array<int,array<string,mixed>> $linhas
     * @return array<int,array{dominio: string, nome: ?string, site_url: string, provas_ano: int,
     *         provas_futuras: int, provas_total: int, fontes: string, tem_scraper: bool, exemplo_corrida_id: int}>
     */
    public static function calcular(array $linhas): array
    {
        $hoje = date('Y-m-d');
        $haUmAno = date('Y-m-d', strtotime('-1 year'));

        // Sites que já são fonte: os das páginas de onde o scraper lê as provas.
        $comScraper = [];
        foreach ($linhas as $l) {
            $chave = self::chave($l['url_fonte'] ?? null);
            if ($chave !== null) {
                $comScraper[$chave] = true;
            }
        }

        $sites = [];
        foreach ($linhas as $l) {
            $id = (int) $l['id'];
            $chaveOrganizador = self::chave($l['organizador_url'] ?? null);
            $urls = [$l['organizador_url'] ?? null, $l['url_evento'] ?? null, $l['fonte_url_evento'] ?? null, $l['fonte_url_inscricao'] ?? null];

            foreach ($urls as $url) {
                $chave = self::chave($url);
                if ($chave === null) {
                    continue;
                }
                $site = &$sites[$chave];
                $site ??= ['dominio' => $chave, 'nomes' => [], 'site_url' => null, 'provas' => [], 'fontes' => []];
                $site['provas'][$id] = $l['data_prova'];
                if (!empty($l['fonte'])) {
                    $site['fontes'][$l['fonte']] = true;
                }
                // O nome só se sabe quando o site é o do organizador da prova.
                if ($chave === $chaveOrganizador) {
                    $site['site_url'] ??= $l['organizador_url'];
                    $nome = trim((string) ($l['organizador'] ?? ''));
                    if ($nome !== '') {
                        $site['nomes'][$nome][$id] = true;
                    }
                }
                unset($site);
            }
        }

        $radar = [];
        foreach ($sites as $chave => $s) {
            $datas = array_filter($s['provas']);
            // O nome mais usado nas provas desse site.
            uasort($s['nomes'], fn (array $a, array $b) => count($b) <=> count($a));
            $radar[] = [
                'dominio' => $chave,
                'nome' => $s['nomes'] !== [] ? mb_substr((string) array_key_first($s['nomes']), 0, 255) : null,
                'site_url' => $s['site_url'] ?? 'https://' . $chave,
                'provas_ano' => count(array_filter($datas, fn ($d) => $d >= $haUmAno)),
                'provas_futuras' => count(array_filter($datas, fn ($d) => $d >= $hoje)),
                'provas_total' => count($s['provas']),
                'fontes' => implode(', ', array_keys($s['fontes'])),
                'tem_scraper' => isset($comScraper[$chave]),
                'exemplo_corrida_id' => (int) array_key_last($s['provas']),
            ];
        }
        usort($radar, fn (array $a, array $b) => [$b['provas_ano'], $b['provas_total'], $a['dominio']] <=> [$a['provas_ano'], $a['provas_total'], $b['dominio']]);

        return $radar;
    }

    /** Recalcula e grava o radar. @return int número de sites */
    public static function atualizar(Database $db): int
    {
        $radar = self::calcular($db->linhasParaRadarOrganizadores());
        $db->substituirOrganizadores($radar);

        return count($radar);
    }

    /** @param array{provas_ano: int, tem_scraper: bool|int} $site */
    public static function eCandidato(array $site): bool
    {
        return !$site['tem_scraper'] && (int) $site['provas_ano'] >= self::MINIMO_PROVAS;
    }
}
