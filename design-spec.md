# HexBadge — Especificación de diseño

Sistema de diseño de la plataforma de credenciales HexBadge (SecureHex).
Dirección: **claro, institucional y confiable** — referencia Credly / Accredible.
La página pública de verificación la ven empleadores, así que el registro
emocional es **credibilidad y oficialidad**, no "tech llamativo".

Implementación: un único archivo CSS (`apps/{admin,earner}/public/assets/css/app.css`,
sincronizados). PHP plano, sin frameworks JS.

---

## Marca

- **Nombre:** HexBadge (producto) · **SecureHex** (organización).
- **Motivo:** hexágono ("Hex") — marca reutilizable en `layout/hexmark.php`
  (hexágono anidado, `currentColor`). Aparece en sidebar, login, header público.
- El emisor de cada badge es **por template** (SecureHex, Cámara Chilena de IA, …);
  la marca del badge la lleva su imagen, no el chrome de la app.

## Tipografía

- **Public Sans** (auto-hospedada en `assets/fonts/`, pesos 400/500/600/700).
  Elegida por su registro "oficial" (es la fuente del design system del gobierno
  de EE.UU.). Fallback: `system-ui`.
- Escala: base **15px**, line-height 1.55. Títulos peso 700, `letter-spacing -.018em`.
  `h1` 1.6rem · `h2` 1.18rem · `h3` 1rem.

## Color (tokens en `:root`)

| Token | Valor | Uso |
|---|---|---|
| `--bg` | `#f4f6fb` | fondo de página |
| `--surface` | `#ffffff` | tarjetas, tablas, inputs |
| `--surface-2` | `#f7f9fc` | encabezados de tabla, hover sutil |
| `--border` | `#e4e9f2` | bordes |
| `--text` | `#0f1b2e` | texto principal (navy casi negro) |
| `--muted` | `#697587` | texto secundario |
| `--primary` | `#2456d6` | azul de confianza — acción primaria, enlaces |
| `--primary-soft` | `#eaf0fe` | fondos tintados, tags |
| `--nav-bg` | `#0e1726` | sidebar navy |
| `--success` | `#1a7f43` / soft `#e6f4ec` | estado aceptado/válido |
| `--warn` | `#a55a09` / soft `#fbeedd` | pendiente |
| `--error` | `#c12525` / soft `#fbe9e9` | revocado / errores |

Color **restringido**: un solo primario (azul). Los semánticos solo en estados.

## Forma y elevación

- Radios: tarjetas `14px`, inputs/botones `8px`, pills `999px`, modales/auth `18px`.
- Sombras sutiles en capas (`--shadow-xs` … `--shadow-lg`). Fondo claro ⇒ las
  tarjetas se separan con sombra suave + borde de 1px, no con color.
- Foco visible: anillo `0 0 0 3px rgba(36,86,214,.22)` en inputs y botones.

## Espaciado

- Escala base **4px** (0.25rem). Padding de contenido 1.75rem; gap de grillas 1–1.25rem.

## Layout

- **Panel admin:** shell de 2 columnas — *sidebar navy* (250px, marca + nav agrupada
  con íconos + estado activo) y área de contenido clara con *topbar* (avatar + usuario
  + salir). Responsive: bajo 860px el sidebar pasa a barra superior con scroll.
- **Portal público (earner):** header claro con marca hexagonal + nav; contenido
  centrado en `--maxw` 1120px.
- **Pantallas standalone** (login, instalador, verificación): tarjeta centrada sobre
  fondo con gradiente radial sutil del primario.

## Componentes (clases)

- **Botones:** `.btn` (+ `.btn-primary`, `.btn-danger`, `.btn-ghost`, `.btn-sm`,
  `.btn-block`). Estados hover/active/focus definidos.
- **Tarjetas:** `.card`; métricas `.cards` + `.card-value` + `.card-label` + `.card-delta`.
- **Tablas:** `.table` — encabezado en mayúsculas/muted, filas con hover, bordes suaves.
- **Pills de estado:** `.badge-status` + `.status-{accepted,pending,revoked,rejected}`
  con punto de color. Tags: `.tag`.
- **Alertas:** `.alert` + `.alert-{success,error}` con ícono.
- **Formularios:** label 600/muted, inputs con borde fuerte + foco con anillo.
- **Badges (wallet):** `.badge-grid` + `.badge-tile` (hover con elevación).
- **Verificación pública:** `.verify-page` (fondo) + `.verify-card` + `.badge-img`.

## Reglas UX aplicadas (hard rules)

- Jerarquía task-first: la acción primaria de cada pantalla es 1 botón `--primary`.
- Cobertura de estados: vacío ("aún no hay…"), error (alert), éxito (flash).
- Affordance: lo clickeable parece clickeable; acciones destructivas (revocar,
  desactivar 2FA) piden confirmación / contraseña.
- Consistencia: misma interacción ⇒ misma clase y ubicación.
- CRAP: contraste alto texto/fondo, repetición de tokens, alineación a grilla,
  proximidad por secciones.

## Pendientes / decisiones abiertas

- Modo oscuro del panel admin (hoy todo claro) — los tokens permiten agregarlo.
- Íconos: set propio inline (stroke) en el sidebar; se puede extender a otras vistas.
- Estados de carga: la app es server-rendered (sin spinners); si se agrega JS,
  definir skeleton/loading.
