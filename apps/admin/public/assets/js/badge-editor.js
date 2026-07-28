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
    // PHP serializa un array vacío como [], y un array de JS descarta las claves
    // de texto al pasar por JSON.stringify: las posiciones se perdían enteras.
    if (!state.pos || typeof state.pos !== 'object' || Array.isArray(state.pos)) { state.pos = {}; }

    var selected = null;   // 'i:<n>' para imagen, 't:<clave>' para texto
    var layout = {};       // posiciones efectivas que publica el servidor
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
        ['gradX','gradY','gradSpread','gradAngle','patternOp','stars','ornScale','ornY','ringW','ribbonY','ribbonW','arcR','arcSize'].forEach(function (k) {
            output(k, state[k], k === 'gradAngle' ? '°' : (k === 'stars' ? '' : '%'));
        });
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
            if (['gradX','gradY','gradSpread','patternOp','ornScale','ornY','ringW','ribbonY','ribbonW','arcR','arcSize'].indexOf(k) >= 0) { output(k, v, '%'); }
            if (k === 'gradAngle') { output(k, v, '°'); }
            if (k === 'stars') { output(k, v, ''); }
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
            selected = 'i:' + (state.images.length - 1);
            sync();
        });
    });

    var resetText = document.getElementById('bde-reset-text');
    if (resetText) {
        resetText.addEventListener('click', function () {
            if (typeof selected !== 'string' || selected.indexOf('t:') !== 0) { return; }
            if (state.pos) { delete state.pos[selected.slice(2)]; }
            sync();
        });
    }

    var remove = document.getElementById('bde-remove');
    if (remove) {
        remove.addEventListener('click', function () {
            var i = imgIndex();
            if (i >= 0) { state.images.splice(i, 1); selected = null; sync(); }
        });
    }

    [['f-imgFlip', 'flip'], ['f-imgGray', 'gray']].forEach(function (pair) {
        var el = document.getElementById(pair[0]);
        if (!el) { return; }
        el.addEventListener('change', function () {
            var i = imgIndex();
            if (i < 0) { return; }
            state.images[i][pair[1]] = el.checked;
            sync();
        });
    });

    ['imgW', 'imgRot', 'imgOp'].forEach(function (id) {
        var el = document.getElementById('f-' + id);
        if (!el) { return; }
        el.addEventListener('input', function () {
            var i = imgIndex();
            if (i < 0) { return; }
            var img = state.images[i];
            var v = parseFloat(el.value);
            if (id === 'imgW')   { img.w = v / 100; output('imgW', v, '%'); }
            if (id === 'imgRot') { img.rot = v; output('imgRot', v, '°'); }
            if (id === 'imgOp')  { img.op = v / 100; output('imgOp', v, '%'); }
            sync();
        });
    });

    /** Índice de la imagen seleccionada, o -1 si lo seleccionado es un texto. */
    function imgIndex() {
        return (typeof selected === 'string' && selected.indexOf('i:') === 0) ? parseInt(selected.slice(2), 10) : -1;
    }

    function refreshSelected() {
        var box = document.getElementById('bde-selected');
        var cnt = document.getElementById('bde-count');
        var rst = document.getElementById('bde-reset-text');
        if (cnt) { cnt.textContent = '(' + state.images.length + '/' + MAX + ')'; }
        if (rst) { rst.hidden = !(typeof selected === 'string' && selected.indexOf('t:') === 0); }
        if (!box) { return; }
        var sel = imgIndex();
        if (sel < 0 || !state.images[sel]) {
            box.hidden = true;
            return;
        }
        var img = state.images[sel];
        box.hidden = false;
        document.getElementById('f-imgW').value   = Math.round(img.w * 100);
        document.getElementById('f-imgRot').value = img.rot || 0;
        document.getElementById('f-imgOp').value  = Math.round((img.op == null ? 1 : img.op) * 100);
        output('imgW', Math.round(img.w * 100), '%');
        output('imgRot', img.rot || 0, '°');
        output('imgOp', Math.round((img.op == null ? 1 : img.op) * 100), '%');
        var fl = document.getElementById('f-imgFlip');
        var gr = document.getElementById('f-imgGray');
        if (fl) { fl.checked = !!img.flip; }
        if (gr) { gr.checked = !!img.gray; }
    }

    // ---- manijas de las capas ----
    function drawHandles() {
        handles.innerHTML = '';
        var side = stage.clientWidth;

        state.images.forEach(function (img, i) {
            var w = img.w * side;
            var el = document.createElement('div');
            el.className = 'bde-handle' + (selected === 'i:' + i ? ' is-sel' : '');
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
            el.addEventListener('focus', function () { selected = 'i:' + i; refreshSelected(); drawHandles(); });
            handles.appendChild(el);
        });

        // Los textos se dibujan al final para quedar por encima: con imágenes
        // agregadas, sus manijas tapaban las de texto y no había forma de
        // agarrarlas. El ancho es ajustado para pisar lo menos posible.
        Object.keys(layout).forEach(function (key) {
            var b  = layout[key];
            var h  = Math.max(20, b.h * side * 1.35);
            var w  = side * 0.46;
            var el = document.createElement('div');
            el.className = 'bde-handle bde-text' + (selected === 't:' + key ? ' is-sel' : '');
            el.style.left   = (b.x * side - w / 2) + 'px';
            el.style.top    = (b.y * side - h * 0.78) + 'px';
            el.style.width  = w + 'px';
            el.style.height = h + 'px';
            el.tabIndex = 0;
            el.setAttribute('role', 'button');
            el.setAttribute('aria-label', 'Mover el texto: ' + key);
            el.addEventListener('pointerdown', function (ev) { startText(ev, key); });
            el.addEventListener('focus', function () { selected = 't:' + key; refreshSelected(); drawHandles(); });
            handles.appendChild(el);
        });
    }

    /** Arrastra un bloque de texto: fija su posición en la receta. */
    function startText(ev, key) {
        ev.preventDefault();
        selected = 't:' + key;
        refreshSelected();
        drawHandles();

        var side = stage.clientWidth;
        var rect = stage.getBoundingClientRect();
        var base = state.pos[key] || { x: layout[key].x, y: layout[key].y };
        var offX = (ev.clientX - rect.left) / side - base.x;
        var offY = (ev.clientY - rect.top) / side - base.y;

        function move(e) {
            state.pos[key] = {
                x: Math.max(0, Math.min(1, (e.clientX - rect.left) / side - offX)),
                y: Math.max(0, Math.min(1, (e.clientY - rect.top) / side - offY))
            };
            layout[key].x = state.pos[key].x;
            layout[key].y = state.pos[key].y;
            drawHandles();
            queue();
        }
        function up() {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            sync();
        }
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    }

    function start(ev, index, resizing) {
        ev.preventDefault();
        selected = 'i:' + index;
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
        if (selected === null) { return; }
        if (document.activeElement && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) { return; }
        var step = e.shiftKey ? 0.05 : 0.01;
        var map  = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        var i    = imgIndex();

        if (map[e.key]) {
            e.preventDefault();
            if (i >= 0) {
                var img = state.images[i];
                img.x = Math.max(0, Math.min(1, img.x + map[e.key][0]));
                img.y = Math.max(0, Math.min(1, img.y + map[e.key][1]));
            } else {
                var key = selected.slice(2);
                        var cur = state.pos[key] || { x: layout[key].x, y: layout[key].y };
                state.pos[key] = {
                    x: Math.max(0, Math.min(1, cur.x + map[e.key][0])),
                    y: Math.max(0, Math.min(1, cur.y + map[e.key][1]))
                };
                layout[key] = Object.assign({}, layout[key], state.pos[key]);
            }
            drawHandles();
            sync();
        } else if ((e.key === 'Delete' || e.key === 'Backspace') && i >= 0) {
            e.preventDefault();
            state.images.splice(i, 1);
            selected = null;
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
                    var root = canvas.querySelector('svg');
                    try { layout = JSON.parse(root.getAttribute('data-layout') || '{}'); }
                    catch (err) { layout = {}; }
                    drawHandles();
                })
                .catch(function () { /* una vista previa fallida no rompe la edición */ });
        }, 220);
    }

    window.addEventListener('resize', drawHandles);

    hydrate();
    sync();
})();
