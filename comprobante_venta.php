<?php
require_once 'includes/verificar_sesion.php';
require_once 'config/conexion.php';

$idVenta = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idVenta || $idVenta <= 0) {
    die("ID de venta no válido.");
}

try {
    $sqlVenta = "SELECT v.id_venta, v.id_pedido, v.fecha_venta, v.metodo_pago, v.total,
                        c.nombre AS cliente, c.documento, c.telefono, c.correo, c.direccion
                 FROM venta v
                 INNER JOIN pedido p ON v.id_pedido = p.id_pedido
                 INNER JOIN cliente c ON p.id_cliente = c.id_cliente
                 WHERE v.id_venta = :id LIMIT 1";
    $stmt = $conexion->prepare($sqlVenta);
    $stmt->execute([':id' => $idVenta]);
    $venta = $stmt->fetch();

    if (!$venta) {
        die("El comprobante solicitado no existe.");
    }

    $sqlDetalle = "SELECT dp.cantidad, dp.precio_unitario, dp.subtotal, p.nombre AS producto
                   FROM detalle_pedido dp
                   INNER JOIN producto p ON dp.id_producto = p.id_producto
                   WHERE dp.id_pedido = :id_pedido";
    $stmtDet = $conexion->prepare($sqlDetalle);
    $stmtDet->execute([':id_pedido' => (int)$venta['id_pedido']]);
    $detalles = $stmtDet->fetchAll();

} catch (PDOException $e) {
    error_log("Error al generar comprobante: " . $e->getMessage());
    die("Error interno al cargar el comprobante.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante de Venta #<?= (int)$venta['id_venta'] ?> - Artesanías Wayúu</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #333; }
        .factura { max-width: 720px; margin: 0 auto; background: #fff; padding: 35px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 6px solid #753b24; }
        .encabezado { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f2ebe5; padding-bottom: 18px; margin-bottom: 20px; }
        .encabezado h1 { margin: 0; color: #753b24; font-size: 24px; }
        .encabezado p { margin: 4px 0 0; font-size: 13px; color: #666; }
        .info-venta { text-align: right; font-size: 14px; }
        .datos-cliente { background: #fdfaf7; padding: 15px; border-radius: 6px; margin-bottom: 22px; font-size: 14px; line-height: 1.6; border-left: 4px solid #d9822b; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e0e0e0; text-align: left; font-size: 14px; }
        th { background: #f2ebe5; color: #753b24; }
        .derecha { text-align: right; }
        .total-box { text-align: right; font-size: 20px; font-weight: bold; color: #287a45; margin-top: 10px; }
        .acciones { text-align: center; margin-top: 30px; display: flex; justify-content: center; gap: 12px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; font-size: 14px; font-weight: bold; cursor: pointer; text-decoration: none; color: #fff; }
        .btn-imprimir { background: #287a45; }
        .btn-cerrar { background: #6c757d; }
        @media print {
            body { background: #fff; padding: 0; }
            .factura { box-shadow: none; border-top: none; padding: 0; }
            .acciones { display: none !important; }
        }
    </style>
</head>
<body>

<div class="factura">
    <div class="encabezado">
        <div>
            <h1>ARTESANÍAS WAYÚU</h1>
            <p>Plataforma de comercialización de productos artesanales<br>La Guajira, Colombia</p>
        </div>
        <div class="info-venta">
            <strong>COMPROBANTE DE VENTA</strong><br>
            N.º Venta: <strong>#<?= (int)$venta['id_venta'] ?></strong><br>
            Pedido Ref: #<?= (int)$venta['id_pedido'] ?><br>
            Fecha: <?= htmlspecialchars($venta['fecha_venta']) ?>
        </div>
    </div>

    <div class="datos-cliente">
        <strong>Cliente:</strong> <?= htmlspecialchars($venta['cliente']) ?><br>
        <strong>Documento:</strong> <?= htmlspecialchars($venta['documento'] ?? 'No registrado') ?><br>
        <strong>Teléfono:</strong> <?= htmlspecialchars($venta['telefono'] ?? 'No registrado') ?><br>
        <strong>Dirección:</strong> <?= htmlspecialchars($venta['direccion'] ?? 'No registrada') ?><br>
        <strong>Método de pago:</strong> <?= ucfirst(htmlspecialchars($venta['metodo_pago'])) ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th class="derecha">Cantidad</th>
                <th class="derecha">Precio Unit.</th>
                <th class="derecha">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detalles as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['producto']) ?></td>
                    <td class="derecha"><?= (int)$d['cantidad'] ?></td>
                    <td class="derecha">$<?= number_format((float)$d['precio_unitario'], 0, ',', '.') ?></td>
                    <td class="derecha">$<?= number_format((float)$d['subtotal'], 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="total-box">
        TOTAL PAGADO: $<?= number_format((float)$venta['total'], 0, ',', '.') ?> COP
    </div>

    <div class="acciones">
        <button type="button" onclick="window.print()" class="btn btn-imprimir">Imprimir / Descargar PDF</button>
        <button type="button" onclick="window.close()" class="btn btn-cerrar">Cerrar ventana</button>
    </div>
</div>

</body>
</html>
