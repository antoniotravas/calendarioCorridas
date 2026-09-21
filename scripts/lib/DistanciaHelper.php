<?php

declare(strict_types=1);

/**
 * Extrai distâncias numéricas (em km) a partir do texto livre guardado em
 * corridas.distancias (ex: "10K, Caminhada", "5-10 km", "Meia-Maratona").
 */
final class DistanciaHelper
{
    /** Faixas usadas no filtro de distância da página pública. */
    public const FAIXAS = [
        '0-5' => ['label' => 'Até 5 km', 'min' => 0, 'max' => 5],
        '5-10' => ['label' => '5 a 10 km', 'min' => 5.01, 'max' => 10],
        '10-15' => ['label' => '10 a 15 km', 'min' => 10.01, 'max' => 15],
        '15-21' => ['label' => '15 a 21 km (Meia-Maratona)', 'min' => 15.01, 'max' => 21.1],
        '21-42' => ['label' => '21 a 42 km (Maratona)', 'min' => 21.11, 'max' => 42.2],
        '42+' => ['label' => 'Mais de 42 km', 'min' => 42.21, 'max' => 999],
    ];

    /**
     * @return array{0:float,1:float}|null
     */
    public static function intervaloPorChave(?string $chave): ?array
    {
        if ($chave === null || !isset(self::FAIXAS[$chave])) {
            return null;
        }

        return [self::FAIXAS[$chave]['min'], self::FAIXAS[$chave]['max']];
    }

    /**
     * @return float[] distâncias em km, sem duplicados
     */
    public static function extrairKm(?string $texto): array
    {
        if ($texto === null || trim($texto) === '') {
            return [];
        }

        $texto = mb_strtolower($texto);
        $valores = [];

        if (preg_match('/meia[\s-]?maratona/u', $texto)) {
            $valores[] = 21.1;
        }
        if (preg_match('/(?<!meia[\s-])\bmaratona\b/u', $texto)) {
            $valores[] = 42.2;
        }

        // Formatos "10K", "10 km", "5,5 km", "5.5k"
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*k(?:m)?\b/u', $texto, $m)) {
            foreach ($m[1] as $valor) {
                $valores[] = (float) str_replace(',', '.', $valor);
            }
        }

        $valores = array_values(array_unique($valores));
        sort($valores);

        return $valores;
    }
}
