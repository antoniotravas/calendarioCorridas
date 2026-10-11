<?php

declare(strict_types=1);

/**
 * Encontra a imagem principal (cartaz) na página de um evento: a imagem de
 * pré-visualização para redes sociais (og:image / twitter:image) ou, em sites
 * WordPress sem ela, a "imagem de destaque" (img.wp-post-image).
 */
final class ImagemEventoHelper
{
    public static function extrair(string $html, string $urlPagina): ?string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $candidatas = [];
        foreach (['og:image:secure_url', 'og:image', 'twitter:image'] as $nome) {
            foreach ($xpath->query("//meta[@property='{$nome}' or @name='{$nome}']/@content") as $atributo) {
                $candidatas[] = $atributo->value;
            }
        }
        foreach ($xpath->query('//img[contains(@class,"wp-post-image")]/@src') as $atributo) {
            $candidatas[] = $atributo->value;
        }

        foreach ($candidatas as $candidata) {
            $url = self::absoluta(trim($candidata), $urlPagina);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Converte um endereço de imagem em absoluto e https (o site é servido
     * em https e os browsers bloqueiam imagens http). Recusa valores vazios,
     * "null" e SVG (quase sempre logótipos).
     */
    private static function absoluta(string $url, string $urlPagina): ?string
    {
        if ($url === '' || strcasecmp($url, 'null') === 0 || preg_match('/\.svg($|\?)/i', $url)) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (str_starts_with($url, '/')) {
            $partes = parse_url($urlPagina);
            if (!isset($partes['host'])) {
                return null;
            }
            $url = 'https://' . $partes['host'] . $url;
        }

        $url = preg_replace('#^http://#i', 'https://', $url);

        return preg_match('#^https://[^\s]+$#i', $url) && strlen($url) <= 500 ? $url : null;
    }
}
