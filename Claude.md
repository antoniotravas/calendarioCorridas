## Aplicação para listar calendário de corridas de estrada (atletismo)

# Objetivo
    - Criar uma aplicação que pesquise calendários portugueses onde sejam divuladas corridas de atletismo em estrada
    - Numa primeira fase o programa corre um script que tenta encontar aa informação pretendida
    - A infoirmação é apresetada em forma d ecalendário, permitimdo fiiltro nos meses, por distancia e outro que possam ser inetressantes
    - Os dados a apresnetar deverão ser em portugês europeu

# Plaforma e tecnologias

    - deixo ao teu criterio as tecnologias que poderás usar
    - Na minha a otica poderia ser um fronte end em html e javascript e css e o backenda em php ou similar, no entanto a página irá correr num server com acesso a abases de dados em mysql
    - Os dados a apresentar deverão levar com pormenor ao site do evento e ainda onde foram encontrados 

# Manuais
    - Apresentar um manual para programaror, o que se usou, o fluxo da informação etc.
    - Ver docs/MANUAL.md para o manual técnico completo (tecnologias, fluxo de dados, estrutura de pastas, como correr, como adicionar fontes, como configurar login Google).

# Funcionalidades implementadas (registo cronológico)

    - Fase 1 — Script de recolha (scripts/run_scraper.php): recolhe corridas de estrada de três fontes portuguesas (All4Running, Portugal Running, AAAPorto) e grava-as em MySQL, sem duplicados.
    - Fase 2 — Site público (public/index.php): calendário navegável no browser, agrupado por mês, com filtros por mês, fonte e pesquisa por nome/local.
    - Filtro por distância: as distâncias de cada prova (ex. "10K, 5K") são extraídas para uma tabela própria (corridas_distancias) e o calendário passou a ter um filtro por faixa de distância (até 5 km, 5-10 km, 10-15 km, 15-21 km/Meia-Maratona, 21-42 km/Maratona, mais de 42 km).
    - Nova fonte de dados: AAAPorto (Associação de Atletismo do Porto), foco na região do Porto.
    - Contas de utilizador: registo/login por email e password, e login com Google (OAuth 2.0 — precisa de credenciais próprias na Google Cloud Console para ficar ativo, ver MANUAL.md).
    - "O meu calendário": qualquer utilizador com sessão iniciada pode marcar provas como suas (como um carrinho) a partir do calendário público, e consultá-las à parte em /meu-calendario.php.
    - Nova fonte: FPA Competições (plataforma de competições da Federação Portuguesa de Atletismo).
    - Associações distritais como fontes: Braga, Leiria, São Miguel, Madeira e Santarém (as restantes só publicam em PDF ou não têm calendário legível — ver MANUAL.md).
    - Provas repetidas entre fontes: a mesma prova encontrada em vários sites passa a aparecer uma só vez, com a lista de todas as fontes onde foi encontrada (tabela corridas_fontes).
    - Aviso no scraper quando uma fonte falha ou deixa de devolver provas (provável mudança no site).
    - Exportar "O meu calendário" para o calendário do telemóvel: subscrição por link pessoal (Google Calendar, iPhone/Mac, Outlook — atualiza-se sozinha) ou descarga de um ficheiro .ics.
    - Novas fontes: plataformas de inscrições Lap2Go, Stop and Go e Acorrer, e o organizador Xistarca (região de Lisboa).
    - Fontes organizadoras (Xistarca, Lap2Go, Stop and Go, Acorrer): quando uma prova aparece numa delas, o link do evento aponta sempre para a página delas, mesmo que a prova venha também de outras fontes.
    - Novo visual (topo claro, letra Barlow, tema "estrada"): miniatura (cartaz) em cada prova, ou um dorsal com o dia quando não há imagem; só provas a partir de hoje; 20 provas de cada vez, com as seguintes a carregar ao descer; filtros aplicam-se sozinhos; "Guardar" sem recarregar a página; botão para voltar ao topo.
    - Trails, ultra trails e corta-mato passam a entrar (All4Running trail, AAAPorto corta-mato, FPA, AA Braga, Stop and Go trail, e pelo nome nas restantes), com filtro "Tipo" (estrada, trail, corta-mato). Provas agrupadas por dia (dorsal com o dia + cartões), cartão inteiro clicável, fade in dos cartões, topo todo claro, frase do total de acordo com os filtros.
    - Provas da All4Running: o link da prova passa a ser o site oficial do organizador (lido na página da prova na All4Running), em vez da página da All4Running, que fica como link da fonte.
    - Nova fonte organizadora: Trilho Perdido (trails e atletismo, sobretudo região de Lisboa e Vale do Tejo).
    - Competições de pista (ar livre, coberta e pista curta): novo tipo "Pista" no filtro, com etiqueta própria. Entram pela FPA Competições (onde as associações registam as suas competições), AA Braga, AAAPorto (listagens de pista), São Miguel/Madeira (categorias de pista) e, pelo nome, Leiria. Novas fontes de associações: ADA Coimbra e AA Lisboa (plataforma de competições da AAL). Provas de pista com nomes genéricos ("Torneio de Abertura") só se juntam entre fontes se o local coincidir.
    - Prémios monetários: o scraper lê a página e o regulamento (PDF ou página) de cada prova e marca se há prémios em dinheiro para os mais bem classificados. No site, ícone "€" ao lado do nome (verde: há; cinzento riscado: não há; sem ícone: por saber), com link para o documento onde foi lido, e filtro "Prémios €". Precisa da migração sql/migracao_007_premios.sql.
    - Gestão de utilizadores: os prémios monetários (ícone "€" e filtro) só aparecem a administradores e a quem eles derem acesso, na página "Gestão" (/gestao-utilizadores.php). Tornar alguém administrador: php scripts/definir_admin.php email. Precisa da migração sql/migracao_008_permissoes.sql.
    - Página individual de cada prova (/prova.php?id=N): ao clicar num cartão abre a página da prova, com cartaz, data por extenso, local (com mapa), tipo, distâncias, botão para o site da prova, guardar no meu calendário, adicionar ao Google Calendar ou descarregar .ics, prémios (só para quem tem acesso) e as fontes onde foi encontrada. "Calendário" volta à lista com os mesmos filtros.
    - Página da prova completa: dia e hora, local, distrito e região (calculados a partir da localidade), tipo, distâncias, programa com escalões (FPA e Xistarca) e, em destaque, a organização com o link para o organizador (nome e site lidos na AA Braga, Lap2Go e Stop and Go; hora na Acorrer). Para quem vê os prémios monetários, uma pequena tabela com os valores lidos no regulamento. Precisa da migração sql/migracao_009_detalhes.sql.
    - Provas encontradas em vários sites: o cartão mostra só a referência ao organizador (a lista completa das fontes fica na página da prova).
    - O filtro "Fonte" passa a aparecer só aos administradores.
    - Correção no scraper da FPA: as provas com inscrições fechadas perdiam a distância e o regulamento (a página tem outro formato).
    - Página da prova mais condensada: tipo e distâncias em etiquetas no topo, local com distrito e região numa só linha, "Adicionar ao calendário" junto aos botões (sem a lista de dados repetida). Prémios monetários numa só tabela simples (lugar × classificação) com o total a distribuir; a leitura dos valores percebe mais formatos e também lê o excerto quando a tabela não foi lida.
    - Ranking dos prémios (só administradores, /ranking-premios.php, link "Ranking €"): provas ordenadas pelo total de prémios em dinheiro (soma de todos os valores, a dobrar quando valem para masculinos e femininos). Para voltar a ler os valores que faltavam: sql/migracao_010_premios_tabela.sql.
    - Leitura dos regulamentos em PDF corrigida: tabelas feitas no Word perdiam letras e todos os valores (ex. São Silvestre do Oeste, Stop and Go). Tabelas com uma coluna por escalão passam a contar todas as colunas no total (ex. 5.400 € na São Silvestre do Oeste). A migração sql/migracao_010_premios_tabela.sql passa a reler os valores de todas as provas com prémios.
    - Leitura dos valores dos prémios alargada: lugares por extenso ("o vencedor recebe 125 €, o segundo 100 €"), abreviaturas ("1º Class."), listas ("50 €, 30 € e 20 € para 1.º, 2.º e 3.º"), algarismos partidos pelo PDF ("12 5 €"), tabelas iguais por escalão contadas todas e, quando só há o total, o "total anunciado". Na base local, as provas com valores lidos passaram de 12 para 48 (de 67 com prémios). Correr de novo sql/migracao_010_premios_tabela.sql no servidor depois do pull.
    - Provas da Portugal Running: o link da prova passa a ser o site oficial do organizador ou a página de inscrições (lido na página da prova na Portugal Running, que é só um calendário), como já acontecia na All4Running; o organizador vem do campo "Entidade Organizadora".
