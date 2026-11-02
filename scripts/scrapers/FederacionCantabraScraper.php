<?php

declare(strict_types=1);

/**
 * Recolhe provas da Federación Cántabra de Atletismo (fcatle.com).
 *
 * O site publica o calendário através da exportação do plugin de eventos
 * (?plugin=all-in-one-event-calendar&action=export_events&xml=true): um
 * iCalendar convertido em XML, com um evento (vevent) por prova. Lê-se o
 * feed inteiro e fica-se com as provas futuras de corrida (cross, trail,
 * maratona, pedestre...). Cursos, assembleias e provas de pista ficam de fora.
 *
 * O link da prova é a página do evento na própria federação.
 */
final class FederacionCantabraScraper implements ScraperInterface
{
    private const BASE_URL = 'https://www.fcatle.com';
    private const FEED_URL = self::BASE_URL
        . '/?plugin=all-in-one-event-calendar&controller=ai1ec_exporter_controller&action=export_events&xml=true';

    /** Palavras do nome que indicam uma corrida (o resto do calendário não interessa). */
    private const PADRAO_CORRIDA = '/\b(carrera|carreras|cross|trail|marat[oó]n|maraton|media maratón|pedestre|kil[oó]metros?|milla|subida|corre)\b/iu';

    /** Palavras que indicam prova cancelada ou adiada: não se gravam. */
    private const PADRAO_CANCELADA = '/aplazad|cancelad|suspendid|suspended|cancelled|postponed/iu';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'Federación Cántabra';
    }

    public function scrape(): array
    {
        $xml = $this->http->get(self::FEED_URL);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            libxml_clear_errors();
            throw new RuntimeException('Feed XML inválido em ' . self::FEED_URL);
        }
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $eventos = $xpath->query('//*[local-name()="vevent"]');
        if ($eventos->length === 0) {
            throw new RuntimeException('Estrutura inesperada em ' . self::FEED_URL . ' (eventos não encontrados)');
        }

        $hoje = new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid'));
        $corridas = [];
        foreach ($eventos as $evento) {
            $nome = $this->texto($xpath, $evento, 'summary');
            $data = $this->data($xpath, $evento);
            if ($nome === '' || $data === null || $data < $hoje) {
                continue;
            }
            if (preg_match(self::PADRAO_CANCELADA, $nome) || !preg_match(self::PADRAO_CORRIDA, $nome)) {
                continue;
            }

            $local = $this->texto($xpath, $evento, 'location');
            $url = $this->texto($xpath, $evento, 'url');

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $data->format('Y-m-d'),
                local: $local !== '' ? $this->localSemRua($local) : null,
                distancias: null,
                tipo: null, // o tipo sai do nome (NomeProvaHelper::categoria)
                regiao: 'Cantabria',
                urlEvento: $url !== '' ? $url : null,
                fonte: $this->nome(),
                urlFonte: self::BASE_URL,
                organizador: null,
                organizadorUrl: null,
                pais: 'ES',
                distrito: 'Cantabria',
            );
        }

        return $corridas;
    }

    /** Data de início do evento (dtstart), como DateTimeImmutable no fuso de Madrid. */
    private function data(DOMXPath $xpath, DOMNode $evento): ?DateTimeImmutable
    {
        $valor = $this->texto($xpath, $evento, 'dtstart');
        // O texto pode trazer antes o fuso horário ("Europe/Madrid2026-11-05T10:00:00").
        if (!preg_match('/(\d{4})-?(\d{2})-?(\d{2})/', $valor, $m)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%s-%s-%s', $m[1], $m[2], $m[3]), new DateTimeZone('Europe/Madrid'));
    }

    /**
     * O local vem como "Santander, Cantabria @ Parque de Las Llamas": fica o
     * que está depois do "@" (o sítio), seguido da localidade.
     */
    private function localSemRua(string $local): string
    {
        $partes = array_map('trim', explode('@', $local, 2));
        $sitio = $partes[1] ?? '';
        $localidade = preg_replace('/,\s*Cantabria$/u', '', $partes[0]) ?? $partes[0];

        return $sitio !== '' ? "{$sitio}, {$localidade}" : $localidade;
    }

    /** Texto do primeiro elemento com este nome dentro do evento (summary, dtstart, url...). */
    private function texto(DOMXPath $xpath, DOMNode $evento, string $nome): string
    {
        $no = $xpath->query(".//*[local-name()=\"{$nome}\"]", $evento)->item(0);
        if ($no === null) {
            return '';
        }

        // O valor pode estar no próprio elemento ou num filho (ex. <dtstart><date-time>...).
        $texto = trim($no->textContent);

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    }
}
