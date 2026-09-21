<?php

declare(strict_types=1);

interface ScraperInterface
{
    /**
     * Nome curto da fonte, usado no campo "fonte" da tabela corridas.
     */
    public function nome(): string;

    /**
     * @return Corrida[]
     */
    public function scrape(): array;
}
