<?php
// Funciones compartidas por la API. No se llama directamente.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

date_default_timezone_set('America/Mexico_City');

// Ajustes locales opcionales (no versionados): ruta de datos, hash de contraseña del admin.
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

if (!defined('DATA_DIR'))     define('DATA_DIR', dirname(__DIR__) . '/datos');
if (!defined('MENU_DEFAULT')) define('MENU_DEFAULT', dirname(__DIR__) . '/menu-default.json');
define('MENU_VIVO', DATA_DIR . '/menu.json');
define('PEDIDOS_DIR', DATA_DIR . '/pedidos');

// ── Respuestas ──────────────────────────────────────────────────────────
function responder($datos, int $codigo = 200): void {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_api(string $mensaje, int $codigo = 400): void {
    responder(['ok' => false, 'error' => $mensaje], $codigo);
}

function exigir_metodo(string $metodo): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) error_api('Método no permitido', 405);
}

// Lee el cuerpo JSON de la petición (acepta application/json o text/plain)
function leer_cuerpo_json(int $max_bytes = 65536): array {
    $crudo = file_get_contents('php://input', false, null, 0, $max_bytes + 1);
    if ($crudo === false || $crudo === '') error_api('Petición vacía');
    if (strlen($crudo) > $max_bytes) error_api('Petición demasiado grande', 413);
    $datos = json_decode($crudo, true);
    if (!is_array($datos)) error_api('JSON inválido');
    return $datos;
}

// ── Archivos JSON ───────────────────────────────────────────────────────
function asegurar_dir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_api('No se pudo crear la carpeta de datos. Revisa permisos del servidor.', 500);
    }
}

function leer_json(string $ruta): ?array {
    if (!is_file($ruta)) return null;
    $datos = json_decode((string) file_get_contents($ruta), true);
    return is_array($datos) ? $datos : null;
}

// Escritura atómica: archivo temporal + rename, para no dejar JSON a medias
function escribir_json(string $ruta, array $datos): void {
    asegurar_dir(dirname($ruta));
    $tmp = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $ruta)) {
        @unlink($tmp);
        error_api('No se pudo guardar el archivo. Revisa permisos del servidor.', 500);
    }
}

// ── Menú ────────────────────────────────────────────────────────────────
// El menú vivo (datos/menu.json) lo edita el admin; si no existe se usa menu-default.json
function menu_vigente(): array {
    $menu = leer_json(MENU_VIVO) ?? leer_json(MENU_DEFAULT);
    if (!$menu || !isset($menu['productos'])) error_api('No hay menú disponible', 500);
    return $menu;
}

function es_si($v): bool {
    return $v === true || strtolower(trim((string) $v)) === 'si';
}

function es_activo(array $fila): bool {
    return !isset($fila['activo']) || $fila['activo'] === '' || es_si($fila['activo']);
}

function normalizar(string $s): string {
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $sin = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return $sin === false ? $s : $sin;
}

function buscar_producto(array $menu, string $nombre): ?array {
    foreach ($menu['productos'] as $p) {
        if (($p['nombre'] ?? '') === $nombre && es_activo($p)) return $p;
    }
    return null;
}

// Misma regla que buscarPrecio() de index.html
function buscar_precio(array $menu, array $prod, array $mariscos, string $columna, string $sabor = ''): float {
    $marisco = count($mariscos) > 1 ? 'Mixto' : ($mariscos[0] ?? '');
    $conSabor = null; $sinSabor = null;
    foreach ($menu['precios'] ?? [] as $f) {
        if (($f['producto'] ?? '') !== $prod['nombre'] || normalizar((string) ($f['marisco'] ?? '')) !== normalizar($marisco)) continue;
        if (($f['sabor'] ?? '') === '') $sinSabor = $sinSabor ?? $f;
        elseif (normalizar($f['sabor']) === normalizar($sabor)) $conSabor = $conSabor ?? $f;
    }
    $fila = $conSabor ?? $sinSabor;
    return $fila ? (float) ($fila[$columna] ?? 0) : 0.0;
}

