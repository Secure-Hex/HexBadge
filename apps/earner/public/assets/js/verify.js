// Confeti de celebración de la página pública de credenciales.
// En archivo, no inline: la CSP de la app es `script-src 'self'`.
// Canvas propio que se autodestruye al terminar, sin librerías. Se omite si la
// persona pidió menos movimiento en su sistema.
(function () {
    if (!document.body.dataset.celebrate) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:9999';
    document.body.appendChild(canvas);
    var ctx = canvas.getContext('2d'), w, h;
    function resize() { w = canvas.width = window.innerWidth; h = canvas.height = window.innerHeight; }
    resize();
    window.addEventListener('resize', resize);

    // Paleta del sistema: primario, su tono suave, éxito y advertencia.
    var colors = ['#1565d8', '#0e459b', '#7aa2ff', '#1a7f43', '#a55a09'];
    var pieces = [];
    for (var i = 0; i < 120; i++) {
        pieces.push({
            x: Math.random() * w,
            y: -20 - Math.random() * h * 0.5,
            vx: (Math.random() - 0.5) * 2,
            vy: 2 + Math.random() * 3,
            w: 6 + Math.random() * 5,
            h: 9 + Math.random() * 6,
            rot: Math.random() * Math.PI,
            vr: (Math.random() - 0.5) * 0.2,
            color: colors[i % colors.length]
        });
    }

    var stopAt = performance.now() + 4500;
    requestAnimationFrame(function frame(now) {
        ctx.clearRect(0, 0, w, h);
        for (var i = 0; i < pieces.length; i++) {
            var p = pieces[i];
            p.x += p.vx;
            p.y += p.vy;
            p.vy += 0.03;
            p.rot += p.vr;
            ctx.save();
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rot);
            ctx.fillStyle = p.color;
            ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
            ctx.restore();
        }
        if (now < stopAt) {
            requestAnimationFrame(frame);
        } else {
            window.removeEventListener('resize', resize);
            canvas.remove();
        }
    });
})();
