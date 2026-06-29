/* Herramienta de marcado de certificados con vista previa en vivo (vanilla).
   Coordenadas guardadas en fracciones 0–1 del ancho/alto de la plantilla.
   Cada caja de texto muestra un valor de muestra renderizado con la tipografía,
   color, alineación y tamaño (= tamaño de la caja) elegidos, tal como saldrá. */
(function () {
  "use strict";
  var form  = document.getElementById("cf-form");
  var stage = document.getElementById("cf-stage");
  var img   = document.getElementById("cf-img");
  var jsonInput = document.getElementById("cf-json");
  var qrSrc = document.getElementById("cf-qr-src");
  if (!form || !stage || !img) return;

  var TEXT = ["name", "course", "date", "cert_id"];
  var LABELS = { name: "Nombre", course: "Curso", date: "Fecha", cert_id: "ID", qr: "QR" };
  var firstFont = (function () { var s = form.querySelector(".cf-font"); return s ? parseInt(s.value, 10) : 0; })();

  var samples = {};
  try { samples = JSON.parse(form.dataset.samples || "{}"); } catch (e) { samples = {}; }
  var qrHtml = qrSrc ? qrSrc.innerHTML : "";

  var defaults = {
    name:    { x: 0.25, y: 0.30, w: 0.50, h: 0.10, align: "center", color: "#1a2233", font: firstFont },
    course:  { x: 0.20, y: 0.46, w: 0.60, h: 0.05, align: "center", color: "#1565d8", font: firstFont },
    date:    { x: 0.30, y: 0.58, w: 0.40, h: 0.035, align: "center", color: "#697587", font: firstFont, format: "long_es" },
    cert_id: { x: 0.25, y: 0.90, w: 0.50, h: 0.022, align: "center", color: "#697587", font: firstFont },
    qr:      { x: 0.82, y: 0.72, size: 0.12 }
  };

  var state = {};
  try { state = JSON.parse(form.dataset.config || "{}"); } catch (e) { state = {}; }
  ["name", "date", "cert_id", "qr"].forEach(function (f) { if (!state[f]) state[f] = Object.assign({}, defaults[f]); });

  function W() { return stage.clientWidth; }
  function H() { return stage.clientHeight; }
  var boxes = {};

  function sampleText(field) {
    if (field === "date") {
      var fmt = (state.date && state.date.format) || "long_es";
      return (samples.date && samples.date[fmt]) || "26 de junio de 2026";
    }
    return samples[field] || "";
  }

  function makeBox(field) {
    var el = document.createElement("div");
    el.className = "cf-box" + (field === "qr" ? " qr" : "");
    el.dataset.field = field;
    if (field === "qr") {
      el.innerHTML = '<span class="cf-label">' + LABELS[field] + '</span>' + qrHtml + '<span class="cf-handle"></span>';
    } else {
      el.innerHTML = '<span class="cf-label">' + LABELS[field] + '</span><span class="cf-text"></span><span class="cf-handle"></span>';
    }
    stage.appendChild(el);
    boxes[field] = el;
    enableDrag(field, el);
    return el;
  }

  function renderBox(field) {
    var s = state[field];
    if (!s) { if (boxes[field]) { boxes[field].remove(); delete boxes[field]; } return; }
    var el = boxes[field] || makeBox(field);
    var w, h;
    if (field === "qr") { w = s.size * W(); h = w; }
    else { w = s.w * W(); h = s.h * H(); }
    el.style.left = (s.x * W()) + "px";
    el.style.top = (s.y * H()) + "px";
    el.style.width = w + "px";
    el.style.height = h + "px";
    if (field !== "qr") styleText(field);
  }

  // Aplica tipografía/color/alineación + auto-ajusta el tamaño al box.
  function styleText(field) {
    var el = boxes[field]; if (!el) return;
    var span = el.querySelector(".cf-text"); if (!span) return;
    var s = state[field];
    span.textContent = sampleText(field);
    span.style.fontFamily = s.font ? "'cf-font-" + s.font + "'" : "inherit";
    span.style.color = s.color || "#1a2233";
    span.style.textAlign = s.align || "center";
    fitText(el, span);
  }

  function fitText(el, span) {
    var inner = el.clientHeight;
    if (inner < 4) return;
    span.style.fontSize = inner + "px";
    var avail = el.clientWidth;
    if (span.scrollWidth > avail && avail > 0) {
      span.style.fontSize = Math.max(6, inner * (avail / span.scrollWidth)) + "px";
    }
  }

  function renderAll() { ["name", "course", "date", "cert_id", "qr"].forEach(renderBox); writeJson(); }
  function reflowBoxes() { ["name", "course", "date", "cert_id", "qr"].forEach(renderBox); }
  function writeJson() { jsonInput.value = JSON.stringify(state); }
  function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

  function enableDrag(field, el) {
    function start(e, mode) {
      e.preventDefault();
      var sx = (e.touches ? e.touches[0].clientX : e.clientX);
      var sy = (e.touches ? e.touches[0].clientY : e.clientY);
      var ox = parseFloat(el.style.left), oy = parseFloat(el.style.top);
      var ow = parseFloat(el.style.width), oh = parseFloat(el.style.height);
      function move(ev) {
        var cx = (ev.touches ? ev.touches[0].clientX : ev.clientX);
        var cy = (ev.touches ? ev.touches[0].clientY : ev.clientY);
        var dx = cx - sx, dy = cy - sy;
        if (mode === "move") {
          el.style.left = clamp(ox + dx, 0, W() - ow) + "px";
          el.style.top = clamp(oy + dy, 0, H() - oh) + "px";
        } else {
          var nw = clamp(ow + dx, 16, W() - parseFloat(el.style.left));
          var nh = (field === "qr") ? nw : clamp(oh + dy, 8, H() - parseFloat(el.style.top));
          el.style.width = nw + "px"; el.style.height = nh + "px";
          if (field !== "qr") { var sp = el.querySelector(".cf-text"); if (sp) fitText(el, sp); }
        }
        sync(field, el);
      }
      function up() {
        document.removeEventListener("mousemove", move); document.removeEventListener("mouseup", up);
        document.removeEventListener("touchmove", move); document.removeEventListener("touchend", up);
      }
      document.addEventListener("mousemove", move); document.addEventListener("mouseup", up);
      document.addEventListener("touchmove", move, { passive: false }); document.addEventListener("touchend", up);
    }
    el.addEventListener("mousedown", function (e) { if (!e.target.classList.contains("cf-handle")) start(e, "move"); });
    el.addEventListener("touchstart", function (e) { if (!e.target.classList.contains("cf-handle")) start(e, "move"); }, { passive: false });
    var handle = el.querySelector(".cf-handle");
    handle.addEventListener("mousedown", function (e) { e.stopPropagation(); start(e, "resize"); });
    handle.addEventListener("touchstart", function (e) { e.stopPropagation(); start(e, "resize"); }, { passive: false });
  }

  function sync(field, el) {
    var s = state[field];
    s.x = parseFloat(el.style.left) / W();
    s.y = parseFloat(el.style.top) / H();
    if (field === "qr") { s.size = parseFloat(el.style.width) / W(); }
    else { s.w = parseFloat(el.style.width) / W(); s.h = parseFloat(el.style.height) / H(); }
    writeJson();
  }

  // --- Sincronizar controles del panel con el estado ---
  function setSelect(sel, val) { if (sel) sel.value = val; }
  TEXT.forEach(function (f) {
    var row = form.querySelector('.cf-row[data-field="' + f + '"]');
    if (!row) return;
    var s = state[f];
    var enable = row.querySelector(".cf-enable");
    if (s) {
      setSelect(row.querySelector(".cf-font"), s.font);
      setSelect(row.querySelector(".cf-align"), s.align);
      var color = row.querySelector(".cf-color"); if (color) color.value = s.color || "#1a2233";
      var fmt = row.querySelector(".cf-format"); if (fmt && s.format) fmt.value = s.format;
      if (enable) enable.checked = true;
    } else if (enable) {
      enable.checked = false; row.classList.add("off");
    }
  });

  form.querySelectorAll(".cf-font").forEach(function (s) { s.addEventListener("change", function () { var f = s.dataset.field; if (state[f]) { state[f].font = parseInt(s.value, 10); styleText(f); writeJson(); } }); });
  form.querySelectorAll(".cf-align").forEach(function (s) { s.addEventListener("change", function () { var f = s.dataset.field; if (state[f]) { state[f].align = s.value; styleText(f); writeJson(); } }); });
  form.querySelectorAll(".cf-color").forEach(function (s) { s.addEventListener("input", function () { var f = s.dataset.field; if (state[f]) { state[f].color = s.value; styleText(f); writeJson(); } }); });
  form.querySelectorAll(".cf-format").forEach(function (s) { s.addEventListener("change", function () { if (state.date) { state.date.format = s.value; styleText("date"); writeJson(); } }); });

  form.querySelectorAll(".cf-enable").forEach(function (chk) {
    chk.addEventListener("change", function () {
      var f = chk.dataset.field;
      var row = form.querySelector('.cf-row[data-field="' + f + '"]');
      if (chk.checked) {
        state[f] = Object.assign({}, defaults[f]);
        row.classList.remove("off");
        var color = row.querySelector(".cf-color");
        if (color) state[f].color = color.value;
        var fontSel = row.querySelector(".cf-font");
        if (fontSel) state[f].font = parseInt(fontSel.value, 10);
        renderBox(f);
        writeJson();
      } else {
        delete state[f];
        if (boxes[f]) { boxes[f].remove(); delete boxes[f]; }
        row.classList.add("off");
        writeJson();
      }
    });
  });

  function init() { renderAll(); }
  if (img.complete) init(); else img.addEventListener("load", init);
  window.addEventListener("resize", reflowBoxes);
})();
