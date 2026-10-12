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
 * formulário da página de destino (action="./{id}"). Não há tipo de prova:
 * decide-se pelo nome (NomeProvaHelper::pareceCorrida / parecePista).
 */
final class AALisboaScraper implements ScraperInterface
{
    private const SITE = 'https://competicoes.aalisboa.com.pt';
    private const LISTA_URL = self::SITE . '/competicoes';
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
            if ($nome === '' || !(NomeProvaHelper::pareceCorrida($nome) || NomeProvaHelper::parecePista($nome))) {
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

            $corridas[] = new Corrida(
                nome: $nome,
                dataProva: $dataProva,
                local: $local !== '' ? $local : null,
                distancias: null,
                tipo: null,
                regiao: 'Lisboa',
                urlEvento: $this->urlProva($xpath, $cartao, $campos),
                fonte: $this->nome(),
                urlFonte: self::LISTA_URL,
                imagemUrl: str_starts_with($imagem, '/') ? self::SITE . $imagem : null,
            );
        }

        return $corridas;
    }

    /**
     * Endereço da página da prova (/Competicoes/{id}), seguindo o botão "Entrar".
     * Se falhar, fica a lista de competições.
     *
     * @param array<string,string> $campos campos escondidos da página "Por Realizar"
     */
    private function urlProva(DOMXPath $xpath, DOMNode $cartao, array $campos): string
    {
        $href = $xpath->query('.//a[contains(@id,"linkButton_detail")]', $cartao)->item(0)?->getAttribute('href') ?? '';
        if (!preg_match("/__doPostBack\\('([^']+)'/", $href, $m)) {
            return self::LISTA_URL;
        }

        try {
            $destino = $this->http->post(self::LISTA_URL, ['__EVENTTARGET' => $m[1]] + $campos);
        } catch (RuntimeException) {
            return self::LISTA_URL;
        }

        return preg_match('/<form[^>]+action="\.\/(\d+)"/', $destino, $id)
            ? self::SITE . '/Competicoes/' . $id[1]
            : self::LISTA_URL;
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
