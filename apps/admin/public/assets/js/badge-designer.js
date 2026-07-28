// Diseñador de insignias: arma la receta desde los controles y pide la vista
// previa al servidor.
//
// La previa se pide por GET con la receta en la URL, no con fetch + blob: la
// CSP del panel es `img-src 'self' data:`, así que una imagen servida desde
// URL.createObjectURL() queda bloqueada sin ningún aviso. De paso, reasignar
// src cancela sola la petición anterior: no hace falta AbortController.
(function () {
    'use strict';

    var recipeField = document.getElementById('bd-recipe');
    var preview     = document.getElementById('bd-preview');
    if (!recipeField || !preview) {
        return;
    }

    var el = {
        shape:    document.getElementById('bd-shape'),
        fill:     document.getElementById('bd-fill'),
        accent:   document.getElementById('bd-accent'),
        finish:   document.getElementById('bd-finish'),
        ring:     document.getElementById('bd-ring'),
        ribbon:   document.getElementById('bd-ribbon'),
        logo:     document.getElementById('bd-logo'),
        initials: document.getElementById('bd-initials'),
        title:    document.getElementById('bd-title'),
        level:    document.getElementById('bd-level')
    };

    var state = {
        shape: 'hexagon',
        fill: '#1565d8',
        accent: '#0f1b2e',
        finish: 'bevel',
        ring: 'double',
        ribbon: false,
        logo: false,
        mark: { type: 'none', value: '' },
        title: '',
        level: ''
    };

    // Al editar un diseño guardado, los controles arrancan con sus valores.
    try {
        var saved = JSON.parse(recipeField.value || '{}');
        if (saved && typeof saved === 'object') {
            Object.keys(state).forEach(function (k) {
                if (saved[k] !== undefined) { state[k] = saved[k]; }
            });
        }
    } catch (e) { /* receta ilegible: se usan los valores por defecto */ }

    el.shape.value    = state.shape;
    el.finish.value   = state.finish;
    el.ring.value     = state.ring;
    el.ribbon.checked = !!state.ribbon;
    el.logo.checked   = !!state.logo;
    el.fill.value     = state.fill;
    el.accent.value   = state.accent;
    el.initials.value = (state.mark && state.mark.value) || '';
    el.title.value    = state.title || '';
    el.level.value    = state.level || '';

    var timer = null;

    function sync() {
        state.shape  = el.shape.value;
        state.finish = el.finish.value;
        state.ring   = el.ring.value;
        state.ribbon = el.ribbon.checked;
        state.logo   = el.logo.checked;
        state.fill   = el.fill.value;
        state.accent = el.accent.value;
        state.title  = el.title.value;
        state.level  = el.level.value;

        var ini = el.initials.value.trim();
        state.mark = ini === '' ? { type: 'none', value: '' } : { type: 'initials', value: ini };

        recipeField.value = JSON.stringify(state);

        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            // La empresa viaja aparte: el logo se lee del servidor, nunca del
            // navegador, para no confiar en una ruta que llega del cliente.
            var sel     = document.getElementById('company_id');
            var company = (sel && sel.value) || recipeField.dataset.company || '';
            var q = '/admin/templates/design/preview?r=' + encodeURIComponent(recipeField.value);
            if (company && company !== '0') { q += '&company=' + encodeURIComponent(company); }
            preview.src = q;
        }, 300);
    }

    Object.keys(el).forEach(function (k) {
        el[k].addEventListener('input', sync);
        el[k].addEventListener('change', sync);
    });

    sync();
})();
