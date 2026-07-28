// Badge template form: show only the block matching the selected diploma mode.
// Kept in a file (not inline) because the CSP is `script-src 'self'`.
(function () {
    function sync() {
        var sel = document.querySelector('input[name="cert_mode"]:checked');
        var v = sel ? sel.value : 'none';
        document.querySelectorAll('[data-cert-mode]').forEach(function (el) {
            el.style.display = el.getAttribute('data-cert-mode') === v ? '' : 'none';
        });
    }
    document.querySelectorAll('input[name="cert_mode"]').forEach(function (r) {
        r.addEventListener('change', sync);
    });
    sync();
})();
