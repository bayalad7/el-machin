<?php
// API del panel admin. Todas las acciones (excepto estado y login) requieren sesión iniciada,
// y las que modifican datos requieren además el encabezado X-CSRF.
//   GET  ?accion=estado | menu | pedidos&desde=AAAA-MM-DD&hasta=AAAA-MM-DD | pedido&folio= | respaldos
//   POST ?accion=login | logout | guardar_menu | estado_pedido | restaurar
require __DIR__ . '/comun.php';

define('RESPALDOS_DIR', DATA_DIR . '/respaldos');
define('MAX_RESPALDOS', 60);
define('SESION_HORAS', 8);
// Flujo de estados del pedido: nuevo → confirmado → entregado, o nuevo → cancelado.
// entregado y cancelado son finales. Mismo flujo que ACCIONES_ESTADO en admin/index.html.
const TRANSICIONES_PEDIDO = [
    'nuevo'      => ['confirmado', 'cancelado'],
    'confirmado' => ['entregado'],
    'entregado'  => [],
    'cancelado'  => [],
];

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
        'envio' => $p['envio'] ?? null, 'total_final' => $p['total_final'] ?? null, 'forma_pago' => $p['forma_pago'] ?? '',
    ];
}

function numero($v, float $min = 0, float $max = 100000): float {
    return is_numeric($v) ? max($min, min($max, (float) $v)) : 0.0;
}

function gps_valido($g): ?array {
    return is_array($g) && is_numeric($g['lat'] ?? null) && is_numeric($g['lng'] ?? null)
        && abs($g['lat']) <= 90 && abs($g['lng']) <= 180 ? ['lat' => (float) $g['lat'], 'lng' => (float) $g['lng']] : null;
}

// Limpia un cliente que llega del panel; conserva estadísticas del registro anterior
function limpiar_cliente(array $c, string $tel, array $previo): array {
    $doms = [];
    foreach ((array) ($c['domicilios'] ?? []) as $d) {
        if (!is_array($d) || trim((string) ($d['direccion'] ?? '')) === '') continue;
        $doms[] = [
            'id'         => preg_match('/^d[a-z0-9]+$/', (string) ($d['id'] ?? '')) ? $d['id'] : 'd' . base_convert((string) (int) (microtime(true) * 1000) + count($doms), 10, 36),
            'alias'      => texto($d, 'alias', 60),
            'direccion'  => texto($d, 'direccion', 300),
            'referencia' => texto($d, 'referencia', 300),
            'gps'        => gps_valido($d['gps'] ?? null),
            'creado'     => $d['creado'] ?? date('c'),
            'ultimo_uso' => $d['ultimo_uso'] ?? null,
        ];
    }
    return array_merge($previo, [
        'telefono' => $tel, 'nombre' => texto($c, 'nombre', 100), 'notas' => texto($c, 'notas', 500),
        'grupo' => texto($c, 'grupo', 100), 'domicilios' => $doms,
        'total_pedidos' => $previo['total_pedidos'] ?? 0, 'primer_pedido' => $previo['primer_pedido'] ?? date('c'),
    ]);
}

