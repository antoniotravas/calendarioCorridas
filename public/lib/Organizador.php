<?php

declare(strict_types=1);

/**
 * Referência ao organizador de uma prova, para o cartão e a página da prova:
 * o nome (quando a fonte o diz) e o link — o site do organizador, ou então o
 * link da prova (que já aponta para a página oficial / inscrições sempre que
 * se conhece, ver Database::aplicarLinksOrganizadores e aplicarLinksOficiais).
 */
final class Organizador
{
    /** Encurtadores de links: o domínio não diz quem organiza. */
    private const ENCURTADORES = ['tinyurl.com', 'bit.ly', 'goo.gl', 'is.gd', 'cutt.ly', 'rb.gy', 't.ly'];

    /**
     * @param array<string,mixed> $c linha de corridas
     * @return array{nome: string, url: ?string, e_regulamento: bool, e_site: bool}|null
     *         e_site: o link é o site do organizador (e não a página da prova)
     */
    public static function de(array $c): ?array
    {
        $url = ($c['organizador_url'] ?? '') ?: (($c['url_evento'] ?? '') ?: null);
        $nome = trim((string) ($c['organizador'] ?? ''));
        $eRegulamento = $url !== null && empty($c['organizador_url']) && self::eDocumento($url);

        if ($nome === '' && $url !== null) {
            $host = (string) preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
            $nome = in_array($host, self::ENCURTADORES, true) ? 'Regulamento' : $host;
        }

        return $nome !== ''
            ? ['nome' => $nome, 'url' => $url, 'e_regulamento' => $eRegulamento, 'e_site' => !empty($c['organizador_url'])]
            : null;
    }

    /**
     * Link direto para as inscrições, quando algum dos endereços conhecidos da
     * prova (link da prova, página dos preços, páginas das fontes) é a página
     * dela numa plataforma de inscrições, ou tem "inscrições" no endereço.
     * Sem nenhum, null: não se mostra link.
     *
     * @param array<string,mixed> $c linha de corridas (com 'fontes', se houver)
     */
    public static function urlInscricao(array $c): ?string
    {
        $candidatos = [$c['url_evento'] ?? null, $c['inscricao_url'] ?? null];
        foreach ($c['fontes'] ?? [] as $fonte) {
            $candidatos[] = $fonte['url_fonte'] ?? null;
        }

        foreach ($candidatos as $url) {
            if (!is_string($url) || $url === '' || self::eDocumento($url)) {
                continue;
            }
            $host = (string) preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
            $caminho = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $plataforma = array_filter(self::PLATAFORMAS_INSCRICAO, fn (string $p) => $host === $p || str_ends_with($host, '.' . $p)) !== [];
            $lista = $caminho === '' || preg_match('#^(?:[a-z]{2}|(?:[a-z]{2}/)?(?:eventos?|events))$#i', $caminho);
            // Na plataforma, só a página de uma prova (não a página inicial nem listas).
            if (($plataforma && (!$lista || str_ends_with($host, '.statusmarathon.run')) && !str_starts_with($caminho, 'uploads'))
                || preg_match('#/(inscri[cç][oõ]es|inscricao|inscrever|registo|registration|register)\b#i', $url)) {
                return preg_replace('#^(https?://(?:www\.)?stopandgo\.net(?:/[a-z]{2})?/events/[^/]+)/prices/?$#i', '$1', $url);
            }
        }

        return null;
    }

    /** Plataformas de inscrições e organizadores que inscrevem na página de cada prova. */
    private const PLATAFORMAS_INSCRICAO = [
        'stopandgo.net', 'lap2go.com', 'acorrer.pt', 'recordepessoal.pt', 'sinctime.com', 'queroir.pt',
        'waitastart.com', 'eventsport.pt', 'statusmarathon.run', 'crono.aaalgarve.org', 'portimer.pt',
        'sportmaniacs.com', 'adnrace.pt', 'bttmanager.com', 'xistarca.pt', 'trilhoperdido.com',
        'proevents.pt', 'runporto.com', 'fpacompeticoes.pt',
    ];

    /** PDF ou encurtador (a FPA usa o regulamento como link da prova). */
    public static function eDocumento(string $url): bool
    {
        $host = (string) preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));

        return (bool) preg_match('/\.pdf($|\?)/i', $url) || in_array($host, self::ENCURTADORES, true);
    }
}
