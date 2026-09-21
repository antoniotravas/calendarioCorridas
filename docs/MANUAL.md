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
Scraper específico da fonte (All4RunningScraper / PortugalRunningScraper / AAAPortoScraper)
      │  DOMDocument + DOMXPath → extrai e normaliza campos
      ▼
Corrida (objeto normalizado: nome, data, local, distâncias, tipo, região, url_evento, fonte, url_fonte)
      │  Database::upsertCorrida()  (hash_dedup evita duplicados; sincroniza corridas_distancias)
      ▼
Tabelas `corridas` + `corridas_distancias` (MySQL)
```

`scripts/run_scraper.php` é o ponto de entrada: instancia todos os scrapers, corre cada um dentro de um `try/catch` (uma fonte a falhar não impede as restantes) e grava os resultados.

## Estrutura de pastas

```
sql/schema.sql                       -- schema completo (corridas, corridas_distancias, utilizadores, favoritos)

scripts/config.example.php           -- template de credenciais (copiar para config.php)
scripts/lib/Corrida.php              -- objeto de valor com os dados normalizados de uma prova
scripts/lib/HttpClient.php           -- pedidos GET/POST via cURL
scripts/lib/DateHelper.php           -- datas em português: parsing, formatação, agrupar por mês
scripts/lib/DistanciaHelper.php      -- extrai distâncias (km) do texto livre; faixas do filtro de distância
scripts/lib/Database.php             -- ligação PDO: upsert de corridas, listagem com filtros, utilizadores, favoritos
scripts/scrapers/ScraperInterface.php
scripts/scrapers/All4RunningScraper.php
scripts/scrapers/PortugalRunningScraper.php
scripts/scrapers/AAAPortoScraper.php
scripts/run_scraper.php              -- ponto de entrada do scraper (CLI)

public/_bootstrap.php                -- sessão + ligação BD + $auth, incluído por todas as páginas
public/index.php                     -- calendário público (filtros: mês, distância, fonte, pesquisa)
public/meu-calendario.php            -- provas guardadas pelo utilizador autenticado
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

### Fontes deixadas para mais tarde

- **correrporprazer.com/provas-de-estrada/** — a listagem só aparece depois de escolher um distrito, carregada via AJAX; precisa de um scraper diferente (simular os pedidos AJAX por distrito) ou de automação de browser.
- **FPA (fpatletismo.pt/calendario-e-provas-homologadas/)** — calendário oficial, mas maioritariamente distribuído em PDF e páginas de notícia, não numa listagem HTML estruturada.

## Como correr o scraper

1. Criar/atualizar a base de dados: importar `sql/schema.sql` (phpMyAdmin, ou `mysql -u root -p < sql/schema.sql`). É seguro voltar a correr — usa `CREATE TABLE IF NOT EXISTS`, não apaga dados existentes.
2. Copiar `scripts/config.example.php` para `scripts/config.php` e ajustar `db_host`, `db_user`, `db_pass` conforme o XAMPP/WAMP local.
3. Correr o scraper:
   ```
   php scripts/run_scraper.php
   ```
4. O script imprime, por fonte, quantas provas foram encontradas, quantas são novas e quantas foram atualizadas.

Correr o script várias vezes é seguro: cada prova tem um `hash_dedup` (nome + data + local); se já existir, a linha é atualizada em vez de duplicada, e as distâncias em `corridas_distancias` são recalculadas a partir do texto de `distancias`.

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

### Configurar o login com Google

O botão "Continuar com o Google" só aparece funcional se `scripts/config.php` tiver `google_client_id`/`google_client_secret` preenchidos (por omissão ficam vazios e a página mostra um aviso). Para configurar:

1. Na [Google Cloud Console](https://console.cloud.google.com/), criar um projeto (ou reutilizar um existente).
2. Ir a "APIs e serviços" → "Ecrã de consentimento OAuth" e configurá-lo (tipo "Externo" chega para testes).
3. Em "Credenciais", criar um "ID de cliente OAuth" do tipo "Aplicação Web".
4. Em "URIs de redirecionamento autorizados", adicionar exatamente o valor de `google_redirect_uri` do `config.php` (ex.: `http://localhost:8000/auth/google-callback.php` em desenvolvimento; em produção, o URL público equivalente com HTTPS).
5. Copiar o "ID de cliente" e o "Segredo do cliente" para `google_client_id` e `google_client_secret` em `scripts/config.php`.

## Limitações conhecidas

- Não há agendamento automático (cron) do scraper — corre manualmente.
- Portugal Running só cobre os próximos ~2 meses por execução; AAAPorto cobre só a sua janela de paginação (ver tabela de fontes).
- Tipo/região/distâncias de Portugal Running são heurísticos, baseados em classes CSS do site.
- AAAPorto não expõe distância nas provas listadas.
- O login com Google exige credenciais próprias da Google Cloud Console (ver secção acima) — sem elas, só o login por email/password está disponível.
- Sem verificação de email no registo local (uma conta fica ativa imediatamente após o registo).
