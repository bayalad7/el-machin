# Administración de la app de pedidos — El Machín

La app funciona sin base de datos: el menú es un archivo JSON y cada pedido se guarda como un archivo `.json` en el servidor. Todo se administra desde el panel en:

**https://tedyc.com/el-machin/app-pedido/admin/**

---

## 1. Instalación en el VPS (una sola vez)

Requisitos: PHP 8.0 o más reciente con las extensiones `mbstring`, `iconv` y `json` (vienen activadas por defecto).

### 1.1 Subir la app

La forma recomendada es clonar el repositorio en la carpeta pública del sitio, para que las actualizaciones sean un `git pull`:

```bash
cd /ruta/publica/de/tedyc.com/el-machin
git clone https://github.com/bayalad7/el-machin.git app-pedido
cd app-pedido
```

Para actualizar después: `cd app-pedido && git pull`. Los pedidos, el menú editado y la contraseña **no** están en git, así que actualizar nunca los borra.

### 1.2 Dar permiso de escritura a `datos/`

PHP necesita escribir en `datos/` (menú editado, respaldos y pedidos). Averigua con qué usuario corre PHP (normalmente `www-data`) y dale la carpeta:

```bash
ps aux | grep -E "php-fpm|apache2|httpd|lsphp" | head -3   # la primera columna es el usuario
sudo chown -R www-data:www-data datos
sudo chmod -R 775 datos
```

### 1.3 Crear la contraseña del panel

```bash
php api/crear-password.php
```

Pide la contraseña dos veces (mínimo 8 caracteres) y la guarda cifrada en `api/config.local.php`. Para cambiarla, vuelve a ejecutar el mismo comando.

### 1.4 Bloquear la carpeta `datos/` al navegador

Los pedidos tienen nombres y teléfonos de clientes; **nadie debe poder abrirlos desde el navegador**. Averigua qué servidor web usa el VPS:

```bash
curl -sI https://tedyc.com/ | grep -i server
```

- **Apache o LiteSpeed:** no hay que hacer nada; el archivo `datos/.htaccess` ya bloquea la carpeta.
- **Nginx:** Nginx ignora `.htaccess`. Agrega esto dentro del bloque `server { ... }` de tedyc.com y recarga Nginx (`sudo nginx -t && sudo systemctl reload nginx`):

  ```nginx
  location ^~ /el-machin/app-pedido/datos/ { deny all; return 404; }
  location ~ /el-machin/app-pedido/\.git   { deny all; return 404; }
  ```

