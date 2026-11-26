<?php
/**
 * Conexión a MySQL.
 * En Azure App Service define estas variables en
 * Configuración > Variables de entorno (App settings).
 * Si no existen, usa los valores de desarrollo local (XAMPP).
 */

$host      = getenv('DB_HOST') ?: 'artesaniasdb.mysql.database.azure.com';
$puerto    = getenv('DB_PORT') ?: '3306';
$basedatos = getenv('DB_NAME') ?: 'artesanias_wayuu';
$usuario   = getenv('DB_USER') ?: 'alexys';
$password  = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

$opciones = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Azure Database for MySQL exige TLS por defecto (DB_SSL=1 en App Service).
// PHP 8.4+ usa Pdo\Mysql::ATTR_*; las PDO::MYSQL_ATTR_* quedan obsoletas en 8.5.
if (getenv('DB_SSL') === '1') {
    $nuevo = defined('Pdo\Mysql::ATTR_SSL_CA');
    $attrCa     = $nuevo ? constant('Pdo\Mysql::ATTR_SSL_CA') : PDO::MYSQL_ATTR_SSL_CA;
    $attrVerify = $nuevo ? constant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
    $opciones[$attrCa]     = getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';
    $opciones[$attrVerify] = true;
}

try {
    $conexion = new PDO(
        "mysql:host=$host;port=$puerto;dbname=$basedatos;charset=utf8mb4",
        $usuario,
        $password,
        $opciones
    );
} catch (PDOException $e) {
    error_log('Error de conexion a la BD: ' . $e->getMessage());
    if (getenv('DB_DEBUG') === '1') {
        // Sin código 500: Azure lo intercepta y lo muestra como "404 Not Found"
        die('<pre>Error de conexion: ' . htmlspecialchars($e->getMessage())
            . "\nhost=$host puerto=$puerto bd=$basedatos usuario=$usuario ssl="
            . (getenv('DB_SSL') === '1' ? 'si' : 'no') . '</pre>');
    }
    http_response_code(500);
    die('No fue posible conectar con la base de datos.');
}