function con_clientes_admin(callable $fn) {
    try { return con_clientes($fn); } catch (RuntimeException $e) { error_api($e->getMessage(), 500); }
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
    if (!isset(TRANSICIONES_PEDIDO[$estado])) error_api('Estado inválido');
    $ruta = ruta_pedido((string) ($datos['folio'] ?? ''));
    $p = $ruta ? leer_json($ruta) : null;
    if (!$p) error_api('Pedido no encontrado', 404);
    $actual = $p['estado'] ?? 'nuevo';
    if (!in_array($estado, TRANSICIONES_PEDIDO[$actual] ?? [], true)) {
        error_api("El pedido está $actual y no se puede cambiar a $estado. Recarga la lista.", 409);
    }
    $p['estado'] = $estado;
    $p['historial'][] = ['estado' => $estado, 'fecha' => date('c')];
    escribir_json($ruta, $p);
    responder(['ok' => true]);

// Elimina un pedido moviéndolo a datos/papelera/ (se puede recuperar desde el servidor)
case 'POST eliminar_pedido':
    exigir_sesion(); exigir_csrf();
    $folio = (string) (leer_cuerpo_json(4096)['folio'] ?? '');
    $ruta = ruta_pedido($folio);
    $p = $ruta ? leer_json($ruta) : null;
    if (!$p) error_api('Pedido no encontrado', 404);
    $p['eliminado'] = date('c');
    escribir_json(DATA_DIR . '/papelera/' . $folio . '.json', $p);
    if (!@unlink($ruta)) error_api('No se pudo eliminar el pedido. Revisa permisos del servidor.', 500);
    // Descontarlo del contador del cliente (si falla, el pedido ya quedó eliminado)
    $tel = normalizar_telefono((string) ($p['cliente']['telefono'] ?? ''));
    try {
        con_clientes(function (&$clientes) use ($tel) {
            if (isset($clientes[$tel])) $clientes[$tel]['total_pedidos'] = max(0, ($clientes[$tel]['total_pedidos'] ?? 0) - 1);
        });
    } catch (RuntimeException $e) {}
    responder(['ok' => true]);

// ── Clientes ──
case 'GET clientes':
    exigir_sesion();
    $lista = array_values(leer_clientes());
    usort($lista, fn($a, $b) => strcmp($b['ultimo_pedido'] ?? '', $a['ultimo_pedido'] ?? ''));
    responder(['ok' => true, 'clientes' => $lista]);

case 'GET cliente':
    exigir_sesion();
    $tel = normalizar_telefono((string) ($_GET['telefono'] ?? ''));
    responder(['ok' => true, 'cliente' => leer_clientes()[$tel] ?? null]);

case 'POST guardar_cliente':
    exigir_sesion(); exigir_csrf();
    $datos = leer_cuerpo_json(65536);
    $c = is_array($datos['cliente'] ?? null) ? $datos['cliente'] : error_api('Cliente inválido');
    $tel = normalizar_telefono((string) ($c['telefono'] ?? ''));
    $original = normalizar_telefono((string) ($datos['telefono_original'] ?? ''));
    if (strlen($tel) !== 10) error_api('El teléfono debe tener 10 dígitos');
    if (texto($c, 'nombre') === '') error_api('Falta el nombre del cliente');
    $guardado = con_clientes_admin(function (&$clientes) use ($c, $tel, $original) {
        if ($tel !== $original && isset($clientes[$tel])) return null;
        $previo = $clientes[$original] ?? [];
        if ($original !== '' && $original !== $tel) unset($clientes[$original]);
        return $clientes[$tel] = limpiar_cliente($c, $tel, $previo);
    });
    if (!$guardado) error_api('Ya existe otro cliente con ese teléfono', 409);
    responder(['ok' => true, 'cliente' => $guardado]);

case 'POST eliminar_cliente':
    exigir_sesion(); exigir_csrf();
    $tel = normalizar_telefono((string) (leer_cuerpo_json(4096)['telefono'] ?? ''));
    con_clientes_admin(function (&$clientes) use ($tel) { unset($clientes[$tel]); });
    responder(['ok' => true]);

// ── Tarifario de envío ──
case 'GET envio':
    exigir_sesion();
    responder(['ok' => true, 'config' => envio_config()]);

case 'POST guardar_envio':
    exigir_sesion(); exigir_csrf();
    $c = leer_cuerpo_json(16384)['config'] ?? [];
    // Tabla por distancia: renglones con km > 0, sin distancias repetidas, ordenados
    $tarifa = function ($t, string $nombre) {
        $rangos = [];
        foreach ((array) ($t['rangos'] ?? []) as $r) {
            if (!is_array($r) || !is_numeric($r['hasta_km'] ?? null) || (float) $r['hasta_km'] <= 0) continue;
            $km = round((float) $r['hasta_km'], 1);
            $rangos[(string) $km] = ['hasta_km' => $km, 'precio' => numero($r['precio'] ?? 0)];
        }
        if (!$rangos) error_api("La tarifa $nombre necesita al menos un renglón con distancia y precio");
        if (count($rangos) > 60) error_api("La tarifa $nombre tiene demasiados renglones");
        ksort($rangos, SORT_NUMERIC);
        return ['rangos' => array_values($rangos), 'km_extra_despues' => numero($t['km_extra_despues'] ?? 0)];
    };
    $rest = gps_valido($c['restaurante'] ?? null);
    if (!$rest) error_api('Coordenadas del restaurante inválidas');
    $cfg = [
        'restaurante'   => $rest + ['mapa' => texto($c['restaurante'], 'mapa', 300)],
        'factor_calles' => numero($c['factor_calles'] ?? 1.3, 1, 3),
        'lluvia_activa' => !empty($c['lluvia_activa']),
        'normal'        => $tarifa($c['normal'] ?? [], 'normal'),
        'lluvia'        => $tarifa($c['lluvia'] ?? [], 'con lluvia'),
    ];
    escribir_json(ENVIO_FILE, $cfg);
    responder(['ok' => true, 'config' => $cfg]);

// Guarda el cálculo del envío de un pedido a domicilio y actualiza su total
case 'POST envio_pedido':
    exigir_sesion(); exigir_csrf();
    $datos = leer_cuerpo_json(8192);
    $ruta = ruta_pedido((string) ($datos['folio'] ?? ''));
    $p = $ruta ? leer_json($ruta) : null;
    if (!$p) error_api('Pedido no encontrado', 404);
    $e = (array) ($datos['envio'] ?? []);
    $envio = [
        'domicilio_id'    => preg_match('/^d[a-z0-9]+$/', (string) ($e['domicilio_id'] ?? '')) ? $e['domicilio_id'] : null,
        'para'            => texto($e, 'para', 300),
        'km'              => round(numero($e['km'] ?? 0, 0, 500), 1),
        'lluvia'          => !empty($e['lluvia']),
        'forma_pago'      => in_array($e['forma_pago'] ?? '', ['efectivo', 'transferencia'], true) ? $e['forma_pago'] : 'efectivo',
        'listo'           => preg_match('/^\d{2}:\d{2}$/', (string) ($e['listo'] ?? '')) ? $e['listo'] : '',
        'tarifa'          => numero($e['tarifa'] ?? 0),
        'repartidor_paga' => numero($e['repartidor_paga'] ?? 0),
        'cliente_paga'    => numero($e['cliente_paga'] ?? 0),
        'ganancia'        => numero($e['ganancia'] ?? 0),
        'actualizado'     => date('c'),
    ];
    $p['envio'] = $envio;
    $p['total_final'] = round((float) $p['total_calculado'] + $envio['tarifa'], 2);
    if ($envio['domicilio_id']) $p['cliente']['domicilio_id'] = $envio['domicilio_id'];
    escribir_json($ruta, $p);
    responder(['ok' => true, 'envio' => $envio, 'total_final' => $p['total_final']]);

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
