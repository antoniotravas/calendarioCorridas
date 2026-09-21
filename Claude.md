#Aplicação para listar calendário de corridas de estrada (atletismo)

#Objetivo
    - Criar uma aplicação que pesquise calendários portugueses onde sejam divuladas corridas de atletismo em estrada
    - Numa primeira fase o programa corre um script que tenta encontar aa informação pretendida
    - A infoirmação é apresetada em forma d ecalendário, permitimdo fiiltro nos meses, por distancia e outro que possam ser inetressantes
    - Os dados a apresnetar deverão ser em portugês europeu

#Plaforma e tecnologias

    - deixo ao teu criterio as tecnologias que poderás usar
    - Na minha a otica poderia ser um fronte end em html e javascript e css e o backenda em php ou similar, no entanto a página irá correr num server com acesso a abases de dados em mysql
    - Os dados a apresentar deverão levar com pormenor ao site do evento e ainda onde foram encontrados 

#Manuais
    - Apresentar um manual para programaror, o que se usou, o fluxo da informação etc.
    - Ver docs/MANUAL.md para o manual técnico completo (tecnologias, fluxo de dados, estrutura de pastas, como correr, como adicionar fontes, como configurar login Google).

#Funcionalidades implementadas (registo cronológico)

    - Fase 1 — Script de recolha (scripts/run_scraper.php): recolhe corridas de estrada de três fontes portuguesas (All4Running, Portugal Running, AAAPorto) e grava-as em MySQL, sem duplicados.
    - Fase 2 — Site público (public/index.php): calendário navegável no browser, agrupado por mês, com filtros por mês, fonte e pesquisa por nome/local.
    - Filtro por distância: as distâncias de cada prova (ex. "10K, 5K") são extraídas para uma tabela própria (corridas_distancias) e o calendário passou a ter um filtro por faixa de distância (até 5 km, 5-10 km, 10-15 km, 15-21 km/Meia-Maratona, 21-42 km/Maratona, mais de 42 km).
    - Nova fonte de dados: AAAPorto (Associação de Atletismo do Porto), foco na região do Porto.
    - Contas de utilizador: registo/login por email e password, e login com Google (OAuth 2.0 — precisa de credenciais próprias na Google Cloud Console para ficar ativo, ver MANUAL.md).
    - "O meu calendário": qualquer utilizador com sessão iniciada pode marcar provas como suas (como um carrinho) a partir do calendário público, e consultá-las à parte em /meu-calendario.php.