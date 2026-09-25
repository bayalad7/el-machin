# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Order-taking web app for **Mariscos El Machín**, a Mexican seafood restaurant. Customers build seafood orders, add them to a cart, and submit via WhatsApp. A single admin edits the menu and reviews orders from a web panel. No database: the menu is a JSON file and each order is saved as a `.json` file on the server.

Production: Hostinger VPS with PHP at `https://tedyc.com/el-machin/app-pedido/`. Repo: `https://github.com/bayalad7/el-machin`. Setup and admin usage: [docs/ADMIN.md](docs/ADMIN.md).

## Running the App

No build step, no npm. Requires PHP (the app fetches the menu, so opening `index.html` from disk does not work):

```
php -S localhost:8000
```

Then open `http://localhost:8000/` (app) and `http://localhost:8000/admin/` (panel). Create a local admin password with `php api/crear-password.php` (writes git-ignored `api/config.local.php`).

## Architecture

| Path | Role |
|---|---|
| `index.html` | Customer app. All HTML/CSS/JS inline. Loads menu from `api/menu.php` → fallback `menu-default.json` → fallback last menu in `localStorage`. |
| `menu-default.json` | Seed menu, versioned. Same schema the admin edits. |
| `admin/index.html` | Admin panel (single file). Generic editable tables driven by the `SECCIONES` schema. |
| `api/comun.php` | Shared helpers: atomic JSON writes, `menu_vigente()`, server-side pricing (`precio_unitario`, mirrors JS `buscarPrecio`), folio counter with `flock`, per-IP rate limit. |
| `api/menu.php` | `GET` current menu. |
| `api/pedido.php` | `POST` order → recomputes total, assigns folio `MACH-AAAAMMDD-###`, writes `datos/pedidos/AAAA-MM/FOLIO.json`. |
| `api/admin.php` | Admin API (PHP session + `X-CSRF` header): login, menu save with validation + auto-backup, orders list/status, backups/restore, client catalog, delivery tariff, per-order delivery (`envio_pedido` sets `envio` + `total_final`). |
| `datos/` | Runtime data (live `menu.json`, `respaldos/`, `pedidos/`, `clientes.json`, `envio.json`, `limites/`). Git-ignored except `.htaccess` (denies web access on Apache). |

### Clients and delivery

- `api/pedido.php` upserts the client in `datos/clientes.json` (key = last 10 phone digits) via `registrar_cliente_de_pedido()`; a new address becomes another `domicilios[]` entry unless the normalized text matches or GPS is < 60 m from a saved one. Client registration failures never block saving the order (`con_clientes()` throws instead of calling `error_api()`).
- Delivery tariff (`envio_config()`): base price up to `base_km` + `precio_km_extra` per started extra km, separate `normal`/`lluvia` tables. JS `tarifaEnvio()` in `admin/index.html` mirrors PHP `calcular_tarifa()`. Km estimate = haversine from `restaurante` × `factor_calles`.
- Courier message follows the provider's template (`mensajeRepartidor()`); cash: courier pays food and collects food + delivery; transfer: client pays us food + delivery, we pay the courier, so courier pays 0 and collects 0. Customer picks `formaPago` in the app (stored as `forma_pago`).
- The customer app only remembers recent clients/addresses in `localStorage` (`machin_clientes_recientes`); the server catalog is never exposed publicly.

External dependencies are CDN-only: Leaflet 1.9.4 + OpenStreetMap tiles (delivery map), Google Fonts (Fredoka One, Nunito).

### Menu schema (`menu-default.json` / `datos/menu.json`)

Top-level arrays of flat rows (so they map 1:1 to admin tables): `productos`, `precios`, `mariscos`, `sabores`, `niveles`, `tamanos`, `micheladas`, `extras`, `operadores`, `grupos`, `config` (`clave`/`valor`). Booleans are the strings `"si"`/`"no"`.

Product behavior comes from row fields, **never from the product name**:
- `flujo`: `marisco` (pick seafood/size/spice), `michelada` (beer options from `micheladas`), `simple` (quantity only).
- `cobro`: `tamano` (kg/lt/medio columns) or `pieza`.
- `sabores`: flavor group (e.g. `aguachile`) or empty; `tostitos`: `si` asks tostitos flavor; `picor`: `obligatorio`/`opcional`/`no`; `mariscos`: seafood group; `ancho`/`color`: card layout.

Price rule (JS `buscarPrecio` in `index.html` and PHP `buscar_precio` in `api/comun.php` must stay identical): row in `precios` with same `producto`, `marisco` = `Mixto` when 2+ seafoods else the chosen one, `sabor` equal (preferred) or empty; column = size key or `pieza`. Michelada price = `precio` of the chosen `tipo` row.

### Customer flow (`index.html`)

1. **Armar pedido** — product cards rendered from `PRODUCTOS`; sections shown via `flags(prod)`.
2. **Carrito** — items + extras grouped by `seccion`.
3. **Checkout** — operator, customer, group, date/time (from `config`), pickup/delivery with GPS map → `sendWhatsApp()` first POSTs to `api/pedido.php` (4 s timeout; on failure queues in `localStorage` and retries on next load), adds the folio to the message, then opens `wa.me`. On desktop the tab is pre-opened inside the click to avoid popup blocking.

## Key Conventions

- All user-visible text, identifiers for domain concepts, and commit messages are in **Spanish** (commit style: `caracteristica(ambito): asunto`, `refactorizacion(...)`).
- The drink is **Micheladas/Michelada** (`MICHELADAS_*`, `michelada*`), never "Michelas".
- Admin-controlled strings are HTML-escaped with `esc()` and passed to handlers via `data-*` attributes, not inlined into `onclick` JS strings.
- CSS custom properties on `:root` control the palette — verde, amarillo, naranja, rojo, crema, cafe, gris.
- Toasts and modals are implemented inline (no library).
