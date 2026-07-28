# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Tres audiencias, con jobs distintos:

1. **Quien verifica** (reclutador, cliente, coordinador académico). Llega a
   `/verify/{uuid}` desde un CV, un correo o LinkedIn, casi siempre sin cuenta y
   sin intención de crearse una. Su job: decidir en segundos si la credencial que
   alguien dice tener es real, quién la emitió y qué acredita. Es tráfico de una
   sola visita.
2. **Quien recibe la credencial** (earner). Recibe un correo, acepta o rechaza, y
   después usa su perfil público como vitrina de logros y lo comparte.
3. **Quien emite** (empresa/organización): administra templates, emite individual
   o por CSV, revoca, audita. Es el cliente que paga.

## Product Purpose

HexBadge emite y verifica credenciales digitales (Open Badges): insignias y
diplomas que una organización otorga y que cualquier tercero puede comprobar
contra el registro del emisor. Éxito = un verificador resuelve su duda sin
fricción y una persona puede probar su logro sin adjuntar un PDF.

## Positioning

La credencial no es una imagen: es una afirmación verificable contra el emisor,
con URL pública permanente, JSON de Open Badges y estado vivo (válida, revocada,
expirada). Un PDF o una imagen en LinkedIn no pueden probar lo mismo.

## Operating Context

- La verificación pública se abre desde enlaces externos, a menudo en móvil, sin
  sesión y sin contexto previo.
- Los badges los diseña cada emisor: llegan en proporciones y estilos muy
  distintos (circulares, hexagonales, rectangulares, oscuros o claros). El diseño
  no puede asumir una forma ni un fondo.
- Instalación autoalojada (cPanel/FTP) con PHP + MySQL, sin build de assets.

## Capabilities and Constraints

- Estados de una credencial emitida: `pending`, `accepted`, `rejected`,
  `revoked`; más expiración por fecha. La revocación es visible públicamente.
- Cada credencial expone: imagen, nombre, descripción, criterios de obtención,
  competencias (tags), emisor, fecha de emisión, fecha de expiración, UUID de
  verificación, JSON Open Badge y, opcionalmente, un diploma en PDF.
- Emisión individual, por CSV (hasta 2.000 filas) y por API. Multiempresa con
  aislamiento por empresa.
- CSP estricta: `script-src 'self'`. Sin JS inline, sin CDNs, sin fuentes ni
  recursos externos. Todo asset se sirve desde el propio dominio.
- Sin pipeline de build: CSS y JS son archivos servidos tal cual.
- Vistas PHP server-rendered; el portal público debe funcionar sin JavaScript.

## Brand Commitments

- Nombre **HexBadge**, herramienta de **SecureHex** (securehex.cl). Marca
  hexagonal con candado.
- Tipografía Public Sans autoalojada; azul SecureHex `#1565d8` como primario.
- Interfaz en español rioplatense/chileno (voseo), tono profesional y directo.

## Evidence on Hand

- Credenciales reales en la base de desarrollo, con emisores reales (SecureHex,
  Cámara Chilena de IA) e imágenes de badge reales.
- No hay testimonios, métricas de uso, logos de clientes ni casos de estudio.
  No deben inventarse.

## Product Principles

1. **El veredicto antes que el adorno.** Quien abre una verificación vino a
   preguntar si algo es cierto; la respuesta va primera e inequívoca.
2. **La prueba es el producto.** Emisor, fecha, estado y cadena de verificación
   son contenido de primera clase, no metadatos al pie.
3. **La imagen del badge es dato ajeno.** El diseño la enmarca; nunca asume su
   forma, color ni fondo.
4. **Sin dependencias externas.** La CSP y el autoalojamiento son parte del
   producto, no un obstáculo a rodear.
5. **Funciona sin sesión y sin JavaScript.** El público llega de un enlace.

## Accessibility & Inclusion

Español como idioma de interfaz. Contraste mínimo AA (4.5:1) en texto, foco
visible en todo control, contenido dinámico anunciado, y ninguna información
transmitida solo por color: el estado de una credencial siempre se dice con
palabras.
