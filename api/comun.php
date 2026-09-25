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
define('CLIENTES_FILE', DATA_DIR . '/clientes.json');
define('ENVIO_FILE', DATA_DIR . '/envio.json');

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

// Escritura atómica: archivo temporal + rename, para no dejar JSON a medias. Devuelve si se guardó.
function guardar_json(string $ruta, array $datos): bool {
    $dir = dirname($ruta);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    $tmp = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $ruta)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function escribir_json(string $ruta, array $datos): void {
    if (!guardar_json($ruta, $datos)) error_api('No se pudo guardar el archivo. Revisa permisos del servidor.', 500);
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

// ── Clientes ────────────────────────────────────────────────────────────
// datos/clientes.json: {"clientes": {"3141234567": {telefono, nombre, notas, domicilios: [...], ...}}}
// La clave es el teléfono a 10 dígitos.

function normalizar_telefono(string $t): string {
    $d = preg_replace('/\D/', '', $t);
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

function leer_clientes(): array {
    return leer_json(CLIENTES_FILE)['clientes'] ?? [];
}

// Lee, modifica y guarda clientes.json con bloqueo exclusivo (evita perder cambios simultáneos).
// $fn recibe el arreglo de clientes por referencia. Devuelve lo que devuelva $fn.
// Lanza RuntimeException si no puede bloquear o guardar (no termina el script).
function con_clientes(callable $fn) {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $lock = @fopen(CLIENTES_FILE . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('No se pudo abrir el catálogo de clientes');
    try {
        $clientes = leer_clientes();
        $res = $fn($clientes);
        if (!guardar_json(CLIENTES_FILE, ['clientes' => $clientes])) throw new RuntimeException('No se pudo guardar el catálogo de clientes');
        return $res;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function distancia_metros(array $a, array $b): float {
    $r = 6371000; $rad = M_PI / 180;
    $dLat = ($b['lat'] - $a['lat']) * $rad; $dLng = ($b['lng'] - $a['lng']) * $rad;
    $h = sin($dLat / 2) ** 2 + cos($a['lat'] * $rad) * cos($b['lat'] * $rad) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($h)));
}

function clave_direccion(string $d): string {
    return preg_replace('/[^a-z0-9]+/', ' ', normalizar($d));
}

// Busca un domicilio igual (misma dirección o GPS a menos de 60 m) o lo agrega. Devuelve su id.
function upsert_domicilio(array &$cliente, string $direccion, string $referencia, ?array $gps): string {
    $clave = clave_direccion($direccion);
    foreach ($cliente['domicilios'] as &$d) {
        $mismoTexto = $clave !== '' && clave_direccion($d['direccion']) === $clave;
        $mismoGps = $gps && !empty($d['gps']) && distancia_metros($gps, $d['gps']) < 60;
        if ($mismoTexto || $mismoGps) {
            if ($gps) $d['gps'] = $gps;
            if ($referencia !== '' && $d['referencia'] === '') $d['referencia'] = $referencia;
            $d['ultimo_uso'] = date('c');
            return $d['id'];
        }
    }
    unset($d);
    $id = 'd' . base_convert((string) (int) (microtime(true) * 1000), 10, 36);
    $cliente['domicilios'][] = ['id' => $id, 'alias' => '', 'direccion' => $direccion, 'referencia' => $referencia,
                                'gps' => $gps, 'creado' => date('c'), 'ultimo_uso' => date('c')];
    return $id;
}

// Da de alta o actualiza al cliente del pedido y, si es a domicilio, su domicilio.
// No debe impedir que se guarde el pedido: ante cualquier error devuelve null.
function registrar_cliente_de_pedido(array $pedido): ?array {
    $tel = normalizar_telefono($pedido['cliente']['telefono']);
    if (strlen($tel) < 10) return null;
    try {
        return con_clientes(function (&$clientes) use ($pedido, $tel) {
            $c = $clientes[$tel] ?? ['telefono' => $tel, 'nombre' => '', 'notas' => '', 'domicilios' => [],
                                     'total_pedidos' => 0, 'primer_pedido' => date('c')];
            $c['nombre'] = $pedido['cliente']['nombre'];
            if ($pedido['grupo'] !== '') $c['grupo'] = $pedido['grupo'];
            $c['total_pedidos'] = ($c['total_pedidos'] ?? 0) + 1;
            $c['ultimo_pedido'] = date('c');
            $domId = null;
            if ($pedido['entrega'] === 'domicilio' && $pedido['direccion'] !== '') {
                $domId = upsert_domicilio($c, $pedido['direccion'], $pedido['referencia'], $pedido['gps']);
            }
            $clientes[$tel] = $c;
            return ['telefono' => $tel, 'domicilio_id' => $domId];
        });
    } catch (Throwable $e) {
        return null;
    }
}

// ── Tarifario de envío ──────────────────────────────────────────────────
// Base + costo por km extra, con un tarifario normal y otro para lluvia.
function envio_config(): array {
    $def = [
        'restaurante'   => ['lat' => 19.1273254, 'lng' => -104.34609, 'mapa' => 'https://maps.app.goo.gl/wmoyhHNFUDe9B2gV8'],
        'factor_calles' => 1.3,
        'lluvia_activa' => false,
        'normal'        => ['base_km' => 5, 'base_precio' => 40, 'precio_km_extra' => 0],
        'lluvia'        => ['base_km' => 5, 'base_precio' => 40, 'precio_km_extra' => 0],
    ];
    $cfg = leer_json(ENVIO_FILE) ?? [];
    return array_replace_recursive($def, $cfg);
}

// Precio del envío: base hasta base_km; después, precio_km_extra por cada km adicional (o fracción)
function calcular_tarifa(array $cfg, float $km, bool $lluvia): float {
    $t = $cfg[$lluvia ? 'lluvia' : 'normal'];
    $extra = max(0, ceil(round($km - (float) $t['base_km'], 2)));
    return (float) $t['base_precio'] + $extra * (float) $t['precio_km_extra'];
}
