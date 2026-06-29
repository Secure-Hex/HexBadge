<?php
/** @var string $appName */
use HexBadge\Core\View;
$hex  = static fn (): string => View::renderPartial('layout/hexmark');
$logo = static fn (): string => View::renderPartial('layout/securelogo');
?>
<div class="landing">

  <!-- ===== Hero ===== -->
  <section class="hero full-bleed">
    <div class="wrap hero-inner">
      <div>
        <span class="hero-eyebrow"><span class="brand-mark" style="width:16px;height:16px"><?= $logo() ?></span> Credenciales verificables</span>
        <h1>Tus logros, en una <span class="grad">credencial que se verifica</span> en segundos.</h1>
        <p class="lead">Aceptá los badges que ganaste, sumalos a tu perfil de LinkedIn y compartí un enlace que cualquiera puede verificar — sin exponer tus datos.</p>
        <div class="hero-cta">
          <a class="btn btn-primary" href="/login">Ingresar</a>
          <a class="btn" href="#como-funciona">¿Cómo funciona?</a>
        </div>
        <p class="hero-note">¿Recibiste un email con un badge? Usá el enlace del correo o <a href="/login">iniciá sesión</a>.</p>
      </div>
      <div class="hero-art" aria-hidden="true">
        <span class="hero-hex h1" style="width:70px;height:70px"><?= $hex() ?></span>
        <span class="hero-hex h2"><?= $hex() ?></span>
        <span class="hero-hex h3"><?= $hex() ?></span>
        <span class="hero-hex h4"><?= $hex() ?></span>
        <div class="hero-badge"><span class="brand-mark"><?= $logo() ?></span></div>
      </div>
    </div>
  </section>

  <!-- ===== Qué podés hacer ===== -->
  <section class="lsection">
    <div class="wrap">
      <div class="lhead reveal">
        <div class="eyebrow">Tu wallet de credenciales</div>
        <h2>Todo lo que ganaste, en un solo lugar</h2>
        <p>Una cuenta, todos tus badges. Aceptalos, organizalos y compartilos cuando quieras.</p>
      </div>
      <div class="feature-grid">
        <div class="feature reveal d1">
          <div class="fico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>
          <h3>Aceptá tus badges</h3>
          <p>Te llega un email, hacés clic y el badge queda guardado en tu wallet personal.</p>
        </div>
        <div class="feature reveal d2">
          <div class="fico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg></div>
          <h3>Compartí donde quieras</h3>
          <p>Un clic para agregarlo a tu perfil de LinkedIn, o enviá el enlace por donde prefieras.</p>
        </div>
        <div class="feature reveal d3">
          <div class="fico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.5 8 8 11 4.5-3 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></div>
          <h3>Verificación pública</h3>
          <p>Cualquiera confirma que tu credencial es auténtica, sin que tengas que dar datos privados.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== Cómo funciona ===== -->
  <section class="lsection alt" id="como-funciona">
    <div class="wrap">
      <div class="lhead reveal">
        <div class="eyebrow">En 3 pasos</div>
        <h2>Cómo funciona</h2>
        <p>Reclamar un badge te toma menos de un minuto.</p>
      </div>
      <div class="steps">
        <div class="step reveal d1"><h3>Recibís tu badge</h3><p>Cuando una organización te emite una credencial, te llega un email con el enlace para aceptarla.</p></div>
        <div class="step reveal d2"><h3>Iniciás sesión</h3><p>Entrás con tu cuenta o la creás en el momento. Si querés, activás verificación en dos pasos.</p></div>
        <div class="step reveal d3"><h3>Lo aceptás y listo</h3><p>El badge queda en tu wallet, listo para compartir y verificar en cualquier momento.</p></div>
      </div>
    </div>
  </section>

  <!-- ===== CTA final ===== -->
  <section class="lcta full-bleed reveal">
    <h2>¿Tenés un badge esperándote?</h2>
    <p>Ingresá a tu wallet y reclamalo.</p>
    <a class="btn btn-primary" href="/login" style="padding:.75rem 1.6rem;font-size:1rem">Ingresar a mi wallet</a>
  </section>
</div>

<script src="/assets/js/landing.js"></script>
