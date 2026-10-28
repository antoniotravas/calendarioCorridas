<?php

declare(strict_types=1);

/**
 * Link para a lista de inscritos de uma prova, na página do organizador (plataforma de
 * inscrições). Os nomes não são guardados nem mostrados aqui: só se aponta para a lista.
 *
 * Cada plataforma tem o seu endereço, deduzido do link do evento dessa fonte
 * (corridas_fontes.url_evento). Só entram as plataformas em que a lista foi confirmada:
 *   - Queroir:         /evento/{slug}              -> /evento/{slug}/evento_lista_inscritos
 *   - Recorde Pessoal: /evento/{slug}              -> /listaInscritos/{slug}
 *   - Trilho Perdido:  /evento/{slug}              -> /listaInscritos/{slug}
 *   - Stop and Go:     /events/{slug}/...          -> /events/{slug}/registrations
 *   - Acorrer:         /eventos/{slug}             (secção "Inscritos" na própria página)
 *   - AA Lisboa:       /Competicoes/{id}           (botão "Ver Inscritos/Confirmados" na página)
 * Lap2Go, Sinctime, FPA e as restantes não têm lista confirmada (ou carrega-a por JavaScript).
 */
final class ListaInscritos
{
    /**
     * @param array<string,mixed> $c linha de corridas, com 'fontes' (ver Database::anexarFontes)
     * @return array{fonte: string, url: string}|null
     */
    public static function de(array $c): ?array
    {
        foreach ($c['fontes'] ?? [] as $fonte) {
            $url = self::urlDaFonte((string) ($fonte['fonte'] ?? ''), (string) ($fonte['url_evento'] ?? ''));
            if ($url !== null) {
                return ['fonte' => (string) $fonte['fonte'], 'url' => $url];
            }
        }

        return null;
    }

    public static function urlDaFonte(string $fonte, string $urlEvento): ?string
    {
        if ($urlEvento === '') {
            return null;
        }

        switch ($fonte) {
            case 'Queroir':
                return preg_match('#^https://www\.queroir\.pt/evento/[^/]+/?$#', $urlEvento)
                    ? rtrim($urlEvento, '/') . '/evento_lista_inscritos'
                    : null;

            case 'Recorde Pessoal':
            case 'Trilho Perdido':
                if (preg_match('#^(https://www\.(?:recordepessoal\.pt|trilhoperdido\.com))/evento/([^/]+)/?$#', $urlEvento, $m)) {
                    return $m[1] . '/listaInscritos/' . $m[2];
                }

                return null;

            case 'Stop and Go':
                return preg_match('~^https://stopandgo\.net/events/([^/?#]+)~', $urlEvento, $m)
                    ? "https://stopandgo.net/events/{$m[1]}/registrations"
                    : null;

            case 'Acorrer':
                return preg_match('#^https://acorrer\.pt/eventos/[^/]+/?$#', $urlEvento) ? $urlEvento : null;

            case 'AA Lisboa':
                return preg_match('#^https://competicoes\.aalisboa\.com\.pt/Competicoes/\d+#', $urlEvento) ? $urlEvento : null;
        }

        return null;
    }
}
