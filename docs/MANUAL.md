# Manual do Programador — CalendarioCorridas

## Objetivo

Aplicação para listar o calendário de corridas em Portugal — estrada, trail (incluindo ultra trail), corta-mato e competições de pista (ar livre, coberta e pista curta) — com filtros por mês e distância, dados em português europeu, e ligação ao site do evento e à fonte onde foi encontrado. Cada utilizador pode ainda criar conta e guardar as suas próprias provas, como um "carrinho" pessoal ("O meu calendário").

## Tecnologias

- **PHP 8.0+** (usa propriedades `readonly`, `str_starts_with`, `match`), sem dependências externas — o parsing de HTML usa `DOMDocument`/`DOMXPath` nativos e os pedidos HTTP usam `cURL`, para correr diretamente num XAMPP/WAMP sem `composer install`.
- **MySQL** (InnoDB, utf8mb4) para persistência.
- Sessões nativas do PHP (`session_start()`) para autenticação; passwords com `password_hash()`/`password_verify()`; login com Google via OAuth 2.0 (implementado à mão com cURL, sem SDK).

## Arquitetura geral

```
scripts/    → recolha de dados (scraper CLI) + classes partilhadas (lib/)
public/     → site público (document root do servidor web): calendário, login, "o meu calendário"
sql/        → schema da base de dados
docs/       → este manual
```

`public/` e `scripts/` partilham as classes em `scripts/lib/` (`Corrida`, `Database`, `DateHelper`, `DistanciaHelper`, `HttpClient`) — o site público só lê da base de dados, nunca faz scraping diretamente.

## Fluxo de dados (scraper)

```
Site de origem (HTML)
      │  HttpClient::get()
      ▼
Scraper específico da fonte (All4RunningScraper, AABragaScraper, TheEventsCalendarScraper, ...)
      │  DOMDocument + DOMXPath, JSON embutido na página ou API REST → extrai e normaliza campos
      ▼
Corrida (objeto normalizado: nome, data, local, distâncias, tipo, região, url_evento, fonte, url_fonte)
      │  Database::upsertCorrida()  (junta a mesma prova vinda de fontes diferentes; sincroniza corridas_distancias)
      ▼
Tabelas `corridas` + `corridas_fontes` + `corridas_distancias` (MySQL)
      │  no fim: Database::consolidarRepetidas()  (junta repetidas que já estejam na BD)
      │          links oficiais da All4Running (botão "Visitar o site oficial" de cada página, até 50 por execução)
      │          Database::aplicarLinksOrganizadores()
      │          miniaturas: ImagemEventoHelper (og:image das páginas de evento, até 80 provas por execução)
      │          prémios monetários: PremiosHelper + PdfTextoHelper (página e regulamento, as que couberem em 10 minutos — ProcuraPremios)
```

`scripts/run_scraper.php` é o ponto de entrada: instancia todos os scrapers, corre cada um dentro de um `try/catch` (uma fonte a falhar não impede as restantes) e grava os resultados. No fim imprime (em STDERR) uma secção **"ATENÇÃO — fontes com problemas"** quando uma fonte falhou, ou quando devolveu 0 provas apesar de ter provas futuras na BD — sinal de que o site mudou e o scraper precisa de revisão.

### A mesma prova em várias fontes

A mesma prova aparece muitas vezes em vários sites (ex. All4Running, FPA Competições e a associação distrital), com nomes ligeiramente diferentes ("Meia Maratona Cidade Berço" / "Meia Maratona Cidade Berço - Guimarães"). Fica **uma só linha em `corridas`** e **uma linha em `corridas_fontes` por cada fonte** onde foi encontrada; o site público mostra todas ("Fontes: A · B · C").

`Database::upsertCorrida()` decide, para cada prova recolhida:
1. esta fonte já a tinha publicado (mesmo `fonte` + `hash_dedup` em `corridas_fontes`) → atualiza;
2. há na **mesma data** uma prova de **outra** fonte com nome equivalente (`NomeProvaHelper::mesmaProva()`) → junta-lhe esta fonte;
3. senão → cria prova nova.

A fonte que criou a prova (`corridas.fonte`, "fonte principal") define os dados; as outras só preenchem campos vazios (local, distâncias, tipo, região, link do evento).

`NomeProvaHelper::mesmaProva()` normaliza os nomes (minúsculas, sem acentos, sem números/ordinais/anos/distâncias, "GP" = "Grande Prémio", "S." = "São"), põe de parte palavras genéricas ("corrida", "meia", "maratona", "são silvestre", "cidade"...) e exige que pelo menos metade das palavras distintivas coincidam. Recusa sempre formatos diferentes ("Maratona do Porto" ≠ "Meia Maratona do Porto"). Nomes só com palavras genéricas — frequentes na pista: "Torneio de Abertura", "Meeting Jovem", em várias associações no mesmo fim de semana — só se juntam se forem iguais **e** os locais tiverem alguma palavra em comum (ou um deles não tiver local). É conservadora de propósito: um par não detetado fica repetido, mas provas diferentes dificilmente são fundidas por engano (ex. "São Silvestre de Vizela" ≠ "São Silvestre de Amares"). Datas diferentes entre fontes (erro de um dia num dos sites) não são juntadas.

**Exceção — fontes organizadoras (Xistarca, Trilho Perdido, Lap2Go, Stop and Go, Acorrer, Recorde Pessoal, Runporto, ProEvents):** são organizadores ou plataformas de inscrição, por isso o link delas é a página oficial da prova. Os scrapers implementam a interface vazia `FonteOrganizadora` e, no fim de cada execução, `Database::aplicarLinksOrganizadores()` põe em `corridas.url_evento` o link da fonte organizadora sempre que a prova aparece numa delas, mesmo que a fonte principal seja outra (usa os links guardados em `corridas_fontes`, por isso vale mesmo quando a organizadora falhou nessa execução). Para tornar outra fonte organizadora, basta acrescentar `, FonteOrganizadora` ao `implements` do scraper; a ordem em `$scrapers` define a prioridade.

`Database::consolidarRepetidas()` corre no fim de cada execução e aplica a mesma regra às provas já gravadas: a mais antiga fica, a outra passa-lhe as fontes e os favoritos dos utilizadores e é apagada.

## Estrutura de pastas

