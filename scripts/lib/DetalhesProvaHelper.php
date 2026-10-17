<?php

declare(strict_types=1);

/**
 * Lê, na página de uma prova numa plataforma de inscrições ou num
 * organizador, o que as listagens não mostram: hora de partida, programa
 * (provas e horas) e quem organiza, com o site do organizador.
 *
 *  - Lap2Go: blocos "Organizador" (span.evento-org) e "Site" (span.evento-site);
 *  - Stop and Go: "Organizador" (nome num <h3>) e o botão "Website do Organizador";
 *  - Acorrer: "Sábado, 17 de outubro de 2026 às 17:00" (og:description);
 *  - Xistarca: secção "DISTÂNCIA / HORA / LOCAL" ("» Corrida 10km | 10h00");
 *  - Portugal Running (calendário): "Entidade Organizadora".
 *
 * As páginas são lidas no fim do scraper, uma vez por semana por prova
 * (Database::provasParaProcurarDetalhes).
 */
final class DetalhesProvaHelper
{
    /** Domínios cujas páginas de prova se sabem ler. */
    public const SITES = ['lap2go.com', 'stopandgo.net', 'acorrer.pt', 'xistarca.pt', 'portugalrunning.com'];

    /**
     * @return array{hora_inicio: ?string, programa: ?array, organizador: ?string, organizador_url: ?string}
     */
    public static function extrair(string $html, string $url): array
    {
        $detalhes = ['hora_inicio' => null, 'programa' => null, 'organizador' => null, 'organizador_url' => null];
        $host = (string) parse_url($url, PHP_URL_HOST);
        $xpath = self::xpath($html);

        if (self::eSite($host, 'lap2go.com')) {
            $detalhes['organizador'] = self::texto($xpath, '//span[contains(@class,"evento-org")]/p');
            $detalhes['organizador_url'] = self::link($xpath, '//span[contains(@class,"evento-site")]//a/@href');
            // Sem site próprio, a Lap2Go põe o endereço dela.
            if (self::eSite((string) parse_url((string) $detalhes['organizador_url'], PHP_URL_HOST), 'lap2go.com')) {
                $detalhes['organizador_url'] = null;
            }
        } elseif (self::eSite($host, 'stopandgo.net')) {
            $detalhes['organizador'] = self::texto($xpath, '//p[normalize-space()="Organizador"]/following-sibling::h3[1]');
            $detalhes['organizador_url'] = self::link($xpath, '//a[@title="Website do Organizador"]/@href');
        } elseif (self::eSite($host, 'acorrer.pt')) {
            $quando = self::texto($xpath, '//meta[@property="og:description"]/@content') ?? '';
            if (preg_match('/\bàs (\d{1,2}):(\d{2})/u', $quando, $m) && (int) $m[1] < 24) {
                $detalhes['hora_inicio'] = sprintf('%02d:%s', $m[1], $m[2]);
            }
        } elseif (self::eSite($host, 'xistarca.pt')) {
            $detalhes['programa'] = self::programaXistarca(PremiosHelper::textoHtml($html));
        } elseif (self::eSite($host, 'portugalrunning.com')) {
            $detalhes['organizador'] = PortugalRunningScraper::extrairOrganizador($html);
        }

        if ($detalhes['programa'] !== null && $detalhes['hora_inicio'] === null) {
            $horas = array_filter(array_column($detalhes['programa'], 'hora'));
            $detalhes['hora_inicio'] = $horas !== [] ? min($horas) : null;
        }

        return $detalhes;
    }

    /**
     * "DISTÂNCIA / HORA / LOCAL" seguido de linhas "» Corrida 10km | 10h00".
     *
     * @return array<int,array{hora: string, prova: string, escaloes: null}>|null
     */
    private static function programaXistarca(string $texto): ?array
    {
        if (!preg_match('/DIST\S*NCIA\s*\/\s*HORA/iu', $texto, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $programa = [];
        foreach (array_slice(explode("\n", substr($texto, $m[0][1])), 1, 30) as $linha) {
            $linha = trim(preg_replace('/\s+/u', ' ', $linha));
            if ($linha === '') {
                continue;
            }
            if (!preg_match('/^[»•\-–]?\s*(.+?)\s*\|\s*(\d{1,2})\s*[h:]\s*(\d{2})/u', $linha, $m)) {
                if ($programa !== []) {
                    break; // acabou a lista
                }
                continue;
            }
            $programa[] = ['hora' => sprintf('%02d:%s', $m[2], $m[3]), 'prova' => $m[1], 'escaloes' => null];
        }

        return $programa !== [] ? $programa : null;
    }

    private static function eSite(string $host, string $site): bool
    {
        return $host === $site || str_ends_with($host, '.' . $site);
    }

    private static function texto(DOMXPath $xpath, string $consulta): ?string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $xpath->query($consulta)->item(0)?->textContent));

        return $texto !== '' ? $texto : null;
    }

    private static function link(DOMXPath $xpath, string $consulta): ?string
    {
        $url = self::texto($xpath, $consulta);
        if ($url !== null && !preg_match('#^https?://#i', $url)) {
            $url = preg_match('#^(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(/.*)?$#i', $url) ? 'https://' . $url : null;
        }

        return $url;
    }

    private static function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
