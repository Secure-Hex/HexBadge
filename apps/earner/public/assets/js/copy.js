// Copiar al portapapeles y seleccionar texto: data-copy="texto",
// data-copy-el="#selector" (lee su .value) y data-select (selecciona al clic).
//
// En archivo, no inline: la CSP de la app es `script-src 'self'`.

// navigator.clipboard solo existe en contexto seguro (HTTPS/localhost); se cae
// al execCommand clásico para que también funcione por HTTP.
function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        ok ? resolve() : reject();
    });
}

document.addEventListener('click', function (e) {
    var sel = e.target.closest('[data-select]');
    if (sel && typeof sel.select === 'function') { sel.select(); }

    var b = e.target.closest('[data-copy],[data-copy-el]');
    if (!b) return;
    var el = b.dataset.copyEl ? document.querySelector(b.dataset.copyEl) : null;
    var text = b.dataset.copy || (el ? el.value : '');
    if (!text) return;
    copyText(text).then(function () {
        var span = b.querySelector('span') || b, prev = span.textContent;
        span.textContent = '¡Copiado!';
        setTimeout(function () { span.textContent = prev; }, 1500);
    }).catch(function () {});
});
