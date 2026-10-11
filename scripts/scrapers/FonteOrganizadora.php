<?php

declare(strict_types=1);

/**
 * Marca as fontes que organizam as provas (ou gerem as inscrições): o link
 * delas é a página oficial do evento, por isso tem prioridade sobre o link de
 * qualquer outra fonte onde a mesma prova apareça
 * (ver Database::aplicarLinksOrganizadores).
 */
interface FonteOrganizadora
{
}