```
sql/schema.sql                       -- schema completo (corridas, corridas_fontes, corridas_distancias, utilizadores, favoritos)
sql/migracao_002_corridas_fontes.sql -- para BDs criadas antes de corridas_fontes (correr uma vez)
sql/migracao_003_ics_token.sql       -- para BDs criadas antes do link de subscrição .ics (correr uma vez)
sql/migracao_004_imagens.sql         -- para BDs criadas antes das miniaturas (correr uma vez)
sql/migracao_005_categoria.sql       -- para BDs criadas antes do filtro "Tipo" (correr uma vez)
sql/migracao_006_links_oficiais.sql  -- tabela links_oficiais (link oficial lido das páginas da All4Running e da Portugal Running)
sql/migracao_007_premios.sql         -- colunas premios_* em corridas (ícone "€" e filtro "Prémios")
sql/migracao_008_permissoes.sql      -- colunas e_admin e ver_premios em utilizadores (gestão de acessos)
sql/migracao_009_detalhes.sql        -- hora_inicio, programa, organizador, organizador_url, detalhes_procurado_em e premios_tabela em corridas
sql/migracao_010_premios_tabela.sql  -- volta a ler uma vez os valores dos prémios de todas as provas com prémios
sql/migracao_011_utilizadores_ativos.sql -- coluna ativo em utilizadores (desativar contas na página Gestão)
sql/migracao_012_inscricoes.sql      -- colunas inscricao_* em corridas (valor da inscrição e filtro "Inscrição")
sql/migracao_013_coordenadas.sql     -- latitude/longitude em corridas (botão "Perto de mim")

scripts/config.example.php           -- template de credenciais (copiar para config.php)
scripts/lib/Corrida.php              -- objeto de valor com os dados normalizados de uma prova
scripts/lib/HttpClient.php           -- pedidos GET/POST via cURL
scripts/lib/DateHelper.php           -- datas em português: parsing, formatação, agrupar por mês
scripts/lib/DistanciaHelper.php      -- extrai distâncias (km) do texto livre; faixas do filtro de distância
scripts/lib/NomeProvaHelper.php      -- mesma prova com nomes diferentes? parece corrida / prova de pista (pelo nome)? estrada, trail, corta-mato ou pista?
scripts/lib/PremiosHelper.php        -- há prémios monetários? (analisa o texto; segue os links do regulamento) e tabela dos valores
scripts/lib/DetalhesProvaHelper.php  -- hora, programa e organizador lidos nas páginas das provas (Lap2Go, Stop and Go, Acorrer, Recorde Pessoal, Xistarca)
scripts/lib/DistritoHelper.php       -- distrito e região a partir do local (308 concelhos + localidades conhecidas)
scripts/lib/PdfTextoHelper.php       -- extrai o texto de PDFs (regulamentos) em PHP puro
scripts/lib/ProcuraPremios.php       -- percorre as provas por ver, com tempo máximo (usado pelo scraper e por procurar_premios.php)
scripts/lib/InscricaoHelper.php      -- lê o valor da inscrição no texto de uma página ou regulamento; faixas do filtro "Inscrição"
scripts/lib/ProcuraInscricoes.php    -- percorre as provas por ver e grava o valor da inscrição (scraper e procurar_inscricoes.php)
scripts/lib/Geocodificador.php       -- coordenadas das localidades (Nominatim/OpenStreetMap) e distância em km
scripts/lib/Database.php             -- ligação PDO: upsert/junção de corridas, listagem com filtros, utilizadores, favoritos
scripts/scrapers/ScraperInterface.php
scripts/scrapers/All4RunningScraper.php
scripts/scrapers/PortugalRunningScraper.php
scripts/scrapers/AAAPortoScraper.php
scripts/scrapers/FpaCompeticoesScraper.php
scripts/scrapers/AABragaScraper.php
scripts/scrapers/AdalLeiriaScraper.php
scripts/scrapers/AdacCoimbraScraper.php
scripts/scrapers/AALisboaScraper.php          -- app ASP.NET WebForms: simula os botões (__doPostBack)
scripts/scrapers/TheEventsCalendarScraper.php  -- genérico: sites WordPress com o plugin "The Events Calendar"
scripts/scrapers/Lap2GoScraper.php
scripts/scrapers/XistarcaScraper.php
scripts/scrapers/TrilhoPerdidoScraper.php
scripts/scrapers/StopAndGoScraper.php
scripts/scrapers/AcorrerScraper.php           -- app Livewire: simula a chamada "load" do browser
scripts/scrapers/RecordePessoalScraper.php
scripts/scrapers/RunportoScraper.php
scripts/scrapers/ProEventsScraper.php         -- app Angular: lê as provas no ficheiro JavaScript
scripts/scrapers/FonteOrganizadora.php        -- marca fontes cujo link do evento tem prioridade
scripts/run_scraper.php              -- ponto de entrada do scraper (CLI)
scripts/definir_admin.php            -- torna um utilizador administrador (CLI: php scripts/definir_admin.php email)
scripts/procurar_premios.php         -- procura prémios monetários em todas as provas por ver, sem o resto do scraper (CLI)
scripts/procurar_inscricoes.php      -- procura o valor da inscrição em todas as provas por ver (CLI)
scripts/localizar_provas.php         -- procura as coordenadas de todas as provas sem elas (CLI)

public/_bootstrap.php                -- sessão + ligação BD + $auth, incluído por todas as páginas
public/index.php                     -- calendário público (filtros: mês, distância, fonte, pesquisa), 20 provas por página; ?parcial=1 devolve só a lista (carregamento progressivo)
public/prova.php                     -- página individual de uma prova (?id=N), aberta ao clicar num cartão (ver "Página da prova")
public/lib/Organizador.php           -- referência ao organizador (nome + link) usada no cartão e na página da prova
public/prova-ics.php                 -- uma prova em .ics (?id=N), para o calendário do telemóvel
public/gestao-utilizadores.php       -- (só administradores) acesso aos prémios, administradores e desativar contas
public/ranking-premios.php           -- (só administradores) provas ordenadas pelo total dos prémios monetários
public/meu-calendario.php            -- provas guardadas pelo utilizador autenticado + botões de exportação .ics
public/meu-calendario-ics.php        -- "o meu calendário" em .ics (descarga com sessão, ou subscrição com ?token=)
public/lib/CalendarioIcs.php         -- gera o texto iCalendar (RFC 5545) a partir das provas
public/favorito.php                  -- endpoint POST: adiciona/remove uma prova do "meu calendário" (responde JSON se o pedido aceitar application/json)
public/login.php / registo.php / logout.php
public/auth/google-iniciar.php       -- redireciona para o ecrã de login da Google
public/auth/google-callback.php      -- troca o "code" por perfil e inicia sessão
public/lib/Auth.php                  -- sessão atual, CSRF, exigirLogin()
public/partials/head.php             -- <head> comum (letra Barlow do Google Fonts, CSS e JS com versão)
public/partials/cabecalho.php        -- topo: nome do site e conta, reutilizado em todas as páginas
public/partials/lista-corridas.php   -- provas agrupadas por mês (<section data-mes>), com miniatura/dorsal e botão "Guardar"
public/assets/estilo.css             -- CSS partilhado (tema e cores em variáveis no :root)
public/assets/app.js                 -- filtros automáticos, carregamento progressivo, "Guardar" sem recarregar, voltar ao topo

docs/MANUAL.md                       -- este ficheiro
```

## Fontes cobertas pelo scraper

