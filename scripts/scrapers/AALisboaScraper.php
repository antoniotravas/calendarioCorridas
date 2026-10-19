<?php

declare(strict_types=1);

/**
 * Recolhe provas de corrida e de pista da plataforma de competições da
 * Associação de Atletismo de Lisboa (competicoes.aalisboa.com.pt — o site
 * principal da AAL é Wix e só tem ligações para aqui).
 *
 * É uma aplicação ASP.NET WebForms: a página /competicoes abre no separador
 * "Abertos" (só as provas com inscrições abertas); para ver todas as provas
 * futuras é preciso repetir o que o botão "Por Realizar" faz — um POST da
 * própria página (__doPostBack) com os campos escondidos (__VIEWSTATE...) e
 * __EVENTTARGET a apontar para o botão, na mesma sessão (cookies).
 *
 * Cada cartão tem data (<time datetime="17-10-2026">), nome, local e cartaz,
 * mas o botão "Entrar" é outro __doPostBack que redireciona para a página da
 * prova (/Competicoes/{id}); faz-se esse pedido por prova e lê-se o id no
 * formulário da página de destino (action="./{id}"). Entram todas as
 * competições menos as ações de formação (eCompeticao); o tipo (estrada,
 * trail, pista...) decide-se depois pelo nome.
 *
 * Também se leem os circuitos e troféus (/circuitos), que têm provas que não
 * aparecem na lista "Por Realizar" (provasDosCircuitos).
 */
final class AALisboaScraper implements ScraperInterface
{
    private const SITE = 'https://competicoes.aalisboa.com.pt';
    private const LISTA_URL = self::SITE . '/competicoes';
    private const CIRCUITOS_URL = self::SITE . '/circuitos';
    private const BOTAO_POR_REALIZAR = 'ctl00$MainContent$OpcoesBotoes$BotaoBig_2$LinkButton';

    /** Precisa de um HttpClient com cookies (new HttpClient(guardarCookies: true)). */
    public function __construct(private readonly HttpClient $http)
    {
    }

    public function nome(): string
    {
        return 'AA Lisboa';
    }

