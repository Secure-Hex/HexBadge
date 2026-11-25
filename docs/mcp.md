# MCP server

HexBadge exposes a remote [Model Context Protocol](https://modelcontextprotocol.io) server so AI clients (Claude Code, Claude Desktop, any MCP client) can list, create and archive templates and issue, look up and revoke badges.

It exposes the API v1 operations plus two MCP-only template writes (`create_template`, `archive_template`, under the `templates:write` scope), all over the same layer as API v1: same API keys, same scopes, same rate limit (`RATE_LIMIT_API` per key per minute) and the same company isolation. Apart from those two template writes, anything a key cannot do over REST, it cannot do over MCP either.

## Connect

- **URL:** `https://<host>/mcp` (the admin host, the same one that serves `/api/v1`)
- **Transport:** Streamable HTTP, stateless, JSON responses only (no SSE, no sessions). Only `POST` is accepted; `GET` and `DELETE` answer 405.
- **Auth:** `Authorization: Bearer <api_key>` on every request. Create the key in the admin panel (API Keys) with the scopes you need. A missing or invalid key gets HTTP 401.

Claude Code:

```bash
claude mcp add --transport http hexbadge https://<host>/mcp --header "Authorization: Bearer <key>"
```

Other clients take the same two values: the URL and the `Authorization` header.

## Scopes and tools

`tools/list` only shows the tools the key's scopes allow. Calling a tool without the scope returns a tool error.

| Tool | Scope (any of) | REST equivalent | Notes |
|---|---|---|---|
| `list_templates` | `templates:read`, `badges:read` | `GET /api/v1/templates` | read-only |
| `get_template` | `templates:read`, `badges:read` | `GET /api/v1/templates/{uuid}` | read-only |
| `issue_badge` | `badges:issue` | `POST /api/v1/badges/issue` | `template_id`, `email`, `first_name`, `last_name`, `locale?` (`es`/`en`) |
| `bulk_issue_badges` | `bulk:issue` | `POST /api/v1/badges/bulk-issue` | `template_id`, `earners[]` (max 100) |
| `get_badge` | `badges:read` | `GET /api/v1/badges/{uuid}` | read-only |
| `revoke_badge` | `badges:issue` | `DELETE /api/v1/badges/{uuid}` | `uuid`, `reason?`; destructive |
| `list_earner_badges` | `badges:read` | `GET /api/v1/earners/{email}/badges` | read-only |
| `create_template` | `templates:write` | none (MCP only) | always `draft`; see below |
| `archive_template` | `templates:write` | none (MCP only) | `uuid`; destructive, idempotent |

Parameters use the same names and validation as the REST bodies. A successful call returns the same data as the REST `data` field, as `structuredContent` and as JSON text (lists come wrapped as `{"items": [...]}`).

`issue_badge` and `bulk_issue_badges` email each earner the acceptance link, the same email the admin panel sends. A mail failure never undoes the issuance. `notification_sent` (per earner in bulk) mirrors the badge's notification flag in the admin panel; when it is `false`, resend the email from there. The bulk limit is `ApiOperations::BULK_MAX` (100) earners per call.

## Template writes

`create_template` creates a template the same way the admin panel's form does (same field rules, same image storage, audit `template.created`), with these differences:

- **Always `draft`.** There is no `state` parameter; activate it from the admin panel before issuing badges from it.
- **No diploma/certificate** options, and no designed badge: the image is uploaded.
- **Company:** the template belongs to the API key owner's company. A global key (owner without a company) gets a tool error: the panel also requires a company for every template.
- **Fields:** `name` (≤200), `description` (≤5000), `criteria_text` (≤5000) are required; `criteria_url` (http/https), `expires_days` (integer 1-3650), `is_public` (boolean, default `true`) and `skills_tags` (array of strings; an empty tag or one containing a comma is a tool error) are optional.
- **Image** (required): `{"data": "<base64>", "mime_type": "image/png" | "image/jpeg" | "image/webp"}`.
  - `data` must be valid base64 (strict decode) and at most 5 MB decoded, 2048 px per side (`ApiOperations::IMAGE_MAX_EDGE`).
  - The real type is read from the bytes and must match `mime_type`. SVG is not accepted.
  - It is stored like a panel upload: random name, re-encoded to WebP and reduced to 512 px on the longest side.
  - The whole request body may be up to 8 MiB, enough for a 5 MB image in base64. Larger bodies get HTTP 413.

It returns the template like `get_template` (`id`, `state: "draft"`, ...).

`archive_template` does exactly what the panel's Archive button does: sets the template to `archived` (no new badges from it; issued badges are untouched) and audits `template.archived`. Archiving an already archived template is not an error: it returns the template unchanged (and audits again, like the panel). A template of another company answers the same `Template no encontrado` as an unknown UUID.

## Errors

Failed tool calls come back as a tool result with `isError: true` and a text message, never with the REST error `code`:

- **Business errors** (not found, validation, duplicate): the same message as the REST `error.message`.
- **Missing scope:** `Scope no autorizado` (REST answers 403 `INSUFFICIENT_SCOPE` with the same text).
- **Wrong or missing arguments:** an MCP-only message such as `Falta el parámetro email` or `Tipo inválido para uuid: se esperaba string`, checked against the tool's `inputSchema` before the operation runs.
- **Unexpected failure:** a generic `Error interno al ejecutar la herramienta`; the detail goes to the server log only.

Protocol problems are JSON-RPC errors instead: an unknown tool or a non-string `name` is `-32602`, an unknown method `-32601`, invalid JSON `-32700`.

**Rate limit:** past `RATE_LIMIT_API` requests per key per minute the server answers HTTP 429 with a JSON-RPC error (`-32000`, `Demasiadas solicitudes`, `id: null`). Every MCP message counts, including `initialize`, `tools/list` and `ping`, and the budget is shared with REST calls made with the same key. Back off and retry after the minute window.

## Browser origins

Requests that carry an `Origin` header are rejected with 403 unless the origin is allowed (DNS-rebinding protection). By default only `APP_URL` is allowed; set `MCP_ALLOWED_ORIGINS` (comma-separated) in `.env` to allow others. CLI and server clients send no `Origin` and are unaffected.