| Fonte | URL | Cobertura | Notas |
|---|---|---|---|
| All4Running | `all4running.pt/provas/provas-de-estrada/` e `/provas-de-trail/` | Todas as páginas de paginação das duas listagens (~10 provas/página) | Nome, data, local, tipo e distâncias vêm diretamente do HTML da listagem. Inclui algumas provas no estrangeiro (ex. Maratona de Chicago). **Link da prova:** a listagem só tem a página da prova na All4Running; o link oficial está nessa página (botão com `title="Site oficial da prova"`; senão o link do botão de inscrição — o do topo, "Inscrições abertas" (`title="Fazer Inscrições …"`), ou o "Fazer inscrição"/"Inscrever" mais abaixo; senão o "Visitar website" do organizador — `All4RunningScraper::extrairLinkOficial`). Cada página é lida uma vez (tabela `links_oficiais`); as que ficaram sem link voltam a ser lidas, no máximo uma vez por dia, depois das ainda não lidas e o link da prova passa a ser o oficial (`Database::aplicarLinksOficiais`); a página da All4Running fica como link da fonte. **A All4Running recusa pedidos seguidos (HTTP 429):** as páginas são pedidas de 5 em 5 segundos, até 50 por execução; se recusar duas vezes, pára e continua na execução seguinte. A imagem da prova é aproveitada da mesma página. |
| Portugal Running | `portugalrunning.com/calendario-de-corridas-de-estrada/` | Só o mês corrente e o seguinte (o que a página mostra por omissão) | Apesar do endereço, inclui trails, corta-mato e outras modalidades (duatlo, Hyrox...), sem filtro. Tipo, região e distâncias são **inferidos** a partir das classes CSS de categorização do site (ex.: `evo_corrida-10-km`, `evo_algarve-e-sul`); podem ficar vazios para eventos com etiquetagem atípica. **Link da prova:** a listagem só tem a página da prova na Portugal Running (um calendário, não o organizador); o link oficial está nessa página — o botão "saber mais" do EventON (`a.evo_clik_row`: o site da prova ou a página de inscrições na Lap2Go, Stop and Go...), senão o "Regulamento Oficial" (`PortugalRunningScraper::extrairLinkOficial`). Usa o mesmo mecanismo da All4Running (tabela `links_oficiais`, até 50 páginas por execução, de 2 em 2 segundos); o link da prova passa a ser o oficial. O organizador vem do campo "Entidade Organizadora" da mesma página (`DetalhesProvaHelper`). |
| AAAPorto (Associação de Atletismo do Porto) | `aaporto.com/.../calendario-competitivo/estrada`, `/corta-mato`, `/pista-coberta` e `/pista-ar-livre` | Primeiras 15 páginas de cada listagem (~120 registos) | A listagem não está ordenada por data — mistura provas passadas e futuras pela ordem de inserção. O scraper descarta qualquer prova com data já passada. Sem distância na listagem (fica `null`). Foco na região do Porto. As listagens de pista não aparecem no menu do site (só estrada e corta-mato), mas existem com a mesma estrutura. |
| FPA Competições | `beta.fpacompeticoes.pt/calendar` | Janela mostrada por omissão pela página (~3-4 meses à frente) | Aceita `tipo` Estrada, Trail, Corta-Mato e os de pista ("Pista Ar Livre", "Pista Coberta"...; "Outro" fica de fora). As associações distritais registam aqui as suas competições (campo `owner`: AAB, ADAL, AAVR, AADP, AARAM...), por isso é a principal fonte de provas de pista das associações sem calendário próprio legível. A página devolve sempre as próximas 50 provas (a pesquisa e os filtros do site são feitos no browser). Os dados vêm de JSON embutido na página (`window.__remixContext`), não de HTML tradicional — é uma app Remix renderizada no servidor. Um pedido extra por prova a `/competition/{id}` obtém a distância real a partir dos escalões de inscrição (nas provas de pista não, porque os metros dos escalões não são uma distância de corrida). |
| AA Braga | `aabraga.pt/pt/calendario` | Todos os eventos futuros | JSON embutido na página (`window.AABragaCalendarioEventos`, alimenta um FullCalendar). Aceita os tipos Estrada, Corta-mato, Trail, Montanha e pista ("Pista", "Pista Ar Livre", "Pista Coberta", "Pista Curta", "Pavilhão") (campo `type`); quando vem vazio decide pelo nome (`NomeProvaHelper::pareceCorrida` / `parecePista`). Exclui âmbito "Internacional". |
| ADA Leiria | `adal.pt/calendario.php` | Todos os eventos futuros | HTML sem tipo nem local: decide-se pelo nome (`pareceCorrida` / `parecePista`: entram estrada, trail, corta-mato e pista — torneios, meetings, lançamentos...; ficam de fora marcha, convívios, Kids Athletics, formação...). O link "Programa" (PDF do regulamento) é usado como link do evento. |
| ADA Coimbra | `adac.pt/calendar/` | Todos os eventos futuros (o JSON traz várias épocas) | WordPress com o plugin "calendario-lite" (FullCalendar): os eventos vêm em `var calendarioEvents = {"events": [...]}` com título, início, link da página do evento no site da ADAC e local (`extendedProps.location`). Sem tipo: decide-se pelo nome (`pareceCorrida` / `parecePista`). |
| AA Lisboa | `competicoes.aalisboa.com.pt/competicoes` | Separador "Por Realizar" (todas as provas futuras da época publicadas) | O site principal da AAL é Wix e só tem ligações para esta plataforma, que é **ASP.NET WebForms**: a página abre no separador "Abertos" (inscrições abertas); o scraper repete o clique em "Por Realizar" — POST da página com os campos escondidos (`__VIEWSTATE`, `__EVENTVALIDATION`...) e `__EVENTTARGET` do botão, na mesma sessão (`new HttpClient(guardarCookies: true)`). O botão "Entrar" de cada prova é outro `__doPostBack` que redireciona para `/Competicoes/{id}`: um POST por prova (com os mesmos campos escondidos) dá o link da prova, lido no `action="./{id}"` do formulário de destino. Sem tipo: decide-se pelo nome. |
| AA São Miguel, AA Madeira, AA Santarém | `/wp-json/tribe/events/v1/events` de cada site | Eventos futuros (API paginada) | `TheEventsCalendarScraper`, uma instância por site. Entra se uma categoria for de estrada, trail, corta-mato, montanha ou pista (ex. "Pista Local", "Pista Regional" em São Miguel; "Pista", "Pista / Escolar" na Madeira) — ou, sem categorias, pelo nome. Madeira e Santarém têm hoje poucos ou nenhuns eventos no calendário do site (publicam sobretudo em PDF), mas passam a entrar automaticamente se o usarem. |
| Lap2Go | `lap2go.com/pt/event/list` | Todos os eventos futuros | Plataforma de inscrições/cronometragem. Os dados vêm de atributos `data-day`, `data-name`, `data-city` de cada `a.event-item`. Fica só o que tem ícone de corrida (`type-run`) **e** nome de corrida (`NomeProvaHelper::pareceCorrida`), porque o ícone também marca provas híbridas e de obstáculos. |
| Xistarca | `xistarca.pt/eventos/` | Próximos eventos (lista única) | Organizador de corridas de estrada da região de Lisboa. A data não tem ano ("18 de Outubro"): `DateHelper::proximaOcorrencia()` usa o ano corrente ou o seguinte. Exclui só o que pelo nome é outra modalidade (`NomeProvaHelper::eOutraModalidade`). |
| Trilho Perdido | `trilhoperdido.com/eventos?cat=5` (Trail Running) e `cat=6` (Atletismo) | Próximos eventos, todas as páginas (`pag=N`, 16 por página) | Organizador e cronometragem, sobretudo trails na região de Lisboa e Vale do Tejo. Cada `div.evento` tem o link, o cartaz, a data em três linhas ("18" / "out" / "2026"), o nome e a localidade. As outras categorias (BTT, TT, Caminhadas) ficam de fora; caminhadas nestas duas são excluídas pelo nome. |
| Stop and Go | `stopandgo.net/events?status=S&country=178&modality=1` (Atletismo) e `modality=5` (Trail) | Próximos eventos, todas as páginas | Filtros na query string: próximos, modalidade, Portugal. Data da etiqueta "17 de outubro 2026" (em provas de vários dias, "16 - 18 de outubro 2026", fica o 1.º dia). Exclui caminhadas pelo nome. |
| Acorrer | `acorrer.pt/eventos` | Todos os eventos futuros | App Laravel **Livewire**: o HTML inicial vem sem eventos. O scraper repete o que o browser faz — GET da página (com cookies de sessão, `new HttpClient(guardarCookies: true)`), lê `window.livewireScriptConfig` (CSRF + endereço de atualização) e o `wire:snapshot` do componente `provas-grid`, e faz POST JSON com a chamada `load`; a resposta traz o HTML da grelha. Sem modalidade nem ano: decide-se pelo nome (`pareceCorrida`) e o ano por `proximaOcorrencia()`. |
| Recorde Pessoal | `recordepessoal.pt/eventos?pagina=-1&cat=atletismo` e `cat=trail` | Todos os próximos eventos (`pagina=-1` = "ver todos") | Plataforma de inscrições e cronometragem, sobretudo distritos de Leiria e Santarém. Cada `div.evento` tem a data ("24 Outubro 2026"), o título, a localidade e o cartaz (no `style` de `div.imagem`). Caminhadas excluídas pelo nome (incluindo "Walk"). A hora lê-se depois na página da prova (`DetalhesProvaHelper`). |
| Runporto | `runporto.com/pt/novidades/calendario-de-provas-AAAA/` (ano corrente e seguinte, se existir) | Provas a partir de hoje | Organizador das grandes provas do Porto e Braga. Página de texto: `<h2>` com o nome e `<li>` "8 de novembro de 2026 - Porto - 42km/10km/6km". O link de cada prova vem do menu de eventos do topo, emparelhado pelo nome (`NomeProvaHelper::mesmaProva`, fica o que tem mais palavras em comum). O site envia gzip mesmo sem o pedir (por isso o `HttpClient` usa `CURLOPT_ENCODING`). |
| ProEvents | `proevents.pt` | Provas futuras (`status` diferente de `finished`), categorias `running` e `trail` | Organizador da região do Porto. App **Angular**: as provas estão num dos ficheiros `chunk-*.js` carregados pela página inicial, como objetos `{id:"…",name:"…",date:"2026-12-19",location:"…",distances:[…],category:"running",status:"upcoming",startTime:"10:00"}`; o scraper procura o ficheiro que tem `category:"running"` (os nomes mudam a cada atualização do site). O link da prova é a página de inscrições na Status Marathon (mapa slug ⇒ URL no mesmo ficheiro). "São Silvestre 2026" passa a "São Silvestre de Vila do Conde 2026", para se juntar à mesma prova vinda de outras fontes. |

