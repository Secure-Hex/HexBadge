// Previsualización instantánea de la foto de perfil y de portada al elegir el
// archivo, antes de guardar. Usa data: URL (FileReader) porque la CSP de
// img-src permite 'self' y data:, pero no blob:.
(function () {
    'use strict';

    function onPick(inputId, apply) {
        var input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file || file.type.indexOf('image/') !== 0) return;
            var reader = new FileReader();
            reader.onload = function () { apply(reader.result); };
            reader.readAsDataURL(file);
        });
    }

    onPick('avatar', function (url) {
        var box = document.querySelector('.pf-preview-avatar');
        if (!box) return;
        var img = box.querySelector('img');
        if (!img) {                 // aún muestra la inicial: reemplazar por <img>
            box.innerHTML = '';
            img = document.createElement('img');
            img.alt = '';
            box.appendChild(img);
        }
        img.src = url;
    });

    onPick('cover', function (url) {
        var box = document.querySelector('.pf-preview-cover');
        if (box) box.style.backgroundImage = "url('" + url + "')";
    });
})();
