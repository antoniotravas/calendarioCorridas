<?php

declare(strict_types=1);

/**
 * Distrito (ou região autónoma) e região de uma prova, a partir do texto do
 * local ("Alcáçovas - Viana do Alentejo", "Chaves, Vila Real", "Escola
 * Superior Agrária de Coimbra").
 *
 * Procura no local os nomes dos 308 concelhos (e de algumas localidades
 * conhecidas que não são sede de concelho, ex. "Fátima", "Estoril"). Fica o
 * último nome encontrado — os locais costumam acabar no concelho ("Lagoa de
 * Albufeira, Sesimbra" → Sesimbra) — e, quando há nomes sobrepostos, o mais
 * comprido ("Porto de Mós" e não "Porto").
 */
final class DistritoHelper
{
    /** Distrito => concelhos. */
    private const CONCELHOS = [
        'Aveiro' => ['Águeda', 'Albergaria-a-Velha', 'Anadia', 'Arouca', 'Aveiro', 'Castelo de Paiva', 'Espinho', 'Estarreja',
            'Ílhavo', 'Mealhada', 'Murtosa', 'Oliveira de Azeméis', 'Oliveira do Bairro', 'Ovar', 'Santa Maria da Feira',
            'São João da Madeira', 'Sever do Vouga', 'Vagos', 'Vale de Cambra'],
        'Beja' => ['Aljustrel', 'Almodôvar', 'Alvito', 'Barrancos', 'Beja', 'Castro Verde', 'Cuba', 'Ferreira do Alentejo',
            'Mértola', 'Moura', 'Odemira', 'Ourique', 'Serpa', 'Vidigueira'],
        'Braga' => ['Amares', 'Barcelos', 'Braga', 'Cabeceiras de Basto', 'Celorico de Basto', 'Esposende', 'Fafe', 'Guimarães',
            'Póvoa de Lanhoso', 'Terras de Bouro', 'Vieira do Minho', 'Vila Nova de Famalicão', 'Vila Verde', 'Vizela'],
        'Bragança' => ['Alfândega da Fé', 'Bragança', 'Carrazeda de Ansiães', 'Freixo de Espada à Cinta', 'Macedo de Cavaleiros',
            'Miranda do Douro', 'Mirandela', 'Mogadouro', 'Torre de Moncorvo', 'Vila Flor', 'Vimioso', 'Vinhais'],
        'Castelo Branco' => ['Belmonte', 'Castelo Branco', 'Covilhã', 'Fundão', 'Idanha-a-Nova', 'Oleiros', 'Penamacor',
            'Proença-a-Nova', 'Sertã', 'Vila de Rei', 'Vila Velha de Ródão'],
        'Coimbra' => ['Arganil', 'Cantanhede', 'Coimbra', 'Condeixa-a-Nova', 'Figueira da Foz', 'Góis', 'Lousã', 'Mira',
            'Miranda do Corvo', 'Montemor-o-Velho', 'Oliveira do Hospital', 'Pampilhosa da Serra', 'Penacova', 'Penela',
            'Soure', 'Tábua', 'Vila Nova de Poiares'],
        'Évora' => ['Alandroal', 'Arraiolos', 'Borba', 'Estremoz', 'Évora', 'Montemor-o-Novo', 'Mora', 'Mourão', 'Portel',
            'Redondo', 'Reguengos de Monsaraz', 'Vendas Novas', 'Viana do Alentejo', 'Vila Viçosa'],
        'Faro' => ['Albufeira', 'Alcoutim', 'Aljezur', 'Castro Marim', 'Faro', 'Lagos', 'Loulé', 'Monchique', 'Olhão',
            'Portimão', 'São Brás de Alportel', 'Silves', 'Tavira', 'Vila do Bispo', 'Vila Real de Santo António'],
        'Guarda' => ['Aguiar da Beira', 'Almeida', 'Celorico da Beira', 'Figueira de Castelo Rodrigo', 'Fornos de Algodres',
            'Gouveia', 'Guarda', 'Manteigas', 'Mêda', 'Pinhel', 'Sabugal', 'Seia', 'Trancoso', 'Vila Nova de Foz Côa'],
        'Leiria' => ['Alcobaça', 'Alvaiázere', 'Ansião', 'Batalha', 'Bombarral', 'Caldas da Rainha', 'Castanheira de Pera',
            'Figueiró dos Vinhos', 'Leiria', 'Marinha Grande', 'Nazaré', 'Óbidos', 'Pedrógão Grande', 'Peniche', 'Pombal',
            'Porto de Mós'],
        'Lisboa' => ['Alenquer', 'Amadora', 'Arruda dos Vinhos', 'Azambuja', 'Cadaval', 'Cascais', 'Lisboa', 'Loures',
            'Lourinhã', 'Mafra', 'Odivelas', 'Oeiras', 'Sintra', 'Sobral de Monte Agraço', 'Torres Vedras', 'Vila Franca de Xira'],
        'Portalegre' => ['Alter do Chão', 'Arronches', 'Avis', 'Campo Maior', 'Castelo de Vide', 'Crato', 'Elvas', 'Fronteira',
            'Gavião', 'Marvão', 'Monforte', 'Nisa', 'Ponte de Sor', 'Portalegre', 'Sousel'],
        'Porto' => ['Amarante', 'Baião', 'Felgueiras', 'Gondomar', 'Lousada', 'Maia', 'Marco de Canaveses', 'Matosinhos',
            'Paços de Ferreira', 'Paredes', 'Penafiel', 'Porto', 'Póvoa de Varzim', 'Santo Tirso', 'Trofa', 'Valongo',
            'Vila do Conde', 'Vila Nova de Gaia'],
        'Santarém' => ['Abrantes', 'Alcanena', 'Almeirim', 'Alpiarça', 'Benavente', 'Cartaxo', 'Chamusca', 'Constância',
            'Coruche', 'Entroncamento', 'Ferreira do Zêzere', 'Golegã', 'Mação', 'Ourém', 'Rio Maior', 'Salvaterra de Magos',
            'Santarém', 'Sardoal', 'Tomar', 'Torres Novas', 'Vila Nova da Barquinha'],
        'Setúbal' => ['Alcácer do Sal', 'Alcochete', 'Almada', 'Barreiro', 'Grândola', 'Moita', 'Montijo', 'Palmela',
            'Santiago do Cacém', 'Seixal', 'Sesimbra', 'Setúbal', 'Sines'],
        'Viana do Castelo' => ['Arcos de Valdevez', 'Caminha', 'Melgaço', 'Monção', 'Paredes de Coura', 'Ponte da Barca',
            'Ponte de Lima', 'Valença', 'Viana do Castelo', 'Vila Nova de Cerveira'],
        'Vila Real' => ['Alijó', 'Boticas', 'Chaves', 'Mesão Frio', 'Mondim de Basto', 'Montalegre', 'Murça', 'Peso da Régua',
            'Ribeira de Pena', 'Sabrosa', 'Santa Marta de Penaguião', 'Valpaços', 'Vila Pouca de Aguiar', 'Vila Real'],
        'Viseu' => ['Armamar', 'Carregal do Sal', 'Castro Daire', 'Cinfães', 'Lamego', 'Mangualde', 'Moimenta da Beira',
            'Mortágua', 'Nelas', 'Oliveira de Frades', 'Penalva do Castelo', 'Penedono', 'Resende', 'Santa Comba Dão',
            'São João da Pesqueira', 'São Pedro do Sul', 'Sátão', 'Sernancelhe', 'Tabuaço', 'Tarouca', 'Tondela',
            'Vila Nova de Paiva', 'Viseu', 'Vouzela'],
        'Açores' => ['Angra do Heroísmo', 'Corvo', 'Horta', 'Lajes das Flores', 'Lajes do Pico', 'Madalena', 'Nordeste',
            'Ponta Delgada', 'Povoação', 'Ribeira Grande', 'Santa Cruz da Graciosa', 'Santa Cruz das Flores',
            'São Roque do Pico', 'Velas', 'Praia da Vitória', 'Vila do Porto', 'Vila Franca do Campo',
            'São Miguel', 'Terceira', 'Faial', 'Pico', 'São Jorge', 'Graciosa', 'Açores'],
        'Madeira' => ['Câmara de Lobos', 'Funchal', 'Machico', 'Ponta do Sol', 'Porto Moniz', 'Porto Santo', 'Ribeira Brava',
            'Santana', 'São Vicente', 'Madeira'],
    ];

