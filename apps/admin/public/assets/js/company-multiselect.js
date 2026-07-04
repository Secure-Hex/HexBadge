/**
 * Realza cada [data-ms] (partial layout/company_multiselect.php) como un
 * multi-select con búsqueda: botón con conteo, panel desplegable con filtro y
 * chips de lo seleccionado. Los checkboxes son inputs reales del form, así que
 * el envío es nativo; sin JS el panel queda visible como lista normal.
 */
(function () {
    'use strict';

    function init(root) {
        var trigger = root.querySelector('[data-ms-trigger]');
        var panel   = root.querySelector('[data-ms-panel]');
        var search  = root.querySelector('[data-ms-search]');
        var list    = root.querySelector('[data-ms-list]');
        var chips   = root.querySelector('[data-ms-chips]');
        var countEl = root.querySelector('[data-ms-count]');
        var empty   = root.querySelector('[data-ms-empty]');
        if (!trigger || !panel || !list) { return; }
        var boxes = Array.prototype.slice.call(list.querySelectorAll('input[type="checkbox"]'));

        function labelOf(box) {
            var span = box.parentNode.querySelector('span');
            return span ? span.textContent : box.value;
        }

        function open() { panel.hidden = false; trigger.setAttribute('aria-expanded', 'true'); if (search) { search.focus(); } }
        function close() { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); }

        function render() {
            var checked = boxes.filter(function (b) { return b.checked; });
            var n = checked.length;
            countEl.textContent = n === 0 ? 'Elegir empresas' : (n === 1 ? '1 empresa' : n + ' empresas');
            root.classList.toggle('ms-has', n > 0);
            chips.textContent = '';
            checked.forEach(function (b) {
                var chip = document.createElement('span');
                chip.className = 'ms-chip';
                chip.appendChild(document.createTextNode(labelOf(b)));
                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'ms-chip-x';
                x.setAttribute('aria-label', 'Quitar ' + labelOf(b));
                x.innerHTML = '&times;';
                x.addEventListener('click', function () { b.checked = false; render(); });
                chip.appendChild(x);
                chips.appendChild(chip);
            });
        }

        function filter() {
            var q = (search.value || '').trim().toLowerCase();
            var any = false;
            Array.prototype.forEach.call(list.querySelectorAll('.ms-opt'), function (opt) {
                var hit = opt.getAttribute('data-ms-name').indexOf(q) !== -1;
                opt.style.display = hit ? '' : 'none';
                if (hit) { any = true; }
            });
            if (empty) { empty.hidden = any; }
        }

        trigger.addEventListener('click', function () { panel.hidden ? open() : close(); });
        boxes.forEach(function (b) { b.addEventListener('change', render); });
        if (search) { search.addEventListener('input', filter); }
        root.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) { close(); trigger.focus(); }
        });
        document.addEventListener('click', function (e) {
            if (!panel.hidden && !root.contains(e.target)) { close(); }
        });

        render();
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ms]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
