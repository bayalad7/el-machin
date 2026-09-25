<?php
// Define la contraseña del panel admin. Se ejecuta en el servidor por consola:
//   php api/crear-password.php
// Crea (o actualiza) api/config.local.php con el hash de la contraseña.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ruta = __DIR__ . '/config.local.php';

echo "Nueva contraseña del panel admin (mínimo 8 caracteres): ";
$pass = trim((string) fgets(STDIN));
if (mb_strlen($pass) < 8) { fwrite(STDERR, "La contraseña debe tener al menos 8 caracteres.\n"); exit(1); }

echo "Repite la contraseña: ";
if (trim((string) fgets(STDIN)) !== $pass) { fwrite(STDERR, "Las contraseñas no coinciden.\n"); exit(1); }

$hash = password_hash($pass, PASSWORD_DEFAULT);

// Conserva otras líneas de config.local.php (ej. DATA_DIR) y reemplaza solo ADMIN_HASH
$lineas = is_file($ruta) ? file($ruta, FILE_IGNORE_NEW_LINES) : ['<?php', '// Configuración local (no se sube a git)'];
$lineas = array_values(array_filter($lineas, fn($l) => strpos($l, "'ADMIN_HASH'") === false));
$lineas[] = "define('ADMIN_HASH', " . var_export($hash, true) . ");";

if (file_put_contents($ruta, implode(PHP_EOL, $lineas) . PHP_EOL) === false) {
    fwrite(STDERR, "No se pudo escribir $ruta\n"); exit(1);
}
echo "Listo. Contraseña guardada en api/config.local.php\n";
