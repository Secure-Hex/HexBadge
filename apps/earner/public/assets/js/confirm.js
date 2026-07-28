// Confirmación de acciones destructivas: data-confirm="mensaje" en el form o
// en el botón que lo envía.
//
// Reemplaza a onsubmit="return confirm(...)", que la CSP de la app
// (script-src 'self') bloquea sin avisar: los formularios se enviaban directo
// y la confirmación nunca aparecía.
document.addEventListener('submit', function (e) {
    var el = (e.submitter && e.submitter.closest('[data-confirm]'))
        || (e.target.matches('[data-confirm]') ? e.target : e.target.querySelector('[data-confirm]'));
    if (el && !window.confirm(el.dataset.confirm)) {
        e.preventDefault();
    }
});
