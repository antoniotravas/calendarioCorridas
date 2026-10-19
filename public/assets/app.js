/*
 * Comportamento da página (tudo funciona sem JavaScript; isto só melhora):
 *  1. filtros aplicam-se ao mudar uma lista, sem carregar em "Procurar";
 *  2. mais provas carregam sozinhas ao chegar ao fim da lista;
 *  3. guardar uma prova no "meu calendário" sem recarregar a página;
 *  4. botão para voltar ao topo depois de descer bastante;
 *  5. os cartões das provas aparecem com fade in ao chegar ao ecrã;
 *  6. na página da prova, "Calendário" volta atrás (mesmos filtros e posição);
 *  7. "Perto de mim": pede a posição ao browser e mostra as provas próximas.
 */
(function () {
    'use strict';

    window.calendarioPronto = true; // ver partials/head.php
    var reduzirMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // 5. Fade in dos cartões ----------------------------------------------------
    // Os que entram juntos aparecem em cascata (40 ms de diferença, no máximo 240 ms).
    var revelador = 'IntersectionObserver' in window
        ? new IntersectionObserver(function (entradas) {
            var ordem = 0;
            entradas.forEach(function (entrada) {
                if (!entrada.isIntersecting) { return; }
                var prova = entrada.target;
                prova.style.transitionDelay = Math.min(ordem++ * 40, 240) + 'ms';
                prova.classList.add('visivel');
                // Depois de aparecer, o atraso deixa de se aplicar (ex. ao passar o rato).
                prova.addEventListener('transitionend', function () { prova.style.transitionDelay = ''; }, { once: true });
                revelador.unobserve(entrada.target);
            });
        }, { rootMargin: '0px 0px -40px 0px' })
        : null;

    function revelar(raiz) {
        raiz.querySelectorAll('li.prova:not(.visivel)').forEach(function (prova) {
            if (revelador && !reduzirMovimento) {
                revelador.observe(prova);
            } else {
                prova.classList.add('visivel');
            }
        });
    }
    revelar(document);

    // 1. Filtros ---------------------------------------------------------------
    var filtros = document.querySelector('form.filtros');
    if (filtros) {
        filtros.classList.add('com-js');
        filtros.querySelectorAll('select').forEach(function (lista) {
            lista.addEventListener('change', function () { filtros.requestSubmit(); });
        });
        // Campos vazios ficam fora do endereço: "/?mes=10" em vez de "/?procura=&mes=10&...".
        filtros.addEventListener('submit', function () {
            filtros.querySelectorAll('input, select').forEach(function (campo) {
                if (campo.name && campo.value.trim() === '') { campo.disabled = true; }
            });
        });
    }

    // 7. Perto de mim ------------------------------------------------------------
    // A posição vai no endereço arredondada a 2 casas decimais (~1 km) e não é guardada.
    // Com o filtro ativo, mudar o período ("Próximo mês", "Sempre"...) aplica-se logo.
    var periodoPerto = document.querySelector('form.periodo-perto select');
    if (periodoPerto) {
        periodoPerto.addEventListener('change', function () { periodoPerto.form.requestSubmit(); });
    }
    var botaoPerto = document.querySelector('[data-perto]');
    if (botaoPerto && 'geolocation' in navigator) {
        botaoPerto.hidden = false;
        botaoPerto.addEventListener('click', function () {
            var texto = botaoPerto.textContent;
            botaoPerto.disabled = true;
            botaoPerto.textContent = 'A localizar…';
            navigator.geolocation.getCurrentPosition(function (posicao) {
                var url = new URL(window.location.href);
                url.searchParams.delete('pagina');
                url.searchParams.delete('mes');
                url.searchParams.set('perto', posicao.coords.latitude.toFixed(2) + ',' + posicao.coords.longitude.toFixed(2));
                window.location.href = url.pathname + url.search;
            }, function (erro) {
                // Escrito no botão (no telemóvel a dica do title não se vê); volta ao normal ao fim de 4 s.
                botaoPerto.textContent = erro.code === erro.PERMISSION_DENIED ? 'Localização não autorizada' : 'Localização indisponível';
                botaoPerto.classList.add('erro');
                setTimeout(function () {
                    botaoPerto.disabled = false;
                    botaoPerto.textContent = texto;
                    botaoPerto.classList.remove('erro');
                }, 4000);
            }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 });
        });
    }

    // 2. Carregar mais provas --------------------------------------------------
    var listaProvas = document.getElementById('lista-provas');
    var observador = 'IntersectionObserver' in window
        ? new IntersectionObserver(function (entradas) {
            entradas.forEach(function (entrada) {
                if (entrada.isIntersecting) { carregarMais(entrada.target); }
            });
        }, { rootMargin: '0px 0px 900px 0px' })
        : null;

    function ligarMais(link) {
        link.addEventListener('click', function (ev) {
            ev.preventDefault();
            carregarMais(link);
        });
        if (observador) { observador.observe(link); }
    }

    function carregarMais(link) {
        if (link.dataset.aCarregar) { return; }
        link.dataset.aCarregar = '1';
        link.classList.add('a-carregar');
        link.textContent = 'A carregar mais provas…';

        fetch(link.dataset.parcial, { credentials: 'same-origin' })
            .then(function (resposta) {
                if (!resposta.ok) { throw new Error('HTTP ' + resposta.status); }
                return resposta.text();
            })
            .then(function (html) {
                var modelo = document.createElement('template');
                modelo.innerHTML = html;

                // Provas de um mês (ou dia) que já está na página juntam-se a esse
                // mês (ou dia), em vez de o repetir.
                modelo.content.querySelectorAll('section.mes').forEach(function (seccao) {
                    var seccoes = listaProvas.querySelectorAll('section.mes');
                    var ultimaSeccao = seccoes[seccoes.length - 1];
                    if (!ultimaSeccao || ultimaSeccao.dataset.mes !== seccao.dataset.mes) {
                        listaProvas.appendChild(seccao);
                        revelar(seccao);
                        return;
                    }
                    seccao.querySelectorAll('.dia').forEach(function (dia) {
                        var dias = ultimaSeccao.querySelectorAll('.dia');
                        var ultimoDia = dias[dias.length - 1];
                        if (ultimoDia && ultimoDia.dataset.dia === dia.dataset.dia) {
                            var destino = ultimoDia.querySelector('ul.provas');
                            dia.querySelectorAll('li.prova').forEach(function (prova) { destino.appendChild(prova); });
                        } else {
                            ultimaSeccao.appendChild(dia);
                        }
                    });
                    revelar(ultimaSeccao);
                });

                if (observador) { observador.unobserve(link); }
                var seguinte = modelo.content.querySelector('a.mais');
                if (seguinte) {
                    link.replaceWith(seguinte);
                    ligarMais(seguinte);
                } else {
                    link.remove();
                }
            })
            .catch(function () {
                // Sem repetir sozinho (evita pedidos em ciclo): fica um botão para tentar outra vez.
                if (observador) { observador.unobserve(link); }
                delete link.dataset.aCarregar;
                link.classList.remove('a-carregar');
                link.textContent = 'Não foi possível carregar mais provas. Tentar outra vez';
            });
    }

    if (listaProvas) {
        document.querySelectorAll('a.mais').forEach(ligarMais);
    }

    // 3. Guardar no "meu calendário" -------------------------------------------
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.matches('form.favorito')) { return; }
        ev.preventDefault();

        var botao = form.querySelector('button');
        botao.disabled = true;

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (resposta) {
                if (resposta.status === 401) {
                    window.location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.search);
                    return null;
                }
                if (!resposta.ok) { throw new Error('HTTP ' + resposta.status); }
                return resposta.json();
            })
            .then(function (dados) {
                if (!dados) { return; }
                botao.classList.toggle('ativo', dados.ativo);
                botao.setAttribute('aria-pressed', dados.ativo ? 'true' : 'false');
                botao.title = dados.ativo ? 'Tirar do meu calendário' : 'Guardar no meu calendário';
                botao.querySelector('.rotulo').textContent = dados.ativo ? 'Guardada' : 'Guardar';
            })
            .catch(function () { form.submit(); }) // falhou o pedido: faz como sem JavaScript
            .finally(function () { botao.disabled = false; });
    });

    // 6. Voltar ao calendário -------------------------------------------------
    // Vindo do calendário (ou do "meu calendário"), volta atrás: o browser repõe
    // os filtros e a posição na lista. Aberta de outra forma (link partilhado,
    // depois de entrar na conta...), segue para o calendário.
    var voltarCalendario = document.querySelector('[data-voltar]');
    var origem = document.referrer.indexOf(location.origin) === 0 ? document.referrer.slice(location.origin.length) : '';
    if (voltarCalendario && history.length > 1 && /^\/(\?|$|meu-calendario\.php)/.test(origem)) {
        voltarCalendario.addEventListener('click', function (ev) {
            ev.preventDefault();
            history.back();
        });
    }

    // 4. Voltar ao topo ---------------------------------------------------------
    var voltarTopo = document.querySelector('.voltar-topo');
    if (voltarTopo) {
        var pendente = false;
        window.addEventListener('scroll', function () {
            if (pendente) { return; }
            pendente = true;
            requestAnimationFrame(function () {
                voltarTopo.hidden = window.scrollY < 1400;
                pendente = false;
            });
        }, { passive: true });
        voltarTopo.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: reduzirMovimento ? 'auto' : 'smooth' });
            var procura = document.querySelector('.filtros input[name="procura"]');
            if (procura) { procura.focus({ preventScroll: true }); }
        });
    }
})();