### 1.5 Comprobar

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://tedyc.com/el-machin/app-pedido/datos/.htaccess   # debe ser 403 o 404
curl -s https://tedyc.com/el-machin/app-pedido/api/menu.php | head -c 80                        # debe mostrar {"version":...
```

Luego abre el panel, entra con la contraseña, cambia un precio, guarda y confirma que la app lo muestra.

### Opcional: guardar los datos fuera de la carpeta pública

Para más seguridad, los datos pueden vivir fuera de la carpeta pública. Agrega esta línea a `api/config.local.php` (antes o después de `ADMIN_HASH`), crea la carpeta y dale permisos como en 1.2:

```php
define('DATA_DIR', '/var/lib/el-machin');
```

Si ya había datos en `datos/`, muévelos a la nueva carpeta.

---

## 2. Uso del panel (encargado)

### Cambiar un precio
1. Pestaña **💲 Precios** → en *Producto* elige el platillo.
2. Cambia el número (kg, lt, ½ litro o pieza).
3. Toca **💾 Guardar y publicar** en la barra de abajo. Los clientes ven el cambio al recargar la app.

### Agregar un platillo con mariscos (como un ceviche nuevo)
1. **🍽️ Productos** → **➕ Agregar fila**. Llena:
   - `nombre`: como saldrá en el pedido (ej. *Coctel*). No lo repitas.
   - `titulo` y `subtitulo`: lo que se ve en la tarjeta. `icono`: uno o dos emojis. `color`: color de la tarjeta.
   - `flujo` = **marisco**; `cobro` = **tamano** (kilo/litro/½) o **pieza**.
   - `mariscos` = **general** (o el grupo que corresponda); `sabores` = **aguachile** si pide sabor rojo/verde/negro.
   - `picor` = obligatorio, opcional o no. `ancho` = tercio (3 por fila), medio (2) o completo.
2. **💲 Precios** → elige el producto nuevo → **🪄 Crear filas faltantes** → llena los precios de cada fila (incluida la de *Mixto*).
3. **💾 Guardar y publicar**.

> **Solo aparecen los mariscos que tienen precio.** Si un platillo no se vende con algún marisco, borra esa fila en Precios (o déjala sin precios) y el cliente no verá esa opción. El pulpo, que no se pide solo, aparece si el platillo tiene precio *Mixto*. Ejemplo: una ensalada con precios solo para *Camarón cocido en agua* y *Mixto* muestra Camarón cocido en agua y Pulpo; camarón solo cobra su precio y camarón + pulpo cobra el Mixto. Si una combinación de tamaño queda sin precio, la app avisa que no está disponible y no deja agregarla.

### Agregar una bebida o platillo sin opciones (ej. agua fresca)
- **Opción A — como tarjeta en la pantalla principal:** en **Productos** agrega una fila con `flujo` = **simple** y `cobro` = **pieza**; en **Precios** elige el producto → **🪄 Crear filas faltantes** → pon el precio en *pieza*.
- **Opción B — como extra en el carrito:** en **🛍️ Extras** agrega una fila con nombre, icono, precio y la `seccion` donde debe aparecer (ej. *Refrescos 600ml*).

### Ocultar algo temporalmente
Pon `activo` = **no** (productos, mariscos, extras, operadores, opciones de micheladas). No se borra y puedes volver a activarlo.

### Operadores, días y horarios
- **💼 Operadores:** nombre y WhatsApp con `521` + 10 dígitos, sin espacios.
- **⚙️ Ajustes:** días de entrega (ej. `jueves,viernes,sabado`), horarios en formato 24 h separados por coma (ej. `13:00,13:30,14:00`) y hora de corte.

### Si algo sale mal
- Si al guardar aparece un recuadro rojo, el menú **no** se guardó: corrige lo que indica y vuelve a guardar.
- **🗂️ Respaldos:** cada guardado deja una copia del menú anterior. **↩️ Restaurar** regresa a esa versión.

### Pedidos
- **📋 Pedidos** muestra por defecto los de esta semana según la fecha de entrega. Toca un pedido para ver el detalle.
- Cambia el **estado** (nuevo → confirmado → entregado, o cancelado) para llevar el control.
- **⚠️ revisar** indica que el total que vio el cliente no coincide con los precios actuales (por ejemplo, si cambiaste un precio mientras armaba su pedido).
- **⬇️ CSV** descarga los pedidos filtrados para abrirlos en Excel.
- Cada pedido también es un archivo en `datos/pedidos/AAAA-MM/FOLIO.json` en el servidor. El folio (ej. `MACH-20261001-003`) aparece en el mensaje de WhatsApp.
- Si el cliente no tenía conexión con el servidor al enviar, el WhatsApp sale sin folio y el pedido se registra automáticamente la próxima vez que abra la app.

---

## 3. Referencia técnica

| Archivo | Función |
|---|---|
| `index.html` | App del cliente. Carga el menú de `api/menu.php` (respaldo: `menu-default.json`, luego el último menú guardado en el navegador). |
| `menu-default.json` | Menú base versionado en git. Se usa mientras el admin no haya guardado ningún cambio. |
| `admin/index.html` | Panel de administración. |
| `api/menu.php` | `GET` menú vigente: `datos/menu.json` o, si no existe, `menu-default.json`. |
| `api/pedido.php` | `POST` pedido → recalcula el total con los precios del servidor, asigna folio y guarda el archivo. Máx. 10 pedidos por IP cada 10 min. |
| `api/admin.php` | API del panel (sesión + token CSRF): login, menú, pedidos, estados, respaldos. |
| `api/crear-password.php` | Crea/cambia la contraseña (solo por consola). |
| `api/config.local.php` | Hash de la contraseña y ajustes locales. **No se sube a git.** |
| `datos/` | Menú editado, respaldos (`respaldos/`) y pedidos (`pedidos/AAAA-MM/`). **No se sube a git.** |

Para probar en local (requiere PHP): `php -S localhost:8000` en la carpeta de la app y abrir `http://localhost:8000/`. La app ya no funciona abriendo `index.html` con doble clic, porque el navegador no permite leer el menú desde un archivo local.
