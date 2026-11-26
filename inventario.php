<?php
require_once 'includes/verificar_sesion.php';
require_once 'config/conexion.php';

$soloBajoStock = isset($_GET['bajo_stock']) && $_GET['bajo_stock'] === '1';

$sql = "SELECT i.id_inventario, i.id_producto, i.stock_actual, i.stock_minimo, i.fecha_actualizacion,
               p.nombre AS producto, c.nombre AS categoria,
               fn_valor_inventario_producto(i.id_producto) AS valor_bodega
        FROM inventario i
        INNER JOIN producto p ON i.id_producto = p.id_producto
        INNER JOIN categoria c ON p.id_categoria = c.id_categoria
        WHERE p.estado = 1";

if ($soloBajoStock) {
    $sql .= " AND i.stock_actual <= i.stock_minimo";
}
$sql .= " ORDER BY (i.stock_actual <= i.stock_minimo) DESC, p.nombre ASC";

try {
    $inventarios = $conexion->query($sql)->fetchAll();
} catch (PDOException $e) {
    error_log("Error al listar inventario: " . $e->getMessage());
    $inventarios = [];
}

// Consultar últimos movimientos registrados automáticamente por el Trigger
try {
    $auditoria = $conexion->query("
        SELECT a.fecha_cambio, p.nombre AS producto, a.stock_anterior, a.stock_nuevo, a.diferencia
        FROM auditoria_inventario a
        INNER JOIN producto p ON a.id_producto = p.id_producto
        ORDER BY a.id_auditoria DESC
        LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    error_log("Error al consultar auditoria: " . $e->getMessage());
    $auditoria = [];
}

$mensaje = $_GET['msg'] ?? '';
$tipo = $_GET['tipo'] ?? '';
$tituloPagina = 'Inventario - Artesanías Wayúu';
require_once 'includes/header.php';
?>

<div class="contenedor">
    <div class="barra-superior">
        <h2>Gestión de Inventario</h2>
        <div>
            <?php if ($soloBajoStock): ?>
                <a href="inventario.php" class="btn btn-limpiar">Ver todos</a>
            <?php else: ?>
                <a href="inventario.php?bajo_stock=1" class="btn btn-desactivar">Ver solo bajo stock</a>
            <?php endif; ?>
            <a href="movimiento_inventario.php" class="btn btn-nuevo">+ Registrar Movimiento</a>
        </div>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alerta <?= $tipo === 'exito' ? 'alerta-exito' : 'alerta-error' ?>">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th>Categoría</th>
                <th>Stock Actual</th>
                <th>Stock Mínimo</th>
                <th>Valor en Bodega</th>
                <th>Estado Stock</th>
                <th>Última Actualización</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inventarios)): ?>
                <tr><td colspan="8" style="text-align: center; padding: 20px;">No hay registros de inventario para mostrar.</td></tr>
            <?php else: ?>
                <?php foreach ($inventarios as $inv): 
                    $esBajo = (int)$inv['stock_actual'] <= (int)$inv['stock_minimo'];
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($inv['producto']) ?></strong></td>
                        <td><?= htmlspecialchars($inv['categoria']) ?></td>
                        <td><?= (int)$inv['stock_actual'] ?></td>
                        <td><?= (int)$inv['stock_minimo'] ?></td>
                        <td><strong>$<?= number_format((float)$inv['valor_bodega'], 0, ',', '.') ?></strong></td>
                        <td>
                            <?php if ($esBajo): ?>
                                <span class="badge badge-alerta">Bajo stock</span>
                            <?php else: ?>
                                <span class="badge badge-ok">Óptimo</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($inv['fecha_actualizacion']) ?></td>
                        <td class="acciones">
                            <a href="editar_stock_minimo.php?id=<?= (int)$inv['id_producto'] ?>" class="btn btn-editar">Stock mínimo</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="seccion-reporte">
        <h3>Últimos Cambios de Stock (Auditoría Automática por Trigger)</h3>
        <table>
            <thead>
                <tr>
                    <th>Fecha y Hora</th>
                    <th>Producto</th>
                    <th>Stock Anterior</th>
                    <th>Stock Nuevo</th>
                    <th>Variación</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($auditoria)): ?>
                    <tr><td colspan="5" style="text-align: center; padding: 15px;">Aún no hay movimientos registrados en la auditoría.</td></tr>
                <?php else: ?>
                    <?php foreach ($auditoria as $aud): 
                        $dif = (int)$aud['diferencia'];
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($aud['fecha_cambio']) ?></td>
                            <td><?= htmlspecialchars($aud['producto']) ?></td>
                            <td><?= (int)$aud['stock_anterior'] ?></td>
                            <td><?= (int)$aud['stock_nuevo'] ?></td>
                            <td>
                                <span class="badge <?= $dif >= 0 ? 'badge-ok' : 'badge-alerta' ?>">
                                    <?= $dif > 0 ? '+' . $dif : $dif ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
