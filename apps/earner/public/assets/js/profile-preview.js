// Auto-guardado de la foto de perfil/portada: al elegir el archivo se envía su
// formulario de subida al instante, sin tocar "Guardar perfil" (que solo guarda
// los textos). El input vive en la tarjeta pero pertenece al form de subida vía
// el atributo form=. La página recarga mostrando ya la foto guardada.
(function () {
    'use strict';

    function autoUpload(inputId, formId) {
        var input = document.getElementById(inputId);
        var form  = document.getElementById(formId);
        if (!input || !form) return;
        input.addEventListener('change', function () {
            if (!input.files || !input.files[0]) return;
            var drop = document.querySelector('.file-drop[for="' + inputId + '"]');
            if (drop) {
                drop.classList.add('is-loading');
                var text = drop.querySelector('.file-drop-text');
                if (text) text.textContent = 'Subiendo…';
            }
            form.submit();
        });
    }

    autoUpload('avatar', 'upload-avatar');
    autoUpload('cover', 'upload-cover');
})();
