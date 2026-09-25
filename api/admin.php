<?php
// API del panel admin. Todas las acciones (excepto estado y login) requieren sesión iniciada,
// y las que modifican datos requieren además el encabezado X-CSRF.
//   GET  ?accion=estado | menu | pedidos&desde=AAAA-MM-DD&hasta=AAAA-MM-DD | pedido&folio= | respaldos
//   POST ?accion=login | logout | guardar_menu | estado_pedido | restaurar
require __DIR__ . '/comun.php';

define('RESPALDOS_DIR', DATA_DIR . '/respaldos');
define('MAX_RESPALDOS', 60);
define('SESION_HORAS', 8);
const ESTADOS_PEDIDO = ['nuevo', 'confirmado', 'entregado', 'cancelado'];

session_name('machin_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function autenticado(): bool {
    if (empty($_SESSION['admin'])) return false;
    if (time() - ($_SESSION['actividad'] ?? 0) > SESION_HORAS * 3600) { $_SESSION = []; return false; }
    $_SESSION['actividad'] = time();
    return true;
}

function exigir_sesion(): void {
    if (!autenticado()) error_api('Sesión expirada. Vuelve a entrar.', 401);
}

function exigir_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) error_api('Token inválido. Recarga el panel.', 403);
}

// ── Validación del menú ─────────────────────────────────────────────────
function validar_menu($menu): array {
    $errores = [];
    if (!is_array($menu)) return ['El menú no es válido'];
    $listas = ['productos', 'precios', 'mariscos', 'sabores', 'niveles', 'tamanos', 'micheladas', 'extras', 'operadores', 'grupos', 'config'];
    foreach ($listas as $l) {
        if (!isset($menu[$l]) || !is_array($menu[$l])) $errores[] = "Falta la sección \"$l\"";
    }
    if ($errores) return $errores;

    $opciones = [
        'flujo' => ['marisco', 'michelada', 'simple'], 'cobro' => ['tamano', 'pieza'],
        'picor' => ['obligatorio', 'opcional', 'no'], 'ancho' => ['tercio', 'medio', 'completo'],
    ];
    $nombres = [];
    foreach ($menu['productos'] as $i => $p) {
        $n = trim((string) ($p['nombre'] ?? ''));
        $fila = 'Productos, fila ' . ($i + 1);
        if ($n === '') { $errores[] = "$fila: falta el nombre"; continue; }
        if (isset($nombres[$n])) $errores[] = "$fila: el nombre \"$n\" está repetido";
        $nombres[$n] = $p;
        foreach ($opciones as $campo => $validos) {
            if (!in_array($p[$campo] ?? '', $validos, true)) $errores[] = "$fila ($n): \"$campo\" debe ser " . implode(', ', $validos);
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($p['color'] ?? ''))) $errores[] = "$fila ($n): color inválido (usa #RRGGBB)";
    }

    $gruposSabor = array_unique(array_column($menu['sabores'], 'grupo'));
    $gruposMarisco = array_unique(array_column($menu['mariscos'], 'grupo'));
    foreach ($nombres as $n => $p) {
        if (!empty($p['sabores']) && !in_array($p['sabores'], $gruposSabor, true)) $errores[] = "Producto \"$n\": el grupo de sabores \"{$p['sabores']}\" no existe";
        if (($p['flujo'] ?? '') === 'marisco' && !in_array($p['mariscos'] ?? '', $gruposMarisco, true)) $errores[] = "Producto \"$n\": el grupo de mariscos \"" . ($p['mariscos'] ?? '') . "\" no existe";
        if (($p['flujo'] ?? '') !== 'michelada' && es_activo($p)) {
            $tiene = array_filter($menu['precios'], fn($f) => ($f['producto'] ?? '') === $n);
            if (!$tiene) $errores[] = "Producto \"$n\": no tiene precios";
        }
    }

    foreach ($menu['precios'] as $i => $f) {
        $fila = 'Precios, fila ' . ($i + 1);
        if (!isset($nombres[$f['producto'] ?? ''])) $errores[] = "$fila: el producto \"" . ($f['producto'] ?? '') . "\" no existe";
        foreach (['kg', 'lt', 'medio', 'pieza'] as $c) {
            $v = $f[$c] ?? '';
            if ($v !== '' && (!is_numeric($v) || $v < 0)) $errores[] = "$fila: \"$c\" debe ser un número";
        }
    }
    foreach ($menu['extras'] as $i => $e) {
        if (trim((string) ($e['nombre'] ?? '')) === '') $errores[] = 'Extras, fila ' . ($i + 1) . ': falta el nombre';
        if (!is_numeric($e['precio'] ?? '')) $errores[] = 'Extras, fila ' . ($i + 1) . ': el precio debe ser un número';
    }
    foreach ($menu['micheladas'] as $i => $m) {
        if (($m['opcion'] ?? '') === 'tipo' && !is_numeric($m['precio'] ?? '')) $errores[] = 'Micheladas, fila ' . ($i + 1) . ': el tipo necesita precio';
    }
    foreach ($menu['operadores'] as $i => $o) {
        if (!preg_match('/^\d{10,15}$/', (string) ($o['whatsapp'] ?? ''))) $errores[] = 'Operadores, fila ' . ($i + 1) . ': WhatsApp debe tener solo dígitos (ej. 5213141234567)';
    }
    return array_slice($errores, 0, 30);
}

