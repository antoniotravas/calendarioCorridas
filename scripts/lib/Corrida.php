<?php

declare(strict_types=1);

/**
 * Representa uma corrida normalizada, pronta a gravar na base de dados.
 */
final class Corrida
{
    public function __construct(
        public readonly string $nome,
        public readonly ?string $dataProva,   // formato "Y-m-d", ou null se não apurado
        public readonly ?string $local,
        public readonly ?string $distancias,
        public readonly ?string $tipo,
        public readonly ?string $regiao,
        public readonly ?string $urlEvento,
        public readonly string $fonte,
        public readonly ?string $urlFonte,
    ) {
    }

    /**
     * Hash usado para deduplicar entre execuções do scraper.
     */
    public function hashDedup(): string
    {
        $chave = mb_strtolower(trim($this->nome))
            . '|' . ($this->dataProva ?? '')
            . '|' . mb_strtolower(trim($this->local ?? ''));

        return sha1($chave);
    }
}
