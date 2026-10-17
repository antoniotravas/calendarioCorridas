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

    /** PDF ou encurtador (a FPA usa o regulamento como link da prova). */
    public static function eDocumento(string $url): bool
    {
        $host = (string) preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));

        return (bool) preg_match('/\.pdf($|\?)/i', $url) || in_array($host, self::ENCURTADORES, true);
    }
}