    /**
     * Localidades conhecidas que não são concelho, abreviaturas e nomes
     * curtos ("Gaia", "Famalicão", "VRSA"): nome => distrito.
     */
    private const OUTROS_NOMES = [
        'Gaia' => 'Porto', 'Leça da Palmeira' => 'Porto', 'Leça' => 'Porto', 'Ermesinde' => 'Porto', 'Rio Tinto' => 'Porto',
        'Foz do Douro' => 'Porto', 'Famalicão' => 'Braga', 'VN Famalicão' => 'Braga', 'Caldas das Taipas' => 'Braga',
        'Póvoa de Varzim' => 'Porto', 'Feira' => 'Aveiro', 'Esmoriz' => 'Aveiro', 'Cortegaça' => 'Aveiro', 'Lourosa' => 'Aveiro',
        'Fiães' => 'Aveiro', 'Costa Nova' => 'Aveiro', 'Gafanha' => 'Aveiro', 'Fátima' => 'Santarém', 'Estoril' => 'Lisboa',
        'Carcavelos' => 'Lisboa', 'Parede' => 'Lisboa', 'Queluz' => 'Lisboa', 'Cacém' => 'Lisboa', 'Ericeira' => 'Lisboa',
        'Sacavém' => 'Lisboa', 'Belém' => 'Lisboa', 'Parque das Nações' => 'Lisboa', 'Monsanto' => 'Lisboa', 'Algés' => 'Lisboa',
        'Costa da Caparica' => 'Setúbal', 'Caparica' => 'Setúbal', 'Tróia' => 'Setúbal', 'Porto Covo' => 'Setúbal',
        'Vilamoura' => 'Faro', 'Quarteira' => 'Faro', 'Almancil' => 'Faro', 'Monte Gordo' => 'Faro', 'Armação de Pêra' => 'Faro',
        'Praia da Rocha' => 'Faro', 'Sagres' => 'Faro', 'VRSA' => 'Faro', 'Vila Real de Sto António' => 'Faro', 'Algarve' => 'Faro',
        'Foz do Arelho' => 'Leiria', 'São Martinho do Porto' => 'Leiria', 'Mira de Aire' => 'Leiria', 'Caldas' => 'Leiria',
        'Figueira' => 'Coimbra', 'Buarcos' => 'Coimbra', 'Serra da Estrela' => 'Guarda', 'Penhas da Saúde' => 'Castelo Branco',
        'Régua' => 'Vila Real', 'Foz Côa' => 'Guarda', 'Vila Viçosa' => 'Évora', 'Monsaraz' => 'Évora', 'Gerês' => 'Braga',
        'Vila da Praia da Vitória' => 'Açores', 'Calheta de São Jorge' => 'Açores', 'Santa Cruz' => 'Madeira',
    ];

