# Manual do Programador — CalendarioCorridas

## Objetivo

Aplicação para listar o calendário de corridas de estrada (atletismo) em Portugal, com filtros por mês e distância, dados em português europeu, e ligação ao site do evento e à fonte onde foi encontrado. Cada utilizador pode ainda criar conta e guardar as suas próprias provas, como um "carrinho" pessoal ("O meu calendário").

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
```

`scripts/run_scraper.php` é o ponto de entrada: instancia todos os scrapers, corre cada um dentro de um `try/catch` (uma fonte a falhar não impede as restantes) e grava os resultados. No fim imprime (em STDERR) uma secção **"ATENÇÃO — fontes com problemas"** quando uma fonte falhou, ou quando devolveu 0 provas apesar de ter provas futuras na BD — sinal de que o site mudou e o scraper precisa de revisão.

### A mesma prova em várias fontes

A mesma prova aparece muitas vezes em vários sites (ex. All4Running, FPA Competições e a associação distrital), com nomes ligeiramente diferentes ("Meia Maratona Cidade Berço" / "Meia Maratona Cidade Berço - Guimarães"). Fica **uma só linha em `corridas`** e **uma linha em `corridas_fontes` por cada fonte** onde foi encontrada; o site público mostra todas ("Fontes: A · B · C").

`Database::upsertCorrida()` decide, para cada prova recolhida:
1. esta fonte já a tinha publicado (mesmo `fonte` + `hash_dedup` em `corridas_fontes`) → atualiza;
2. há na **mesma data** uma prova de **outra** fonte com nome equivalente (`NomeProvaHelper::mesmaProva()`) → junta-lhe esta fonte;
3. senão → cria prova nova.

A fonte que criou a prova (`corridas.fonte`, "fonte principal") define os dados; as outras só preenchem campos vazios (local, distâncias, tipo, região, link do evento).

`NomeProvaHelper::mesmaProva()` normaliza os nomes (minúsculas, sem acentos, sem números/ordinais/anos/distâncias, "GP" = "Grande Prémio", "S." = "São"), põe de parte palavras genéricas ("corrida", "meia", "maratona", "são silvestre", "cidade"...) e exige que pelo menos metade das palavras distintivas coincidam. Recusa sempre formatos diferentes ("Maratona do Porto" ≠ "Meia Maratona do Porto"). É conservadora de propósito: um par não detetado fica repetido, mas provas diferentes dificilmente são fundidas por engano (ex. "São Silvestre de Vizela" ≠ "São Silvestre de Amares"). Datas diferentes entre fontes (erro de um dia num dos sites) não são juntadas.

`Database::consolidarRepetidas()` corre no fim de cada execução e aplica a mesma regra às provas já gravadas: a mais antiga fica, a outra passa-lhe as fontes e os favoritos dos utilizadores e é apagada.

## Estrutura de pastas

```
sql/schema.sql                       -- schema completo (corridas, corridas_fontes, corridas_distancias, utilizadores, favoritos)
sql/migracao_002_corridas_fontes.sql -- para BDs criadas antes de corridas_fontes (correr uma vez)
sql/migracao_003_ics_token.sql       -- para BDs criadas antes do link de subscrição .ics (correr uma vez)

scripts/config.example.php           -- template de credenciais (copiar para config.php)
scripts/lib/Corrida.php              -- objeto de valor com os dados normalizados de uma prova
scripts/lib/HttpClient.php           -- pedidos GET/POST via cURL
scripts/lib/DateHelper.php           -- datas em português: parsing, formatação, agrupar por mês
scripts/lib/DistanciaHelper.php      -- extrai distâncias (km) do texto livre; faixas do filtro de distância
scripts/lib/NomeProvaHelper.php      -- mesma prova com nomes diferentes? parece prova de estrada (pelo nome)?
scripts/lib/Database.php             -- ligação PDO: upsert/junção de corridas, listagem com filtros, utilizadores, favoritos
scripts/scrapers/ScraperInterface.php
scripts/scrapers/All4RunningScraper.php
scripts/scrapers/PortugalRunningScraper.php
scripts/scrapers/AAAPortoScraper.php
scripts/scrapers/FpaCompeticoesScraper.php
scripts/scrapers/AABragaScraper.php
scripts/scrapers/AdalLeiriaScraper.php
scripts/scrapers/TheEventsCalendarScraper.php  -- genérico: sites WordPress com o plugin "The Events Calendar"
scripts/run_scraper.php              -- ponto de entrada do scraper (CLI)

public/_bootstrap.php                -- sessão + ligação BD + $auth, incluído por todas as páginas
public/index.php                     -- calendário público (filtros: mês, distância, fonte, pesquisa)
public/meu-calendario.php            -- provas guardadas pelo utilizador autenticado + botões de exportação .ics
public/meu-calendario-ics.php        -- "o meu calendário" em .ics (descarga com sessão, ou subscrição com ?token=)
public/lib/CalendarioIcs.php         -- gera o texto iCalendar (RFC 5545) a partir das provas
public/favorito.php                  -- endpoint POST: adiciona/remove uma prova do "meu calendário"
public/login.php / registo.php / logout.php
public/auth/google-iniciar.php       -- redireciona para o ecrã de login da Google
public/auth/google-callback.php      -- troca o "code" por perfil e inicia sessão
public/lib/Auth.php                  -- sessão atual, CSRF, exigirLogin()
public/partials/cabecalho.php        -- nav com estado de sessão, reutilizado em todas as páginas
public/partials/lista-corridas.php   -- listagem de provas agrupada por mês, com botão de favorito
public/assets/estilo.css             -- CSS partilhado

