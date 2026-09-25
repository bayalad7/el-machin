<?php
// POST api/pedido.php → guarda el pedido en datos/pedidos/AAAA-MM/FOLIO.json y devuelve el folio.
// El total se recalcula con el menú del servidor; se guarda junto al total que mostró la app.
require __DIR__ . '/comun.php';

exigir_metodo('POST');
limitar_frecuencia('pedido', 10, 600);
$p = leer_cuerpo_json();

$nombre   = texto($p, 'nombre', 100);
$telefono = texto($p, 'telefono', 30);
$items    = $p['items'] ?? null;
if ($nombre === '' || $telefono === '') error_api('Faltan nombre o teléfono');
if (!is_array($items) || count($items) === 0 || count($items) > 50) error_api('El pedido no tiene artículos válidos');

$menu = menu_vigente();
$total = 0.0;
$articulos = [];
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $qty = max(1, min(999, (int) ($item['qty'] ?? 1)));
    $unit = precio_unitario($menu, $item);
    $total += $unit * $qty;
    $articulos[] = [
        'producto'        => texto($item, 'prep', 100),
        'descripcion'     => texto($item, 'descripcion', 500),
        'cantidad'        => $qty,
        'precio_unitario' => $unit,
        'subtotal'        => $unit * $qty,
        'precio_app'      => (float) ($item['price'] ?? 0),
        'notas'           => texto($item, 'notes', 500),
        'detalle'         => array_intersect_key($item, array_flip([
            'mariscos', 'size', 'sabor', 'tostosSabor', 'nivel',
            'micheladaCerveza', 'micheladaPreparado', 'micheladaTipo', 'micheladaCamaron',
        ])),
    ];
}

$extras = [];
foreach ((array) ($p['extras'] ?? []) as $nombreExtra => $qty) {
    $qty = (int) $qty;
    if ($qty <= 0 || !is_string($nombreExtra)) continue;
    $unit = precio_extra($menu, $nombreExtra);
    $total += $unit * $qty;
    $extras[] = ['nombre' => mb_substr($nombreExtra, 0, 100), 'cantidad' => min($qty, 999), 'precio_unitario' => $unit, 'subtotal' => $unit * $qty];
}

$totalApp = (float) ($p['total'] ?? 0);
$folio = siguiente_folio();
$gps = $p['gps'] ?? null;

$pedido = [
    'folio'           => $folio,
    'fecha_registro'  => date('c'),
    'estado'          => 'nuevo',
    'operador'        => texto($p, 'operador', 60),
    'cliente'         => ['nombre' => $nombre, 'telefono' => $telefono],
    'grupo'           => texto($p, 'grupo', 100),
    'fecha_entrega'   => texto($p, 'fecha', 10),
    'fecha_texto'     => texto($p, 'fechaTexto', 60),
    'hora'            => texto($p, 'hora', 10),
    'entrega'         => texto($p, 'entrega', 20),
    'forma_pago'      => in_array($p['formaPago'] ?? '', ['efectivo', 'transferencia'], true) ? $p['formaPago'] : '',
    'direccion'       => texto($p, 'direccion', 300),
    'referencia'      => texto($p, 'referencia', 300),
    'gps'             => is_array($gps) && is_numeric($gps['lat'] ?? null) && is_numeric($gps['lng'] ?? null)
                         ? ['lat' => (float) $gps['lat'], 'lng' => (float) $gps['lng']] : null,
    'articulos'       => $articulos,
    'extras'          => $extras,
    'total_app'       => $totalApp,
    'total_calculado' => round($total, 2),
    'diferencia'      => round($totalApp - $total, 2) != 0.0,
    'id_cliente'      => texto($p, 'idLocal', 40),
];

// Alta/actualización del cliente y su domicilio en el catálogo (si falla, el pedido se guarda igual)
$registro = registrar_cliente_de_pedido($pedido);
if ($registro) {
    $pedido['cliente']['telefono_normalizado'] = $registro['telefono'];
    $pedido['cliente']['domicilio_id'] = $registro['domicilio_id'];
}

escribir_json(ruta_pedido($folio), $pedido);
responder(['ok' => true, 'folio' => $folio, 'total_calculado' => $pedido['total_calculado']]);
