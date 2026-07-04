/**
 * Filtrado en vivo de los listados del panel, SIN recargar la página.
 *
 *  - <form data-live>: al teclear (texto, con debounce) o cambiar (select/fecha)
 *    se pide al servidor la lista filtrada por fetch y se reemplaza solo el
 *    bloque <div data-live-results> (la tabla + paginación). El formulario queda
 *    intacto, así que no se pierde el foco ni el cursor. El servidor sigue
 *    haciendo el filtrado real (respeta paginación y permisos).
 *  - Paginación dentro de los resultados: también navega por fetch (sin recarga).
 *  - input[data-filter-rows="#tabla"]: filtra filas en el cliente al instante,
 *    sin pedir nada al servidor (para listas con todo cargado, p. ej. la
 *    descarga de diplomas; conserva las casillas marcadas).
 *
 * Sin JS, los formularios y los enlaces funcionan igual (recarga normal).
 */
(function () {
    'use strict';

    function results() { return document.querySelector('[data-live-results]'); }

    function swap(url, fallback) {
        var current = results();
        if (!current) { fallback(); return; }
        current.style.opacity = '0.5';
        fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-live-results]');
                var cur = results();
                if (!fresh || !cur) { fallback(); return; }
                cur.replaceWith(fresh);
                try { window.history.replaceState(null, '', url); } catch (e) { /* noop */ }
            })
            .catch(fallback);
    }

    function formUrl(form) {
        var qs = new URLSearchParams(new FormData(form)).toString();
        return form.action + (qs ? '?' + qs : '');
    }

    function init() {
        // Formularios de filtro: AJAX en lugar de recarga.
        Array.prototype.forEach.call(document.querySelectorAll('form[data-live]'), function (form) {
            var timer = null;
            var go = function () { swap(formUrl(form), function () { form.submit(); }); };

            Array.prototype.forEach.call(
                form.querySelectorAll('input[type="search"], input[type="text"]'),
                function (input) {
                    input.addEventListener('input', function () {
                        window.clearTimeout(timer);
                        timer = window.setTimeout(go, 350);
                    });
                }
            );
            Array.prototype.forEach.call(
                form.querySelectorAll('select, input[type="date"]'),
                function (control) { control.addEventListener('change', go); }
            );
            // Enter o botón "Filtrar"/"Buscar": también sin recarga.
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                window.clearTimeout(timer);
                go();
            });
        });

        // Paginación dentro de los resultados, sin recarga.
        document.addEventListener('click', function (e) {
            if (!e.target.closest) { return; }
            var link = e.target.closest('[data-live-results] nav.pagination a');
            if (!link || !link.getAttribute('href')) { return; }
            e.preventDefault();
            var href = link.getAttribute('href');
            swap(href, function () { window.location = href; });
        });

        // Filtrado client-side de filas (instantáneo, conserva selección).
        Array.prototype.forEach.call(document.querySelectorAll('input[data-filter-rows]'), function (input) {
            var table = document.querySelector(input.getAttribute('data-filter-rows'));
            if (!table) { return; }
            var rows = table.querySelectorAll('tbody tr');
            var apply = function () {
                var q = input.value.trim().toLowerCase();
                Array.prototype.forEach.call(rows, function (tr) {
                    var hay = (tr.getAttribute('data-search') || tr.textContent || '').toLowerCase();
                    tr.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
                });
            };
            input.addEventListener('input', apply);
            apply();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