// Copia el menú vigente a respaldos/ antes de sobrescribirlo y conserva solo los más recientes
function respaldar_menu(string $motivo): void {
    asegurar_dir(RESPALDOS_DIR);
    $actual = is_file(MENU_VIVO) ? MENU_VIVO : MENU_DEFAULT;
    if (!is_file($actual)) return;
    copy($actual, RESPALDOS_DIR . '/menu-' . date('Ymd-His') . '-' . $motivo . '.json');
    $archivos = glob(RESPALDOS_DIR . '/menu-*.json') ?: [];
    rsort($archivos);
    foreach (array_slice($archivos, MAX_RESPALDOS) as $viejo) @unlink($viejo);
}

function resumen_pedido(array $p): array {
    return [
        'folio' => $p['folio'], 'fecha_registro' => $p['fecha_registro'], 'estado' => $p['estado'] ?? 'nuevo',
        'cliente' => $p['cliente'], 'operador' => $p['operador'], 'grupo' => $p['grupo'] ?? '',
        'fecha_entrega' => $p['fecha_entrega'], 'fecha_texto' => $p['fecha_texto'] ?? '', 'hora' => $p['hora'],
        'entrega' => $p['entrega'], 'direccion' => $p['direccion'] ?? '', 'referencia' => $p['referencia'] ?? '',
        'gps' => $p['gps'] ?? null, 'articulos' => $p['articulos'], 'extras' => $p['extras'],
        'total_app' => $p['total_app'], 'total_calculado' => $p['total_calculado'], 'diferencia' => $p['diferencia'],
    ];
}