    public function scrape(): array
    {
        $inicial = $this->criarXPath($this->http->get(self::LISTA_URL));
        $html = $this->http->post(self::LISTA_URL, $this->camposPostBack($inicial, self::BOTAO_POR_REALIZAR));
        $xpath = $this->criarXPath($html);

        $cartoes = $xpath->query('//ul[contains(@class,"event-list")]/li[time]');
        if ($cartoes->length === 0 && !str_contains($html, 'Por Realizar')) {
            throw new RuntimeException('Estrutura inesperada em ' . self::LISTA_URL . ' (lista de competições não encontrada)');
        }

        // Campos escondidos desta página, reaproveitados nos botões "Entrar" de todas as provas.
        $campos = $this->camposPostBack($xpath, '');
        $hoje = date('Y-m-d');
        $corridas = [];

        foreach ($cartoes as $cartao) {
            $nome = trim((string) preg_replace('/\s+/u', ' ', $xpath->query('.//h2[contains(@class,"title")]', $cartao)->item(0)?->textContent ?? ''));
            if (!$this->eCompeticao($nome)) {
                continue;
            }

            $data = $xpath->query('./time', $cartao)->item(0)?->getAttribute('datetime') ?? '';
            $dataProva = null;
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', trim($data), $d) && checkdate((int) $d[2], (int) $d[1], (int) $d[3])) {
                $dataProva = "{$d[3]}-{$d[2]}-{$d[1]}";
            }
            if ($dataProva !== null && $dataProva < $hoje) {
                continue;
            }

            $local = trim((string) preg_replace(
                '/\s+/u',
                ' ',
                $xpath->query('.//div[contains(@id,"div_local")]', $cartao)->item(0)?->textContent ?? ''
            ));
            $imagem = $xpath->query('./img', $cartao)->item(0)?->getAttribute('src') ?? '';
            [$urlProva, $urlInscricao] = $this->urlProva($xpath, $cartao, $campos);

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: null,
                regiao: 'Lisboa',
                urlEvento: $urlProva,
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
                imagemUrl: str_starts_with($imagem, '/') ? self::SITE . $imagem : null,
                urlInscricao: $urlInscricao,
            );
        }

        $jaVistas = [];
        foreach ($corridas as $corrida) {
            $jaVistas[(string) $corrida->urlEvento] = true;
        }
        foreach ($this->provasDosCircuitos($hoje) as $corrida) {
            if (!isset($jaVistas[(string) $corrida->urlEvento])) {
                $jaVistas[(string) $corrida->urlEvento] = true;
                $corridas[] = $corrida;
            }
        }

        return $corridas;
    }

    /**
     * Uma plataforma de atletismo: todas as competições contam (estrada, trail,
     * corta-mato, pista, estafetas, "Linhas De Torres 100"...), menos as ações
     * de formação e afins que aparecem na mesma lista.
     */
    private function eCompeticao(string $nome): bool
    {
        return $nome !== ''
            && !preg_match('/\bcurso\b|reciclagem|forma[cç][aã]o|ju[ií]z(es)?\b|reuni[aã]o|assembleia|\bgala\b/iu', $nome);
    }

    /**
     * Provas dos circuitos e troféus (/circuitos): algumas só aparecem aqui
     * (ex. "4ª ETCA Légua da Amadora", sem inscrições abertas na plataforma).
     * Cada circuito abre com um __doPostBack ("Ver Detalhe"); cada prova tem
     * o id da competição (hdnId → /Competicoes/{id}), de onde se lê o local.
     *
     * @return Corrida[]
     */
    private function provasDosCircuitos(string $hoje): array
    {
        try {
            $lista = $this->criarXPath($this->http->get(self::CIRCUITOS_URL));
        } catch (RuntimeException) {
            return []; // os circuitos são um extra: a lista principal já foi lida
        }
        $botoes = [];
        foreach ($lista->query('//a[contains(@id,"link_circuito")]') as $a) {
            if (preg_match("/__doPostBack\\('([^']+)'/", $a->getAttribute('href'), $m)) {
                $botoes[] = $m[1];
            }
        }

        $porId = [];
        foreach ($botoes as $botao) {
            try {
                // A página dos circuitos é pedida de novo antes de cada botão (campos escondidos frescos).
                $pagina = $this->criarXPath($this->http->get(self::CIRCUITOS_URL));
                $xpath = $this->criarXPath($this->http->post(self::CIRCUITOS_URL, $this->camposPostBack($pagina, $botao)));
            } catch (RuntimeException) {
                continue;
            }
            foreach ($xpath->query('//input[contains(@name,"CardEventosCircuitos$hdnId")]') as $input) {
                $id = (int) $input->getAttribute('value');
                $cartao = $xpath->query('following-sibling::div[1]', $input)->item(0);
                if ($id <= 0 || $cartao === null || isset($porId[$id])) {
                    continue;
                }
                $nome = trim((string) preg_replace('/\s+/u', ' ', $xpath->query('.//h5[contains(@class,"card-title")]', $cartao)->item(0)?->textContent ?? ''));
                $data = $xpath->query('.//span[contains(@id,"_data_")]', $cartao)->item(0)?->textContent ?? '';
                $dataProva = preg_match('/(\d{1,2})\s+de\s+(\p{L}+)\s+de\s+(\d{4})/u', $data, $d)
                    ? DateHelper::toIso((int) $d[1], $d[2], (int) $d[3])
                    : null;
                if (!$this->eCompeticao($nome) || $dataProva === null || $dataProva < $hoje) {
                    continue;
                }
                $imagem = $xpath->query('.//img', $cartao)->item(0)?->getAttribute('src') ?? '';
                $porId[$id] = [$nome, $dataProva, str_starts_with($imagem, '/') ? self::SITE . $imagem : null];
            }
        }

        $corridas = [];
        foreach ($porId as $id => [$nome, $dataProva, $imagem]) {
            $url = self::SITE . '/Competicoes/' . $id;
            $local = null;
            $urlInscricao = null;
            try {
                $pagina = $this->http->get($url);
                $local = $this->criarXPath($pagina)
                    ->query('//*[@id="MainContent_EventoDetalhe_span_localidade"]')->item(0)?->textContent;
                $urlInscricao = $this->inscricaoDaPagina($pagina, $url);
            } catch (RuntimeException) {
            }
            $local = trim((string) $local);

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: null,
                regiao: 'Lisboa',
                urlEvento: $url,
                fonte: $this->nome(),
                urlFonte: self::CIRCUITOS_URL,
                imagemUrl: $imagem,
                urlInscricao: $urlInscricao,
            );
        }

        return $corridas;
    }

    /**
     * Endereço da página da prova (/Competicoes/{id}), seguindo o botão "Entrar",
     * e o link da inscrição lido nessa página. Se falhar, fica a lista de competições.
     *
     * @param array<string,string> $campos campos escondidos da página "Por Realizar"
     * @return array{0: string, 1: ?string} [página da prova, link da inscrição]
     */
    private function urlProva(DOMXPath $xpath, DOMNode $cartao, array $campos): array
    {
        $href = $xpath->query('.//a[contains(@id,"linkButton_detail")]', $cartao)->item(0)?->getAttribute('href') ?? '';
        if (!preg_match("/__doPostBack\\('([^']+)'/", $href, $m)) {
            return [self::LISTA_URL, null];
        }

        try {
            $destino = $this->http->post(self::LISTA_URL, ['__EVENTTARGET' => $m[1]] + $campos);
        } catch (RuntimeException) {
            return [self::LISTA_URL, null];
        }

        if (!preg_match('/<form[^>]+action="\.\/(\d+)"/', $destino, $id)) {
            return [self::LISTA_URL, null];
        }
        $url = self::SITE . '/Competicoes/' . $id[1];

        return [$url, $this->inscricaoDaPagina($destino, $url)];
    }

    /**
     * Onde é a inscrição, pela página da prova: com o botão "Inscrever" é na
     * própria página (filiados com login; não filiados num formulário); senão, o link em
     * "Informação" (span_site), que a AAL usa para a inscrição no site do
     * organizador ou noutra prova da plataforma ("inscritos no torneio da
     * Amadora estão inscritos no Regional"). Sem nenhum, null.
     */
    private function inscricaoDaPagina(string $html, string $url): ?string
    {
        $xpath = $this->criarXPath($html);
        foreach ($xpath->query('//a[contains(@id,"EventoDetalhe_BotaoBig")]//h4') as $titulo) {
            // "Inscrever", "Inscrever (Filiados)", "Inscrever (Não Filiados)".
            if (str_starts_with(trim($titulo->textContent), 'Inscrever')) {
                return $url;
            }
        }

        $site = trim($xpath->query('//a[@id="MainContent_EventoDetalhe_span_site"]')->item(0)?->getAttribute('href') ?? '');

        return preg_match('#^https?://#i', $site) ? $site : null;
    }

    /**
     * Campos escondidos do formulário ASP.NET mais o botão "carregado".
     *
     * @return array<string,string>
     */
    private function camposPostBack(DOMXPath $xpath, string $alvo): array
    {
        $campos = [];
        foreach ($xpath->query('//form//input[@type="hidden"]') as $input) {
            $campos[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $campos['__EVENTTARGET'] = $alvo;
        $campos['__EVENTARGUMENT'] = '';

        return $campos;
    }

    private function criarXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