// Precio unitario de un artículo del carrito según el menú del servidor
function precio_unitario(array $menu, array $item): float {
    $prod = buscar_producto($menu, (string) ($item['prep'] ?? ''));
    if (!$prod) return 0.0;
    $flujo = $prod['flujo'] ?? 'marisco';

    if ($flujo === 'michelada') {
        foreach ($menu['micheladas'] ?? [] as $m) {
            if (($m['opcion'] ?? '') === 'tipo' && ($m['clave'] ?? '') === ($item['micheladaTipo'] ?? '')) return (float) $m['precio'];
        }
        return 0.0;
    }
    if ($flujo === 'simple') return buscar_precio($menu, $prod, [], 'pieza');

    $mariscos = array_values(array_filter((array) ($item['mariscos'] ?? []), 'is_string'));
    if (!$mariscos) return 0.0;
    $porPieza = ($prod['cobro'] ?? '') === 'pieza';
    $columna = $porPieza ? 'pieza' : (string) ($item['size'] ?? '');
    if (!in_array($columna, ['kg', 'lt', 'medio', 'pieza'], true)) return 0.0;

    $sabor = '';
    if (!empty($prod['sabores'])) {
        foreach ($menu['sabores'] ?? [] as $s) {
            if (($s['grupo'] ?? '') === $prod['sabores'] && ($s['clave'] ?? '') === ($item['sabor'] ?? '')) $sabor = $s['nombre'];
        }
        if ($sabor === '') return 0.0;
    }
    return buscar_precio($menu, $prod, $mariscos, $columna, $sabor);
}

function precio_extra(array $menu, string $nombre): float {
    foreach ($menu['extras'] ?? [] as $e) {
        if (($e['nombre'] ?? '') === $nombre && es_activo($e)) return (float) $e['precio'];
    }
    return 0.0;
}

// ── Pedidos ─────────────────────────────────────────────────────────────
// Folio consecutivo por día: MACH-AAAAMMDD-001 (con bloqueo para pedidos simultáneos)
function siguiente_folio(): string {
    asegurar_dir(PEDIDOS_DIR);
    $hoy = date('Ymd');
    $fp = fopen(PEDIDOS_DIR . '/.contador', 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) error_api('No se pudo generar el folio', 500);
    $datos = json_decode(stream_get_contents($fp) ?: '', true) ?: [];
    $n = (($datos['fecha'] ?? '') === $hoy) ? ((int) $datos['n'] + 1) : 1;
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(['fecha' => $hoy, 'n' => $n]));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return sprintf('MACH-%s-%03d', $hoy, $n);
}

function ruta_pedido(string $folio): ?string {
    if (!preg_match('/^MACH-(\d{4})(\d{2})\d{2}-\d{3,}$/', $folio, $m)) return null;
    return PEDIDOS_DIR . "/{$m[1]}-{$m[2]}/{$folio}.json";
}

// Límite sencillo por IP para evitar abuso del formulario
function limitar_frecuencia(string $accion, int $max, int $segundos): void {
    $dir = DATA_DIR . '/limites';
    asegurar_dir($dir);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
    $ruta = $dir . '/' . $accion . '-' . substr(hash('sha256', $ip), 0, 16) . '.json';
    $ahora = time();
    $marcas = array_filter(leer_json($ruta) ?? [], fn($t) => $t > $ahora - $segundos);
    if (count($marcas) >= $max) error_api('Demasiados intentos. Espera unos minutos.', 429);
    $marcas[] = $ahora;
    file_put_contents($ruta, json_encode(array_values($marcas)), LOCK_EX);
}

function texto(array $datos, string $clave, int $max = 200): string {
    $v = $datos[$clave] ?? '';
    return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max, 'UTF-8') : '';
}
