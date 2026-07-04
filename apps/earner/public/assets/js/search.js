/**
 * Autocompletar del buscador de personas en la cabecera de la wallet pública.
 *
 * Al teclear (con debounce) pide a /buscar?q=... un fragmento HTML con las
 * coincidencias (foto + nombre) y lo muestra en un dropdown. Click afuera o
 * input vacío lo oculta. Sin JS, no aparece nada raro (el input queda inerte).
 */
(function () {
    'use strict';

    function init() {
        var box = document.querySelector('[data-people-search]');
        if (!box) { return; }
        var input = box.querySelector('[data-people-input]');
        var out = box.querySelector('[data-people-results]');
        var timer = null;
        var controller = null;

        function hide() { out.hidden = true; }
        function show() { out.hidden = false; }

        function run() {
            var q = input.value.trim();
            if (q === '') { out.innerHTML = ''; hide(); return; }
            if (controller && typeof controller.abort === 'function') { controller.abort(); }
            controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            fetch('/buscar?q=' + encodeURIComponent(q), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
                signal: controller ? controller.signal : undefined
            })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    out.innerHTML = html;
                    if (html.trim() === '') { hide(); } else { show(); }
                })
                .catch(function () { /* abortado o error de red: ignorar */ });
        }

        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(run, 250);
        });
        input.addEventListener('focus', function () {
            if (input.value.trim() !== '' && out.innerHTML.trim() !== '') { show(); }
        });
        document.addEventListener('click', function (e) {
            if (!box.contains(e.target)) { hide(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { hide(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