### Associações distritais (levantamento de 2026-09-30, revisto a 2026-10-12 para a pista)

Das 22 associações filiadas na FPA ([lista](https://fpatletismo.pt/atletismo/institucional/associados-efetivos/)), só as da tabela acima têm calendário legível automaticamente: Porto, Braga, Leiria, Coimbra, Lisboa, São Miguel, Madeira e Santarém.
- **Só em PDF** (frágil, fora de âmbito): Aveiro (`aaaveiro.pt`, "Calendário Oficial" em PDF), Castelo Branco, Évora, Viana do Castelo, Setúbal.
- **Sites Wix** (conteúdo gerado por JavaScript): Ilha Terceira, Vila Real (`aavilareal.wixsite.com/aavr`). Atenção: `aavr.pt` é a Associação de **Andebol** de Vila Real.
- **Sem calendário online / site indisponível**: Algarve (domínio não resolve), Beja, Guarda, Bragança, Viseu, Faial (calendário vazio).
- **Portalegre** (`aadp.pt`): a tabela de `/calendario/` tem dados incoerentes (colunas trocadas, eventos de formação marcados como "Estrada") e a API do tipo "competicao" (`/wp-json/wp/v2/competicao`) não traz a data da prova nem o tipo (`tipo_prova` vazio) — adiado.

**Pista:** as associações sem calendário legível registam as suas competições (incluindo as de pista) na FPA Competições, que as traz com o tipo certo ("Pista Ar Livre", "Pista Coberta") e o campo `owner` (ex. AAVR, AADP, AARAM). As fontes que publicam todas as competições sem tipo (Leiria, Coimbra, Lisboa) usam `NomeProvaHelper::parecePista()`; as fontes de corridas (Lap2Go, Acorrer, Xistarca, Stop and Go, Trilho Perdido) continuam a excluir pista, porque pelo nome os falsos positivos seriam muitos. Atenção à época: a pista coberta é de dezembro a março e a pista ao ar livre de abril a setembro, por isso fora dessas alturas aparecem poucas provas de pista.

Grande parte das provas das associações já aparece noutras fontes; o ganho está em confirmar provas e apanhar as pequenas provas locais.

### Fontes deixadas para mais tarde

- **correrporprazer.com/provas-de-estrada/** — a listagem só aparece depois de escolher um distrito, carregada via AJAX; precisa de um scraper diferente (simular os pedidos AJAX por distrito) ou de automação de browser.
- **FPA oficial (fpatletismo.pt/calendario-e-provas-homologadas/)** — calendário oficial, mas maioritariamente distribuído em PDF e páginas de notícia, não numa listagem HTML estruturada. Nota: isto é um sistema diferente de `fpacompeticoes.pt` (já coberto pelo `FpaCompeticoesScraper`, ver tabela acima).

## Como correr o scraper

1. Criar/atualizar a base de dados: importar `sql/schema.sql` (phpMyAdmin, ou `mysql -u root -p < sql/schema.sql`). É seguro voltar a correr — usa `CREATE TABLE IF NOT EXISTS`, não apaga dados existentes.
2. Copiar `scripts/config.example.php` para `scripts/config.php` e ajustar `db_host`, `db_user`, `db_pass` conforme o XAMPP/WAMP local.
3. Correr o scraper:
   ```
   php scripts/run_scraper.php
   ```
4. O script imprime, por fonte, quantas provas foram encontradas, quantas são novas, quantas foram atualizadas e quantas já existiam noutra fonte (juntadas) e, no fim, eventuais avisos de fontes com problemas.

Correr o script várias vezes é seguro: cada fonte reencontra as suas provas pelo `hash_dedup` (nome + data + local) em `corridas_fontes` e atualiza-as em vez de as duplicar; as distâncias em `corridas_distancias` são recalculadas a partir do texto de `distancias`.

**Base de dados criada antes de existir `corridas_fontes`:** correr uma vez `sql/migracao_002_corridas_fontes.sql` com a BD selecionada. A execução seguinte do scraper junta as provas repetidas antigas.

## Como adicionar uma nova fonte ao scraper

1. Criar `scripts/scrapers/NovaFonteScraper.php`, implementando `ScraperInterface`:
   - `nome(): string` — nome curto da fonte (vai para o campo `fonte`).
   - `scrape(): array` — devolve uma lista de objetos `Corrida`.
2. Usar `HttpClient` para os pedidos e `DOMDocument`/`DOMXPath` para o parsing (ver `All4RunningScraper` como exemplo direto, `PortugalRunningScraper` para heurísticas em classes CSS, ou `AAAPortoScraper` para uma listagem paginada não ordenada por data).
3. Usar `DateHelper::parseTextoLivre()` ou `DateHelper::toIso()` para normalizar datas em português (`DateHelper::proximaOcorrencia()` quando a fonte só dá dia e mês). Para decidir se é corrida (estrada, trail ou corta-mato): `NomeProvaHelper::pareceCorrida()` quando a fonte não diz a modalidade, `NomeProvaHelper::eOutraModalidade()` quando já diz que é corrida/atletismo mas mistura pista, caminhadas, obstáculos... Para associações que publicam também competições de pista sem dizer o tipo, juntar `NomeProvaHelper::parecePista()` (torneios, meetings, lançamentos, distâncias em metros...; exclui convívios, Kids Athletics, formação). Passar em `tipo` o tipo que a fonte indicar (ex. "Trail", "Corta-mato", "Pista Coberta") ajuda a classificar a prova (ver filtro "Tipo"): um `tipo` com "estrada" impede que o nome a faça passar a pista ("Torneio de Rua").
4. Registar a nova classe em `scripts/run_scraper.php` (`require` + adicionar ao array `$scrapers`).

## Site público (`public/`)

Correr localmente com o servidor embutido do PHP, a partir da pasta `public/`:
```
php -S localhost:8000 -t public
```
Em produção, aponta o *document root* do Apache/Nginx diretamente para `public/`.

### Domínio de produção

O site público está em **https://corridas.eu** (antes: `corridas.espacodecodigo.eu`). O código não tem o domínio escrito: os links de subscrição .ics usam o `HTTP_HOST` do pedido. O domínio só aparece em dois sítios:
- `public/.htaccess`: redireciona com 301 o endereço antigo, `www.corridas.eu` e `http://` para `https://corridas.eu`. Exceção: o endereço antigo continua a servir `meu-calendario-ics.php` diretamente, para que as subscrições já feitas não dependam de a app de calendário seguir redirecionamentos.
- `google_redirect_uri` em `scripts/config.php` do servidor (e o URI correspondente na Google Cloud Console).

### Filtros do calendário

`index.php` filtra por **tipo** (estrada, trail, corta-mato, pista — coluna `corridas.categoria`, calculada no fim de cada execução do scraper por `Database::atualizarCategorias()` a partir do nome e do tipo, com `NomeProvaHelper::categoria()`; ultra trail e montanha contam como trail; pista inclui ar livre, coberta e pista curta), mês, **distância** (faixas pré-definidas em `DistanciaHelper::FAIXAS`, ex. "5 a 10 km", "21 a 42 km"), fonte (**só administradores** — para os outros a lista não aparece e `?fonte=` é ignorado), e pesquisa livre por nome/local. O filtro de distância usa a tabela `corridas_distancias`, sincronizada automaticamente pelo scraper a partir do texto em `corridas.distancias` (`DistanciaHelper::extrairKm()`). As provas de pista ficam sem distância (não há uma distância de corrida), por isso não aparecem quando se escolhe uma faixa de distância. O filtro **Prémios** (`?premios=sim` / `?premios=nao`) usa `corridas.premios_monetarios` (ver "Prémios monetários" abaixo).

### Lista de provas, carregamento progressivo e miniaturas

- Só aparecem provas **a partir de hoje** (`Database::filtrosCorridas()`); as sem data vão no fim. O filtro de mês dá a próxima ocorrência desse mês.
- A página abre com 20 provas (`PROVAS_POR_PAGINA` em `index.php`). No fim da lista há um link "Mostrar mais provas" para `?pagina=N`; o `assets/app.js` observa-o (`IntersectionObserver`) e, ao aproximar-se, pede `?pagina=N&parcial=1`, que devolve só as secções de meses dessa página. Provas de um mês que já está na página juntam-se à secção desse mês. Sem JavaScript o link continua a funcionar (abre a página seguinte).
- "Guardar" (`form.favorito`) é enviado por `fetch` com `Accept: application/json`, para não recarregar a página nem perder as provas já carregadas; se o pedido falhar, o formulário é enviado da forma normal.
- **Miniaturas** (`corridas.imagem_url`): a Acorrer e a Stop and Go dão o cartaz na listagem; para as restantes, no fim de cada execução o scraper abre as páginas do evento de todas as fontes da prova e usa o `og:image`/`twitter:image` (ou a imagem de destaque WordPress, `img.wp-post-image`) — `ImagemEventoHelper`. Cada prova só é procurada uma vez (`imagem_procurada_em`) e há um limite de 80 provas por execução. Imagens usadas por 3 ou mais provas são descartadas (quase sempre o logótipo do site). As imagens são mostradas diretamente do site de origem (`referrerpolicy="no-referrer"`); sem imagem, ou se ela falhar, vê-se um **dorsal** desenhado em CSS com o mês e o dia.
- **Agrupada por dia:** dentro de cada mês, o dia aparece uma vez (`div.dia`): um dorsal com o dia da semana e o número, que fica fixo ao descer enquanto há provas desse dia (por baixo do título do mês, também fixo — `--altura-filtros` e `--altura-mes` no CSS têm de corresponder às alturas reais), e ao lado os cartões das provas. No telemóvel o dorsal fica por cima, com a data por extenso, e é ele que fica fixo ao descer (o título do mês desliza: a data por extenso já diz o mês). Ao carregar mais provas, as de um dia que já está na página juntam-se a esse dia (`data-dia`).
- **Cartão inteiro clicável:** o link do nome da prova abre a página da prova (`/prova.php?id=N`) e tem um `::after` que cobre o cartão (`.prova-link`); o botão "Guardar" e os links das fontes ficam por cima (`z-index`).
- **Referência no cartão:** prova encontrada num só site → "Encontrada em X"; em vários → só a referência ao organizador ("Organização: nome", ou "Regulamento da prova" quando o único link é o regulamento) — `Organizador::de()`. A lista completa das fontes fica na página da prova.
- **Fade in:** os cartões aparecem ao chegar ao ecrã (`IntersectionObserver` em `app.js`, classe `visivel`). Só com a classe `js` no `<html>`, posta por um script no `<head>`, que a retira se o `app.js` não correr em 3 s — os cartões nunca ficam escondidos. Respeita "reduzir movimento".
- **Frase do total** de acordo com os filtros, ex. "3 provas de trail em dezembro, de 5 a 10 km".
- **Base de dados criada antes das miniaturas:** correr uma vez `sql/migracao_004_imagens.sql`; antes do filtro "Tipo": `sql/migracao_005_categoria.sql`; antes dos prémios monetários: `sql/migracao_007_premios.sql`.

### Página da prova

`public/prova.php?id=N` (dados de `Database::obterProva()`, que também devolve provas já passadas):

- **Topo condensado** (cada dado aparece uma só vez): etiquetas do tipo (o subtipo da fonte, ex. "Ultra Trail", quando diz mais) e das distâncias, título, dia e hora (ou "hora por confirmar"), e numa linha o local com o **distrito e a região** ("Distrito de Braga · Norte"; `DistritoHelper::localizar()`, calculados ao mostrar a partir de `corridas.local` e, se o local não chegar, de `corridas.regiao`) e "Ver no mapa".
- **Organização** (em destaque): nome em `corridas.organizador` e botão para `organizador_url` ("Site do organizador") ou, se não houver, para o link da prova ("Site da prova" / "Regulamento da prova"); se o link da prova for outro (inscrições), aparece num segundo botão.
- **Programa e escalões** (`corridas.programa`, JSON `[{hora, prova, escaloes}]`): hora, prova e escalões de cada partida.
- **Prémios monetários** (só para quem tem acesso, e só quando a prova tem prémios em dinheiro — "sem prémios" ou "por saber" não mostram nada): uma só tabela, com uma linha por lugar e uma coluna por classificação (`PremiosHelper::tabelasDaProva()`: `corridas.premios_tabela` ou, se estiver vazia, o que dá para ler no excerto), o total de cada coluna ("× 2" quando vale para masculinos e femininos) e o **total a distribuir** (`PremiosHelper::total()`), com link para o documento onde foram lidos. Sem valores lidos, mostra o excerto do regulamento.
- Guardar (junto ao botão do organizador), adicionar ao Google Calendar (com a hora, se se souber) ou descarregar `.ics` (`public/prova-ics.php`), e a lista de todas as fontes no fundo ("Encontrada em…", **só para administradores** — `$auth->eAdmin()`).
- O link "Calendário" (`data-voltar`) faz `history.back()` quando se veio do calendário, para repor os filtros e a posição na lista; aberta por link direto, vai para `/`.

**De onde vêm hora, programa e organizador:**

| Fonte | O quê | Como |
|---|---|---|
| FPA Competições | hora, programa com escalões, cartaz | no scraper: a página `/competition/{id}` redireciona para `/inscriptions/{id}` (inscrições abertas) ou `/competition/{id}/schedule` (fechadas) — as duas têm o horário no `__remixContext` |
| AA Braga | organizador | campo `extendedProps.organization` da listagem |
| Xistarca, Trilho Perdido, Runporto, ProEvents | organizador (eles próprios) | no scraper |
| ProEvents | hora | no scraper (`startTime`) |
| Lap2Go | organizador e site | página da prova (`span.evento-org`, `span.evento-site`) |
| Portugal Running | organizador | página da prova ("Entidade Organizadora") |
| Stop and Go | organizador e site | página da prova ("Organizador" + botão "Website do Organizador") |
| Acorrer | hora | página da prova (`og:description`: "… às 17:00") |
| Recorde Pessoal | hora | página da prova (bloco "Data": "15 Novembro 2026 10h00") |
| Xistarca | programa e hora | página da prova (secção "DISTÂNCIA / HORA / LOCAL") |

As páginas das plataformas são lidas no fim de `run_scraper.php` (`DetalhesProvaHelper`), cada prova uma vez por semana (`detalhes_procurado_em`), até 120 por execução, de 2 em 2 s por site. O que já se sabia nunca é apagado por uma fonte que não tem esse dado. Para as restantes fontes não há hora nem programa (não os publicam de forma legível).

**Base de dados criada antes desta funcionalidade:** correr uma vez `sql/migracao_009_detalhes.sql`.

### Prémios monetários

**Só para quem tem acesso** (`Auth::podeVerPremios()`): administradores e utilizadores autorizados por eles. Para os restantes — incluindo quem não tem sessão — o ícone, o filtro e o parâmetro `?premios=` não existem. Ver "Administradores e acesso aos prémios" abaixo.

Cada prova pode ter um ícone "€" verde ao lado do nome, só quando os mais bem classificados recebem dinheiro. Quando o regulamento diz que os prémios são troféus/medalhas (ou que não há prémios monetários), ou enquanto não se sabe, não se mostra nada (a informação fica na BD e serve o filtro "Prémios €"). O ícone liga ao documento onde a informação foi lida (`premios_url`) e mostra o excerto (`premios_excerto`) como dica.

**Recolha** (`ProcuraPremios`, no fim de `run_scraper.php`): sem número fixo de provas — vê as que couberem em **10 minutos**, primeiro as nunca vistas (pela data da prova), e a execução seguinte continua onde esta parou. **Cada prova tentada fica marcada** (`premios_procurado_em`), mesmo que nenhum site responda (`Database::marcarPremiosTentados`, que não apaga o que já se sabia) — senão as provas de sites que recusam pedidos ficavam sempre à frente da fila e ocupavam todas as execuções. As que ficaram por saber (ou sem resposta) voltam a ser vistas ao fim de 3 dias (o regulamento costuma sair mais tarde), as "sem prémios" ao fim de 30, as "com prémios" não voltam. Pedidos de 2 em 2 s ao mesmo site; a All4Running usa o cliente de 5 em 5 s das fases anteriores e, se já recusou pedidos nessa execução, não é contactada.

**Pôr tudo em dia de uma vez** (ex. primeira volta, ~20 a 40 minutos para ~400 provas): `php scripts/procurar_premios.php` (até 60 minutos; `--minutos=N` para mudar). Pode ser interrompido e voltar a correr — continua nas que faltam. Para cada prova, `PremiosHelper::procurar()` lê as páginas do evento (de todas as fontes; da All4Running também a página da prova) e segue os links do regulamento (`PremiosHelper::linksRegulamento()`: links com "regulamento" no endereço ou no texto, a página `/rules` da Stop and Go, o campo JSON `"Regulamento"` da Lap2Go), até 5 documentos por prova. Os PDF são lidos por `PdfTextoHelper` (PHP puro: objetos, object streams, FlateDecode, mapas ToUnicode, codificações Windows-1252 e Mac Roman; a letra em uso é guardada/reposta com os operadores q/Q, como no estado gráfico do PDF — o Word escreve cada célula das tabelas entre q/Q sem repetir a letra; as fontes simples (TrueType/Type1) leem-se sempre com 1 byte por letra, mesmo quando o mapa ToUnicode declara 2; ligaduras como "ﬁ" passam a "fi"); PDFs digitalizados (só imagens) ou cifrados ficam sem texto.

**Decisão** (`PremiosHelper::analisar()`), sobre o texto sem acentos:
1. **Sim** — frase explícita não negada ("prémios monetários", "prémio pecuniário", "prize money", "prémios em dinheiro"...), ou um valor em euros (≥ 10 €) com uma palavra de prémio/classificação pouco antes ("1.º lugar – 100 €", "Troféu + 50€") e sem sinais de preço de inscrição, seguro, caução, vales de compras... por perto (`PADRAO_NAO_PREMIO`, `PADRAO_TABELA_PRECOS`). Vales/cartões de compras com valor contam como prémio monetário quando a prova os anuncia como tal.
2. **Não** — "não há prémios monetários", ou o texto descreve os prémios dos primeiros classificados (troféu, medalha, "prémios aos 3 primeiros") sem dinheiro. Numa página de evento só conta se disser o que é o prémio (troféu, medalha...); no regulamento basta "prémios aos 3 primeiros" (quem dá dinheiro diz quanto).
3. **Por saber** — o texto não fala de prémios, diz que serão anunciados mais tarde, ou não houve documento legível.

É uma heurística: pode falhar em tabelas de prémios que o PDF parte de forma estranha ou em regulamentos muito informais — o link para o documento permite confirmar.

**Tabela dos valores** (`PremiosHelper::tabelas()`, gravada em `premios_tabela` como JSON `[{titulo, linhas: [[lugar, "100 €"], ...]}]`): procura pares "lugar → valor" ("1.º lugar – 100 €", "2º- 50€", "1º Troféu + 150 €", "1º €100", "350 € para o 1.º", "1º Class. 400€", "1. Geral M/F – 100€", lugares por extenso "o vencedor recebe 125 €, o segundo 100 €" / "250 € para o segundo", e listas "respetivamente, 50 €, 25 € e 15 €" / "no valor de 100, 50 e 25 euros" / "50 €, 30 € e 20 € para 1.º, 2.º e 3.º" / "para os três primeiros" → 1.º, 2.º, 3.º; cada valor só conta uma vez; algarismos separados pelo PDF, "12 5 €" / "3 00€", são juntados) e agrupa-os em tabelas que começam no 1.º lugar; um lugar repetido começa outra tabela, exceto quando vem logo a seguir (colunas masculinos/femininos lidas linha a linha — mostra-se a primeira). **Várias colunas** na mesma linha ("1º Classificado 500€ 500€", uma coluna por escalão "50€ 50€ 50€...") são contadas: a tabela guarda `colunas` e `soma` (todos os valores de todas as colunas), e o total usa a soma. Tabelas iguais (masculinos e femininos, ou vários escalões com os mesmos valores) aparecem uma vez mas contam todas (`repeticoes`). Sem tabela, fica o total anunciado ("prize money global de 5.000 euros", "prémios monetários no valor de 2650 €"): uma tabela sem linhas com `anunciado: true` e `soma`. Rejeita valores < 10 € e pares com outras palavras pelo meio ("1.ª fase 15 €"). O título vem de palavras logo antes ("Geral", "Femininos", "M/F", "Juniores", "Vet 40", "10 km"). Tabelas iguais aparecem uma vez. Se o documento que diz "há prémios" não tiver valores, procura-se a tabela nos documentos que faltam (ex. o regulamento em PDF). `"[]"` = não deu para ler; `NULL` = ainda não procurada — as provas "com prémios" lidas antes da migração 009 voltam a ser lidas uma vez por isso, e `sql/migracao_010_premios_tabela.sql` repõe `NULL` em todas as provas com prémios, para serem lidas outra vez com os novos formatos e o leitor de PDF corrigido. Os PDFs chegam muitas vezes com as colunas baralhadas: a tabela mostra só o que deu para ler com segurança e a página pede sempre para confirmar no regulamento.

### Valor das inscrições

Cada prova pode ter o valor da inscrição (`corridas.inscricao_min` / `inscricao_max`, em euros; 0 = gratuita; `NULL` = por saber), mostrado na página da prova ("Inscrição 10 € a 25 €", com "conforme a data e a distância" quando há vários valores, e o link "Ver preços" para `inscricao_url`) e usado pelo filtro **Inscrição** da página inicial, visível a todos (faixas em `InscricaoHelper::FAIXAS`: Gratuita, até 10 €, 10 a 20 €, 20 a 30 €, mais de 30 € — pelo valor **mais baixo**, o de quem se inscreve cedo). Precisa de `sql/migracao_012_inscricoes.sql`.

**Como se lê** (`InscricaoHelper::extrair()`, sobre o texto já extraído — `PremiosHelper::textoHtml` / `PdfTextoHelper`):
- **blocos de preços** das plataformas: uma linha curta "Preços", "Inscrição", "Quanto?", "Fases de Preço" (Lap2Go) ou "Período de inscrição" (All4Running) abre um bloco de até 40 linhas; cada valor conta para o nome da prova na mesma linha ("Trail Longo – 20€", Trilho Perdido) ou na última linha anterior que não seja uma data/fase ("TRAIL 25KM" / "€ 13,00" na Stop and Go; "corrida" / "10.00€" na Lap2Go). A linha antes do bloco também conta como nome ("Caminhada de 6 km" / "Período de inscrição"). O bloco fecha ao fim de 8 linhas sem valores;
- **frases**: "A inscrição tem um custo de 12 €", "Inscrição Geral: 10.00 € (até 20/10)" (Portugal Running), "A Corrida de 10 km tem o valor de 7 € e 10 €". O que está entre parênteses ou depois de "inclui" não exclui o valor ("12 € (seguro, dorsal, t-shirt)"); numa frase com corrida e caminhada, cada valor conta para a última prova nomeada;
- ficam de fora caminhada/marcha, refeições, extras (t-shirt, meias, hoodie, roupeiro), provas jovens/kids, penalizações, taxas administrativas, "acresce", prémios e classificações (`PADRAO_EXCLUIR`); valores fora de 2 € a 250 €;
- **gratuita** só quando nenhum valor foi lido e uma frase diz "inscrição gratuita" / "inscrições são grátis" (sem ser das provas jovens, de equipas, da cedência de imagem...).

**Onde se lê** (`ProcuraInscricoes`, no fim de `run_scraper.php`, até 8 minutos por execução): as mesmas páginas que para os prémios, mas primeiro as das plataformas (na Stop and Go a página `/prices`) e só depois os regulamentos (até 5 documentos por prova); fica o primeiro documento com valor. Provas nunca vistas primeiro; sem valor, voltam a ser vistas ao fim de 3 dias; com valor, ao fim de 14 (os preços sobem por fases). Um valor lido não é apagado por uma leitura sem resultado. Para pôr tudo em dia: `php scripts/procurar_inscricoes.php` (até 60 minutos, `--minutos=N`).

### Perto de mim

Botão pequeno "Perto de mim" no início da frase do total (página inicial; só aparece com JavaScript e se o browser souber dar a localização). Ao carregar, `assets/app.js` pede a posição ao browser (`navigator.geolocation`) e abre `/?perto=LAT,LON`, com a posição **arredondada a 2 casas decimais (~1 km)**; não é guardada em lado nenhum. `index.php` mostra então as provas a menos de **50 km** (`RAIO_PERTO_KM`, distância em linha reta pela fórmula de Haversine, calculada no SQL em `Database::filtrosCorridas()`), com "a N km" em cada cartão. Os outros filtros continuam a aplicar-se (o do mês é retirado ao ativar). Com o filtro ativo o botão fica azul ("Perto de mim ✕", volta à lista normal) e ao lado aparece a lista do **período** (`?periodo=`, `PERIODOS_PERTO`): próximos 3 dias (o inicial, de hoje até daqui a 2 dias), próximo mês, próximos 2 meses, próximos 3 meses ou sempre; muda sozinha com JavaScript (`form.periodo-perto`).

**Coordenadas das provas** (`corridas.latitude` / `longitude`, `sql/migracao_013_coordenadas.sql`): `Geocodificador` pergunta ao **Nominatim** (OpenStreetMap, gratuito; regras: 1 pedido por segundo e um User-Agent que identifique a aplicação) pelo local da prova com o distrito (`DistritoHelper`, incluindo Açores e Madeira) e ", Portugal" (só resultados em Portugal); se não encontrar, tenta a última parte do local, que costuma ser a localidade ("Parque Urbano da Cidade, Ponta Delgada" → "Ponta Delgada"), e depois a primeira ("Rio Tinto / Gondomar" → "Rio Tinto"). Locais noutro país ("Sevilha, Espanha", "Frankfurt, Alemanha") ficam sem coordenadas — senão a pesquisa dava uma localidade portuguesa qualquer. Reaproveita as coordenadas de outra prova com o mesmo local. No fim de `run_scraper.php` localiza até 150 provas futuras; as não encontradas voltam a ser tentadas ao fim de 30 dias. Para localizar todas de uma vez: `php scripts/localizar_provas.php` (~1 s por localidade nova). Provas sem coordenadas não aparecem no "Perto de mim".

### Contas e "o meu calendário"

- Registo por email/password (`public/registo.php`) — password guardada com `password_hash()`.
- Login por email/password (`public/login.php`) ou com Google (`public/auth/google-iniciar.php` → `google-callback.php`), fluxo OAuth 2.0 "authorization code": troca o `code` por um `access_token` e pede o perfil ao endpoint `userinfo` da Google (não valida o `id_token` manualmente — evita reimplementar a verificação de assinatura JWT).
- Um utilizador pode existir só com password, só com Google, ou com as duas (a conta é associada pelo email).
- Sessão guardada em `$_SESSION['utilizador_id']`; todas as ações que alteram estado (favoritos, logout) validam um token CSRF guardado em sessão.
- "O meu calendário" (`public/meu-calendario.php`) lista as provas marcadas pelo utilizador; o botão "Guardar" / "Guardada" em cada prova chama `public/favorito.php` (POST), que alterna a linha em `favoritos`. Sem sessão, o botão leva ao login e volta à mesma página.

### Administradores, acesso aos prémios e contas desativadas

- `utilizadores.e_admin`: o administrador vê o link **Gestão** no topo e a página `public/gestao-utilizadores.php` (`Auth::exigirAdmin()`: sem sessão vai para o login; sem permissão, 403). Vê sempre os prémios monetários.
- **Ranking dos prémios** (`public/ranking-premios.php`, link "Ranking €" no topo, só administradores): as provas a partir de hoje com prémios monetários (`Database::listarProvasComPremios()`), ordenadas pelo total a distribuir (`PremiosHelper::total()`: soma de todos os valores das tabelas, incluindo todas as colunas, a dobrar nas que valem para masculinos e femininos — "Masculinos" e "Femininos" ou "M/F" no título —, exceto quando as duas colunas já são femininos e masculinos), com o maior 1.º prémio. As provas com prémios mas sem valores lidos ficam numa lista à parte, com o link do regulamento.
- Na página **Gestão**, para cada utilizador (um formulário POST com CSRF por botão, campos `acao` = `premios` | `admin` | `ativo` e `valor` = 0/1; pesquisa por nome ou email):
  - `utilizadores.ver_premios`: "Dar acesso aos prémios" / "Retirar prémios" (os administradores veem sempre);
  - `utilizadores.e_admin`: "Tornar administrador" / "Retirar administrador" (`Database::definirAdminPorId`);
  - `utilizadores.ativo`: "Desativar" / "Reativar" (`Database::definirAtivo`). Uma conta desativada não entra com email nem com Google (`Auth::contaAtiva()` em `login.php` e `auth/google-callback.php`, que volta ao login com `?desativada=1`), sai da sessão aberta no pedido seguinte (`Auth::utilizadorAtual()` tira-a da sessão; `autenticado()` passou a confirmar na BD) e o link de subscrição `.ics` dá 404. As provas guardadas mantêm-se, para o caso de ser reativada.
  - Os botões de administrador e de desativar não aparecem na própria conta (e o servidor recusa-os), para o administrador não ficar sem acesso à página.
- **Primeiro administrador** (tem de já ter conta): `php scripts/definir_admin.php email@exemplo.pt` (`--retirar` para deixar de ser). O email fica só na base de dados — não é escrito no código, porque o repositório é público.
- **Base de dados criada antes desta funcionalidade:** correr uma vez `sql/migracao_008_permissoes.sql` e, para desativar contas, `sql/migracao_011_utilizadores_ativos.sql` (pode ser antes do `git pull`; sem ela a página Gestão dá erro).

### Exportar "o meu calendário" (.ics)

`public/meu-calendario-ics.php` devolve as provas do utilizador em formato iCalendar (RFC 5545), gerado por `public/lib/CalendarioIcs.php`:
- **Com sessão iniciada, sem parâmetros** → descarga de um ficheiro `.ics` (cópia do momento, não se atualiza).
- **Com `?token=...`** → link de **subscrição**: o Google Calendar, iPhone/Mac ou Outlook pedem o link periodicamente, sem sessão, e mostram as alterações sozinhos. O utilizador é identificado pelo código secreto `utilizadores.ics_token` (32 hex, criado na primeira visita a "O meu calendário"). O botão "Gerar novo link" substitui o código e o link antigo passa a devolver 404.

Na página "O meu calendário" há uma barra de ícones, sem texto visível (a explicação de cada um está no `title`/`aria-label`): Google (`calendar.google.com/calendar/render?cid=webcal://...`), Apple — iPhone/Mac/Outlook (link `webcal://`, que abre a app de calendário do sistema) —, descarga do ficheiro, e um ícone de link que abre um painel com o link pessoal, um botão de copiar e um botão para gerar um link novo (pede confirmação).

Detalhes do formato: cada prova é um evento de **dia inteiro** (`DTSTART;VALUE=DATE`), porque as fontes não dão a hora de forma fiável; provas sem data ficam de fora; o `UID` é `corrida-{id}@calendariocorridas`, fixo por prova, para as apps atualizarem o evento em vez de o duplicar; linhas com mais de 75 octetos são partidas sem cortar caracteres UTF-8. O Google Calendar decide sozinho quando volta a ler subscrições (pode demorar até ~24 h); o `REFRESH-INTERVAL` de 12 h é só uma sugestão, que o iPhone e o Outlook respeitam melhor.

**Base de dados criada antes desta funcionalidade:** correr uma vez `sql/migracao_003_ics_token.sql`.

### Configurar o login com Google

O botão "Continuar com o Google" só aparece funcional se `scripts/config.php` tiver `google_client_id`/`google_client_secret` preenchidos (por omissão ficam vazios e a página mostra um aviso). Para configurar:

1. Na [Google Cloud Console](https://console.cloud.google.com/), criar um projeto (ou reutilizar um existente).
2. Ir a "APIs e serviços" → "Ecrã de consentimento OAuth" e configurá-lo (tipo "Externo" chega para testes).
3. Em "Credenciais", criar um "ID de cliente OAuth" do tipo "Aplicação Web".
4. Em "URIs de redirecionamento autorizados", adicionar exatamente o valor de `google_redirect_uri` do `config.php` (ex.: `http://localhost:8000/auth/google-callback.php` em desenvolvimento; em produção, o URL público equivalente com HTTPS).
5. Copiar o "ID de cliente" e o "Segredo do cliente" para `google_client_id` e `google_client_secret` em `scripts/config.php`.

## Limitações conhecidas

- O agendamento diário do scraper é feito por cron no alojamento (configurado no painel, fora do repositório).
- A deteção de provas repetidas entre fontes exige a mesma data e é conservadora — pares com nomes muito diferentes ficam repetidos.
- Portugal Running só cobre os próximos ~2 meses por execução; AAAPorto cobre só a sua janela de paginação (ver tabela de fontes).
- Tipo/região/distâncias de Portugal Running são heurísticos, baseados em classes CSS do site.
- AAAPorto não expõe distância nas provas listadas.
- FPA Competições depende da estrutura interna `window.__remixContext` de uma app ainda em beta (o próprio site tem um aviso de "nova versão") — se a FPA mudar a framework/estrutura da página, este scraper para de funcionar e precisa de atualização. Cobre só a janela de datas que `/calendar` mostra por omissão (sem paginação/filtro de datas implementado nesta primeira versão).
- O login com Google exige credenciais próprias da Google Cloud Console (ver secção acima) — sem elas, só o login por email/password está disponível.
- Sem verificação de email no registo local (uma conta fica ativa imediatamente após o registo).
