/* Revelado al hacer scroll para el index del portal earner.
   Sin dependencias; respeta prefers-reduced-motion vía CSS. */
(function () {
  "use strict";
  var els = document.querySelectorAll(".reveal");
  if (!("IntersectionObserver" in window)) {
    els.forEach(function (e) { e.classList.add("in"); });
    return;
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (en.isIntersecting) {
        en.target.classList.add("in");
        io.unobserve(en.target);
      }
    });
  }, { threshold: 0.12, rootMargin: "0px 0px -8% 0px" });
  els.forEach(function (e) { io.observe(e); });

  // Scroll suave para anclas internas.
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener("click", function (ev) {
      var t = document.querySelector(a.getAttribute("href"));
      if (t) { ev.preventDefault(); t.scrollIntoView({ behavior: "smooth" }); }
    });
  });
})();