// ── Acciones ────────────────────────────────────────────────────────────
switch ("$metodo $accion") {

case 'GET estado':
    $ok = autenticado();
    if ($ok && empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    responder(['ok' => true, 'autenticado' => $ok, 'csrf' => $ok ? $_SESSION['csrf'] : null, 'configurado' => defined('ADMIN_HASH')]);

case 'POST login':
    if (!defined('ADMIN_HASH')) error_api('Falta crear la contraseña: ejecuta "php api/crear-password.php" en el servidor.', 503);
    limitar_frecuencia('login', 5, 900);
    $datos = leer_cuerpo_json(4096);
    if (!password_verify((string) ($datos['password'] ?? ''), ADMIN_HASH)) error_api('Contraseña incorrecta', 401);
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['actividad'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    responder(['ok' => true, 'csrf' => $_SESSION['csrf']]);

case 'POST logout':
    $_SESSION = [];
    session_destroy();
    responder(['ok' => true]);

case 'GET menu':
    exigir_sesion();
    responder(['ok' => true, 'menu' => menu_vigente(), 'origen' => is_file(MENU_VIVO) ? 'vivo' : 'default',
               'modificado' => is_file(MENU_VIVO) ? date('c', filemtime(MENU_VIVO)) : null]);

case 'POST guardar_menu':
    exigir_sesion(); exigir_csrf();
    $datos = leer_cuerpo_json(1048576);
    $menu = $datos['menu'] ?? null;
    $errores = validar_menu($menu);
    if ($errores) responder(['ok' => false, 'error' => 'El menú tiene errores', 'errores' => $errores], 422);
    $menu['version'] = (int) ($menu['version'] ?? 1);
    respaldar_menu('antes-de-guardar');
    escribir_json(MENU_VIVO, $menu);
    responder(['ok' => true, 'modificado' => date('c')]);

case 'GET pedidos':
    exigir_sesion();
    $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
    $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');
    $campo = ($_GET['por'] ?? '') === 'entrega' ? 'fecha_entrega' : 'fecha_registro';
    $pedidos = [];
    // Los archivos se agrupan por mes de registro; al filtrar por entrega se revisa un mes antes y uno después
    $mes = new DateTime(substr($desde, 0, 7) . '-01');
    $mes->modify($campo === 'fecha_entrega' ? '-1 month' : '+0 month');
    $fin = new DateTime(substr($hasta, 0, 7) . '-01');
    $fin->modify($campo === 'fecha_entrega' ? '+1 month' : '+0 month');
    for ($i = 0; $mes <= $fin && $i < 36; $i++, $mes->modify('+1 month')) {
        foreach (glob(PEDIDOS_DIR . '/' . $mes->format('Y-m') . '/MACH-*.json') ?: [] as $archivo) {
            $p = leer_json($archivo);
            if (!$p) continue;
            $fecha = substr((string) ($p[$campo] ?? ''), 0, 10);
            if ($fecha >= $desde && $fecha <= $hasta) $pedidos[] = resumen_pedido($p);
        }
    }
    usort($pedidos, fn($a, $b) => strcmp($b['fecha_registro'], $a['fecha_registro']));
    responder(['ok' => true, 'desde' => $desde, 'hasta' => $hasta, 'pedidos' => $pedidos]);

case 'GET pedido':
    exigir_sesion();
    $ruta = ruta_pedido((string) ($_GET['folio'] ?? ''));
    $p = $ruta ? leer_json($ruta) : null;
    if (!$p) error_api('Pedido no encontrado', 404);
    responder(['ok' => true, 'pedido' => $p]);

case 'POST estado_pedido':
    exigir_sesion(); exigir_csrf();
    $datos = leer_cuerpo_json(4096);
    $estado = (string) ($datos['estado'] ?? '');
    if (!in_array($estado, ESTADOS_PEDIDO, true)) error_api('Estado inválido');
    $ruta = ruta_pedido((string) ($datos['folio'] ?? ''));
    $p = $ruta ? leer_json($ruta) : null;
    if (!$p) error_api('Pedido no encontrado', 404);
    $p['estado'] = $estado;
    $p['historial'][] = ['estado' => $estado, 'fecha' => date('c')];
    escribir_json($ruta, $p);
    responder(['ok' => true]);

case 'GET respaldos':
    exigir_sesion();
    $lista = [];
    foreach (glob(RESPALDOS_DIR . '/menu-*.json') ?: [] as $f) {
        $lista[] = ['archivo' => basename($f), 'fecha' => date('c', filemtime($f)), 'bytes' => filesize($f)];
    }
    usort($lista, fn($a, $b) => strcmp($b['archivo'], $a['archivo']));
    responder(['ok' => true, 'respaldos' => $lista]);

case 'POST restaurar':
    exigir_sesion(); exigir_csrf();
    $datos = leer_cuerpo_json(4096);
    $archivo = (string) ($datos['archivo'] ?? '');
    if (!preg_match('/^menu-[\w-]+\.json$/', $archivo) || !is_file(RESPALDOS_DIR . '/' . $archivo)) error_api('Respaldo no encontrado', 404);
    $menu = leer_json(RESPALDOS_DIR . '/' . $archivo);
    if (validar_menu($menu)) error_api('Ese respaldo no es un menú válido', 422);
    respaldar_menu('antes-de-restaurar');
    escribir_json(MENU_VIVO, $menu);
    responder(['ok' => true]);

default:
    error_api('Acción no válida', 404);
}
