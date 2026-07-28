// Formulario de acreditación: muestra el bloque que corresponde al modo elegido
// (imagen subida o diseñada, y tipo de diploma), y avisa antes de cambiar una
// imagen que ya salió emitida.
//
// En archivo y no inline porque la CSP es `script-src 'self'`.
(function () {
    'use strict';

    /** Muestra solo el bloque [data-<attr>] que coincide con el radio marcado. */
    function toggle(radioName, attr, fallback) {
        var radios = document.querySelectorAll('input[name="' + radioName + '"]');
        if (radios.length === 0) {
            return;
        }
        function sync() {
            var sel = document.querySelector('input[name="' + radioName + '"]:checked');
            var v   = sel ? sel.value : fallback;
            document.querySelectorAll('[' + attr + ']').forEach(function (el) {
                el.style.display = el.getAttribute(attr) === v ? '' : 'none';
            });
        }
        radios.forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    }

    toggle('cert_mode', 'data-cert-mode', 'none');
    toggle('image_mode', 'data-image-mode', 'upload');

    // Aviso solo si la imagen cambió de verdad: si no, editar la descripción de
    // una acreditación con credenciales emitidas pediría una confirmación que
    // no viene al caso, y a la tercera vez nadie la lee.
    var submit = document.querySelector('button[data-confirm-image]');
    if (!submit) {
        return;
    }
    var message = submit.getAttribute('data-confirm-image');
    var file    = document.getElementById('image');
    var recipe  = document.getElementById('bd-recipe');
    var initialRecipe = recipe ? recipe.value : '';
    var initialMode   = (document.querySelector('input[name="image_mode"]:checked') || {}).value;

    function refresh() {
        var mode    = (document.querySelector('input[name="image_mode"]:checked') || {}).value;
        var changed = (mode !== initialMode)
            || (file && file.files && file.files.length > 0)
            || (recipe && recipe.value !== initialRecipe);

        if (changed) {
            submit.setAttribute('data-confirm', message);
        } else {
            submit.removeAttribute('data-confirm');
        }
    }

    document.addEventListener('change', refresh);
    document.addEventListener('input', refresh);
    refresh();
})();
