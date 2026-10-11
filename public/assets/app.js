/*
 * Comportamento da página (tudo funciona sem JavaScript; isto só melhora):
 *  1. filtros aplicam-se ao mudar uma lista, sem carregar em "Procurar";
 *  2. mais provas carregam sozinhas ao chegar ao fim da lista;
 *  3. guardar uma prova no "meu calendário" sem recarregar a página;
 *  4. botão para voltar ao topo depois de descer bastante.
 */
(function () {
    'use strict';

    var reduzirMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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

                // Provas do mesmo mês juntam-se à secção desse mês que já está na página.
                modelo.content.querySelectorAll('section.mes').forEach(function (seccao) {
                    var seccoes = listaProvas.querySelectorAll('section.mes');
                    var ultima = seccoes[seccoes.length - 1];
                    if (ultima && ultima.dataset.mes === seccao.dataset.mes) {
                        var destino = ultima.querySelector('ul.lista');
                        seccao.querySelectorAll('li.prova').forEach(function (prova) { destino.appendChild(prova); });
                    } else {
                        listaProvas.appendChild(seccao);
                    }
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
