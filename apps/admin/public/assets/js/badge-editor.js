// Diseñador de insignias.
//
// El navegador no dibuja nada: solo edita la receta y le pide el SVG al
// servidor, que usa el mismo render con el que después se guarda el archivo.
// Así no puede haber diferencia entre lo que se ve y lo que queda.
//
// Las capas se arrastran con manijas en HTML colocadas encima del dibujo, en
// vez de manipular el SVG: mover un rectángulo es más simple y más predecible
// que tocar el documento que devuelve el servidor.
(function () {
    'use strict';

    var field   = document.getElementById('bde-recipe');
    var stage   = document.getElementById('bde-stage');
    var canvas  = document.getElementById('bde-svg');
    var handles = document.getElementById('bde-handles');
    if (!field || !stage) {
        return;
    }

    var state = {};
    try { state = JSON.parse(field.value || '{}'); } catch (e) { state = {}; }
    if (!Array.isArray(state.images)) { state.images = []; }
    if (!state.mark || typeof state.mark !== 'object') { state.mark = { type: 'none', value: '' }; }

    var selected = -1;
    var timer = null;
    var MAX = 6;

    /** Lee un valor anidado con notación "a.b". */
    function get(path) {
        return path.split('.').reduce(function (o, k) { return o == null ? undefined : o[k]; }, state);
    }
    function set(path, value) {
        var parts = path.split('.');
        var last  = parts.pop();
        var obj   = parts.reduce(function (o, k) { return (o[k] = o[k] || {}); }, state);
        obj[last] = value;
    }

    // ---- controles ----
    var controls = document.querySelectorAll('[data-k]');

    function hydrate() {
        controls.forEach(function (el) {
            var v = get(el.dataset.k);
            if (v === undefined) { return; }
            if (el.type === 'checkbox') { el.checked = !!v; } else { el.value = v; }
        });
        output('titleSize', state.titleSize, 'x');
        output('tracking', state.tracking, 'px');
    }

    function output(id, value, suffix) {
        var o = document.getElementById('o-' + id);
        if (o) { o.textContent = (Math.round(value * 100) / 100) + (suffix || ''); }
    }

    controls.forEach(function (el) {
        el.addEventListener('input', function () {
            var k = el.dataset.k;
            var v = el.type === 'checkbox' ? el.checked
                  : (el.type === 'range' ? parseFloat(el.value) : el.value);
            set(k, v);
            // Las iniciales son un objeto: sin tipo, el servidor las descarta.
            if (k === 'mark.value') { state.mark.type = String(v).trim() === '' ? 'none' : 'initials'; }
            if (k === 'titleSize') { output('titleSize', v, 'x'); }
            if (k === 'tracking') { output('tracking', v, 'px'); }
            sync();
        });
    });

    // ---- biblioteca de imágenes ----
    document.querySelectorAll('.bde-asset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (state.images.length >= MAX) {
                window.alert('Ya alcanzaste el máximo de ' + MAX + ' imágenes.');
                return;
            }
            state.images.push({
                dir: btn.dataset.dir, file: btn.dataset.file,
                x: 0.5, y: 0.35, w: 0.22, rot: 0, op: 1
            });
            selected = state.images.length - 1;
            sync();
        });
    });

    var remove = document.getElementById('bde-remove');
    if (remove) {
        remove.addEventListener('click', function () {
            if (selected >= 0) { state.images.splice(selected, 1); selected = -1; sync(); }
        });
    }

    ['imgW', 'imgRot', 'imgOp'].forEach(function (id) {
        var el = document.getElementById('f-' + id);
        if (!el) { return; }
        el.addEventListener('input', function () {
            if (selected < 0) { return; }
            var img = state.images[selected];
            var v = parseFloat(el.value);
            if (id === 'imgW')   { img.w = v / 100; output('imgW', v, '%'); }
            if (id === 'imgRot') { img.rot = v; output('imgRot', v, '°'); }
            if (id === 'imgOp')  { img.op = v / 100; output('imgOp', v, '%'); }
            sync();
        });
    });

    function refreshSelected() {
        var box = document.getElementById('bde-selected');
        var cnt = document.getElementById('bde-count');
        if (cnt) { cnt.textContent = '(' + state.images.length + '/' + MAX + ')'; }
        if (!box) { return; }
        if (selected < 0 || !state.images[selected]) {
            box.hidden = true;
            return;
        }
        var img = state.images[selected];
        box.hidden = false;
        document.getElementById('f-imgW').value   = Math.round(img.w * 100);
        document.getElementById('f-imgRot').value = img.rot || 0;
        document.getElementById('f-imgOp').value  = Math.round((img.op == null ? 1 : img.op) * 100);
        output('imgW', Math.round(img.w * 100), '%');
        output('imgRot', img.rot || 0, '°');
        output('imgOp', Math.round((img.op == null ? 1 : img.op) * 100), '%');
    }

    // ---- manijas de las capas ----
    function drawHandles() {
        handles.innerHTML = '';
        var side = stage.clientWidth;
        state.images.forEach(function (img, i) {
            var w = img.w * side;
            var el = document.createElement('div');
            el.className = 'bde-handle' + (i === selected ? ' is-sel' : '');
            el.style.left   = (img.x * side - w / 2) + 'px';
            el.style.top    = (img.y * side - w / 2) + 'px';
            el.style.width  = w + 'px';
            el.style.height = w + 'px';
            el.tabIndex = 0;
            el.setAttribute('role', 'button');
            el.setAttribute('aria-label', 'Imagen ' + (i + 1) + ' de ' + state.images.length);

            var grip = document.createElement('span');
            grip.className = 'bde-grip';
            el.appendChild(grip);

            el.addEventListener('pointerdown', function (ev) { start(ev, i, ev.target === grip); });
            el.addEventListener('focus', function () { selected = i; refreshSelected(); drawHandles(); });
            handles.appendChild(el);
        });
    }

    function start(ev, index, resizing) {
        ev.preventDefault();
        selected = index;
        refreshSelected();
        drawHandles();

        var side  = stage.clientWidth;
        var img   = state.images[index];
        var rect  = stage.getBoundingClientRect();
        var offX  = (ev.clientX - rect.left) / side - img.x;
        var offY  = (ev.clientY - rect.top) / side - img.y;

        function move(e) {
            var px = (e.clientX - rect.left) / side;
            var py = (e.clientY - rect.top) / side;
            if (resizing) {
                img.w = Math.max(0.04, Math.min(0.9, (px - img.x) * 2));
            } else {
                img.x = Math.max(0, Math.min(1, px - offX));
                img.y = Math.max(0, Math.min(1, py - offY));
            }
            drawHandles();
            queue();
        }
        function up() {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            refreshSelected();
            sync();
        }
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    }

    // Teclado: mover y quitar sin depender del arrastre.
    document.addEventListener('keydown', function (e) {
        if (selected < 0 || !state.images[selected]) { return; }
        if (document.activeElement && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) { return; }
        var img  = state.images[selected];
        var step = e.shiftKey ? 0.05 : 0.01;
        var map  = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (map[e.key]) {
            e.preventDefault();
            img.x = Math.max(0, Math.min(1, img.x + map[e.key][0]));
            img.y = Math.max(0, Math.min(1, img.y + map[e.key][1]));
            drawHandles();
            sync();
        } else if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            state.images.splice(selected, 1);
            selected = -1;
            sync();
        }
    });

    // ---- vista previa ----
    function queue() {
        field.value = JSON.stringify(state);
    }

    function sync() {
        queue();
        refreshSelected();
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            fetch('/admin/templates/designer/preview?r=' + encodeURIComponent(field.value), {
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
                .then(function (svg) {
                    canvas.innerHTML = svg;
                    drawHandles();
                })
                .catch(function () { /* una vista previa fallida no rompe la edición */ });
        }, 220);
    }

    window.addEventListener('resize', drawHandles);

    hydrate();
    sync();
})();