    /** Região de cada distrito (as que os calendários de corridas costumam usar). */
    private const REGIOES = [
        'Viana do Castelo' => 'Norte', 'Braga' => 'Norte', 'Porto' => 'Norte', 'Vila Real' => 'Norte', 'Bragança' => 'Norte',
        'Aveiro' => 'Centro', 'Viseu' => 'Centro', 'Guarda' => 'Centro', 'Coimbra' => 'Centro', 'Castelo Branco' => 'Centro',
        'Leiria' => 'Centro', 'Lisboa' => 'Lisboa e Vale do Tejo', 'Setúbal' => 'Lisboa e Vale do Tejo',
        'Santarém' => 'Lisboa e Vale do Tejo', 'Portalegre' => 'Alentejo', 'Évora' => 'Alentejo', 'Beja' => 'Alentejo',
        'Faro' => 'Algarve', 'Açores' => 'Açores', 'Madeira' => 'Madeira',
    ];

    /** Nomes com o mesmo texto em dois sítios: só se usa quando a região da fonte o confirma. */
    private const AMBIGUOS = ['Lagoa' => ['Faro', 'Açores'], 'Calheta' => ['Madeira', 'Açores']];

    /** @var array<string,string>|null nome normalizado => distrito, do mais comprido para o mais curto */
    private static ?array $indice = null;