docs/MANUAL.md                       -- este ficheiro
```

## Fontes cobertas pelo scraper

| Fonte | URL | Cobertura | Notas |
|---|---|---|---|
| All4Running | `all4running.pt/provas/provas-de-estrada/` | Todas as páginas de paginação (~10 provas/página) | Nome, data, local, tipo e distâncias vêm diretamente do HTML da listagem. |
| Portugal Running | `portugalrunning.com/calendario-de-corridas-de-estrada/` | Só o mês corrente e o seguinte (o que a página mostra por omissão) | Tipo, região e distâncias são **inferidos** a partir das classes CSS de categorização do site (ex.: `evo_corrida-10-km`, `evo_algarve-e-sul`); podem ficar vazios para eventos com etiquetagem atípica. |
| AAAPorto (Associação de Atletismo do Porto) | `aaporto.com/.../calendario-competitivo/estrada` | Primeiras 15 páginas (~120 registos) | A listagem (~500 registos) não está ordenada por data — mistura provas passadas e futuras pela ordem de inserção. O scraper descarta qualquer prova com data já passada. Sem distância na listagem (fica `null`). Foco na região do Porto. |
| FPA Competições | `beta.fpacompeticoes.pt/calendar` | Janela mostrada por omissão pela página (~3-4 meses à frente) | Filtra `tipo=Estrada`. Os dados vêm de JSON embutido na página (`window.__remixContext`), não de HTML tradicional — é uma app Remix renderizada no servidor. Um pedido extra por prova a `/competition/{id}` obtém a distância real a partir dos escalões de inscrição. |

| AA Braga | `aabraga.pt/pt/calendario` | Todos os eventos futuros | JSON embutido na página (`window.AABragaCalendarioEventos`, alimenta um FullCalendar). Usa o campo `type`; quando vem vazio decide pelo nome (`NomeProvaHelper::pareceEstrada`). Exclui âmbito "Internacional". |
| ADA Leiria | `adal.pt/calendario.php` | Todos os eventos futuros | HTML sem tipo nem local: o tipo é deduzido do nome (exclui trail, marcha, convívios...). O link "Programa" (PDF do regulamento) é usado como link do evento. |
| AA São Miguel, AA Madeira, AA Santarém | `/wp-json/tribe/events/v1/events` de cada site | Eventos futuros (API paginada) | `TheEventsCalendarScraper`, uma instância por site. Estrada = categoria com "estrada" (ou, sem categorias, pelo nome). Madeira e Santarém têm hoje poucos ou nenhuns eventos no calendário do site (publicam sobretudo em PDF), mas passam a entrar automaticamente se o usarem. |

### Associações distritais (levantamento de 2026-09-30)

Das 22 associações filiadas na FPA ([lista](https://fpatletismo.pt/atletismo/institucional/associados-efetivos/)), só as da tabela acima têm calendário legível automaticamente. O Porto já estava coberto (AAAPorto).
- **Só em PDF** (frágil, fora de âmbito): Aveiro, Castelo Branco, Coimbra, Évora, Viana do Castelo, Setúbal.
- **Sites Wix** (conteúdo gerado por JavaScript): Lisboa, Ilha Terceira.
- **Sem calendário online / site indisponível**: Algarve (domínio não resolve), Beja, Guarda, Bragança, Viseu, Faial (calendário vazio), Vila Real (bloqueia pedidos automáticos, HTTP 406).
- **Portalegre** (`aadp.pt/calendario/`): tabela HTML, mas com dados incoerentes (colunas trocadas, eventos de formação marcados como "Estrada") — adiado.

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
3. Usar `DateHelper::parseTextoLivre()` ou `DateHelper::toIso()` para normalizar datas em português.
4. Registar a nova classe em `scripts/run_scraper.php` (`require` + adicionar ao array `$scrapers`).

## Site público (`public/`)

Correr localmente com o servidor embutido do PHP, a partir da pasta `public/`:
```
php -S localhost:8000 -t public
```
Em produção, aponta o *document root* do Apache/Nginx diretamente para `public/`.

### Filtros do calendário

`index.php` filtra por mês, **distância** (faixas pré-definidas em `DistanciaHelper::FAIXAS`, ex. "5 a 10 km", "21 a 42 km"), fonte, e pesquisa livre por nome/local. O filtro de distância usa a tabela `corridas_distancias`, sincronizada automaticamente pelo scraper a partir do texto em `corridas.distancias` (`DistanciaHelper::extrairKm()`).

### Contas e "o meu calendário"

- Registo por email/password (`public/registo.php`) — password guardada com `password_hash()`.
- Login por email/password (`public/login.php`) ou com Google (`public/auth/google-iniciar.php` → `google-callback.php`), fluxo OAuth 2.0 "authorization code": troca o `code` por um `access_token` e pede o perfil ao endpoint `userinfo` da Google (não valida o `id_token` manualmente — evita reimplementar a verificação de assinatura JWT).
- Um utilizador pode existir só com password, só com Google, ou com as duas (a conta é associada pelo email).
- Sessão guardada em `$_SESSION['utilizador_id']`; todas as ações que alteram estado (favoritos, logout) validam um token CSRF guardado em sessão.
- "O meu calendário" (`public/meu-calendario.php`) lista as provas marcadas pelo utilizador; o botão "+ Adicionar" / "✓ No meu calendário" em cada prova chama `public/favorito.php` (POST), que alterna a linha em `favoritos`.

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