    /**
     * @param ?string $regiao região indicada pela fonte (ex. "Açores", "Braga"),
     *        usada quando o local não diz o concelho
     * @return array{distrito: ?string, regiao: ?string}
     */
    public static function localizar(?string $local, ?string $regiao = null): array
    {
        $distrito = null;
        $regiaoNormal = self::normalizar((string) $regiao);

        $texto = ' ' . self::normalizar((string) $local) . ' ';
        if (trim($texto) !== '') {
            $melhor = null; // [posição, comprimento, distrito]
            foreach (self::indice() as $nome => $d) {
                $pos = strrpos($texto, ' ' . $nome . ' ');
                if ($pos === false) {
                    continue;
                }
                $fim = $pos + strlen($nome);
                // Fica o que acaba mais à direita; se acabarem no mesmo sítio, o mais comprido.
                if ($melhor === null || $fim > $melhor[0] + $melhor[1] || ($fim === $melhor[0] + $melhor[1] && strlen($nome) > $melhor[1])) {
                    // Um nome dentro de outro já encontrado ("Porto" em "Porto de Mós") não conta.
                    if ($melhor !== null && $pos >= $melhor[0] && $fim <= $melhor[0] + $melhor[1]) {
                        continue;
                    }
                    $melhor = [$pos, strlen($nome), $d];
                }
            }
            if ($melhor !== null) {
                $distrito = $melhor[2];
            } else {
                foreach (self::AMBIGUOS as $nome => $opcoes) {
                    if (str_contains($texto, ' ' . self::normalizar($nome) . ' ')) {
                        $distrito = in_array($regiao, $opcoes, true) ? $regiao : $opcoes[0];
                    }
                }
            }
        }

        // A região da fonte (associações distritais: "Braga", "Açores"...) quando o local não chega.
        if ($distrito === null && $regiaoNormal !== '') {
            foreach (array_keys(self::REGIOES) as $d) {
                if (self::normalizar($d) === $regiaoNormal) {
                    $distrito = $d;
                }
            }
        }

        return ['distrito' => $distrito, 'regiao' => $distrito !== null ? self::REGIOES[$distrito] : null];
    }

    /** "Alcáçovas - Viana do Alentejo" → "alcacovas viana do alentejo". */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto);
        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o',
            'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'ü' => 'u',
        ]);
        $texto = (string) preg_replace('/\bs\.\s*/', 'sao ', $texto); // "S. Martinho" = "São Martinho"
        $texto = (string) preg_replace('/\bsto\.?\s/', 'santo ', $texto);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texto));
    }

    /** @return array<string,string> */
    private static function indice(): array
    {
        if (self::$indice === null) {
            $indice = [];
            foreach (self::CONCELHOS as $distrito => $concelhos) {
                foreach ($concelhos as $concelho) {
                    $indice[self::normalizar($concelho)] = $distrito;
                }
            }
            foreach (self::OUTROS_NOMES as $nome => $distrito) {
                $indice[self::normalizar($nome)] = $distrito;
            }
            uksort($indice, fn (string $a, string $b) => strlen($b) <=> strlen($a));
            self::$indice = $indice;
        }

        return self::$indice;
    }
}
