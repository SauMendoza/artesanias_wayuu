<?php
require_once 'includes/verificar_sesion.php';
require_once 'config/conexion.php';

$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin    = $_GET['fecha_fin'] ?? '';
$idArtesanoProc = filter_input(INPUT_GET, 'id_artesano', FILTER_VALIDATE_INT);

$condicionFecha = "";
$params = [];

if ($fechaInicio !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) {
    $condicionFecha .= " AND DATE(v.fecha_venta) >= :fecha_inicio";
    $params[':fecha_inicio'] = $fechaInicio;
}
if ($fechaFin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
    $condicionFecha .= " AND DATE(v.fecha_venta) <= :fecha_fin";
    $params[':fecha_fin'] = $fechaFin;
}

$resumen = ['total_ventas' => 0, 'ingresos' => 0, 'ticket_promedio' => 0];
$porMetodo = [];
$topProductos = [];
$porArtesano = [];
$porCategoriaCte = [];
$listaArtesanos = [];
$detalleArtesanoSP = null;

try {
    $listaArtesanos = $conexion->query("SELECT id_artesano, CONCAT(nombre, ' ', apellido) AS nombre FROM artesano ORDER BY nombre ASC")->fetchAll();

    // 1. Resumen general
    $stmtRes = $conexion->prepare("SELECT COUNT(v.id_venta) AS total_ventas, COALESCE(SUM(v.total), 0) AS ingresos, COALESCE(AVG(v.total), 0) AS ticket_promedio FROM venta v WHERE 1=1 $condicionFecha");
    $stmtRes->execute($params);
    $filaRes = $stmtRes->fetch();
    if ($filaRes) $resumen = $filaRes;

    // 2. Por método de pago
    $stmtMet = $conexion->prepare("SELECT v.metodo_pago, COUNT(v.id_venta) AS cantidad, COALESCE(SUM(v.total), 0) AS total FROM venta v WHERE 1=1 $condicionFecha GROUP BY v.metodo_pago ORDER BY total DESC");
    $stmtMet->execute($params);
    $porMetodo = $stmtMet->fetchAll();

    // 3. Productos más vendidos
    $stmtProd = $conexion->prepare("
        SELECT p.nombre AS producto, c.nombre AS categoria, SUM(dp.cantidad) AS unidades_vendidas, SUM(dp.subtotal) AS total_generado
        FROM venta v
        INNER JOIN pedido ped ON v.id_pedido = ped.id_pedido
        INNER JOIN detalle_pedido dp ON ped.id_pedido = dp.id_pedido
        INNER JOIN producto p ON dp.id_producto = p.id_producto
        INNER JOIN categoria c ON p.id_categoria = c.id_categoria
        WHERE 1=1 $condicionFecha
        GROUP BY p.id_producto, p.nombre, c.nombre
        ORDER BY unidades_vendidas DESC, total_generado DESC
    ");
    $stmtProd->execute($params);
    $topProductos = $stmtProd->fetchAll();

    // 4. Ventas por artesano
    $stmtArt = $conexion->prepare("
        SELECT CONCAT(a.nombre, ' ', a.apellido) AS artesano, COALESCE(a.comunidad, '-') AS comunidad, SUM(dp.cantidad) AS piezas_vendidas, SUM(dp.subtotal) AS total_ventas
        FROM venta v
        INNER JOIN pedido ped ON v.id_pedido = ped.id_pedido
        INNER JOIN detalle_pedido dp ON ped.id_pedido = dp.id_pedido
        INNER JOIN producto p ON dp.id_producto = p.id_producto
        INNER JOIN artesano a ON p.id_artesano = a.id_artesano
        WHERE 1=1 $condicionFecha
        GROUP BY a.id_artesano, a.nombre, a.apellido, a.comunidad
        ORDER BY total_ventas DESC
    ");
    $stmtArt->execute($params);
    $porArtesano = $stmtArt->fetchAll();

    // 5. Consulta con CTE (Participación porcentual por categoría)
    $sqlCte = "WITH VentasPorCategoria AS (
                   SELECT c.id_categoria, c.nombre AS categoria, SUM(dp.cantidad) AS unidades, SUM(dp.subtotal) AS ingresos
                   FROM venta v
                   INNER JOIN pedido ped ON v.id_pedido = ped.id_pedido
                   INNER JOIN detalle_pedido dp ON ped.id_pedido = dp.id_pedido
                   INNER JOIN producto p ON dp.id_producto = p.id_producto
                   INNER JOIN categoria c ON p.id_categoria = c.id_categoria
                   WHERE 1=1 $condicionFecha
                   GROUP BY c.id_categoria, c.nombre
               ),
               TotalGeneral AS (
                   SELECT COALESCE(SUM(ingresos), 0) AS gran_total FROM VentasPorCategoria
               )
               SELECT vc.categoria, vc.unidades, vc.ingresos,
                      CASE WHEN tg.gran_total > 0 THEN ROUND((vc.ingresos / tg.gran_total) * 100, 2) ELSE 0 END AS porcentaje
               FROM VentasPorCategoria vc
               CROSS JOIN TotalGeneral tg
               ORDER BY vc.ingresos DESC";
    $stmtCte = $conexion->prepare($sqlCte);
    $stmtCte->execute($params);
    $porCategoriaCte = $stmtCte->fetchAll();

    // 6. Consulta individual mediante Procedimiento Almacenado (CALL sp_reporte_artesano)
    if ($idArtesanoProc && $idArtesanoProc > 0) {
        $fIniSP = $fechaInicio !== '' ? $fechaInicio : '2000-01-01';
        $fFinSP = $fechaFin !== '' ? $fechaFin : '2099-12-31';
        $stmtSP = $conexion->prepare("CALL sp_reporte_artesano(:id_art, :f_ini, :f_fin)");
        $stmtSP->execute([':id_art' => $idArtesanoProc, ':f_ini' => $fIniSP, ':f_fin' => $fFinSP]);
        $detalleArtesanoSP = $stmtSP->fetch();
        $stmtSP->closeCursor();
    }

} catch (PDOException $e) {
    error_log("Error al generar reportes: " . $e->getMessage());
}

$labelsMetodo = []; $datosMetodo = [];
foreach ($porMetodo as $m) { $labelsMetodo[] = ucfirst($m['metodo_pago']); $datosMetodo[] = (float)$m['total']; }

$labelsProd = []; $datosProd = [];
foreach ($topProductos as $p) { $labelsProd[] = $p['producto']; $datosProd[] = (int)$p['unidades_vendidas']; }

$labelsArt = []; $datosArt = [];
foreach ($porArtesano as $a) { $labelsArt[] = $a['artesano']; $datosArt[] = (float)$a['total_ventas']; }

$tituloPagina = 'Reportes - Artesanías Wayúu';
require_once 'includes/header.php';
?>
<div class="contenedor">
    <div class="barra-superior">
        <h2>Reportes Comerciales y Estadísticas</h2>
        <button type="button" onclick="window.print()" class="btn btn-editar no-print">Imprimir reporte</button>
    </div>

    <form method="GET" action="reportes.php" class="filtros">
        <div>
            <label for="fecha_inicio" style="font-size: 13px; font-weight: bold; color: #753b24;">Desde:</label>
            <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>">
        </div>
        <div>
            <label for="fecha_fin" style="font-size: 13px; font-weight: bold; color: #753b24;">Hasta:</label>
            <input type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>">
        </div>
        <div>
            <label for="id_artesano" style="font-size: 13px; font-weight: bold; color: #753b24;">Consultar Artesano (SP):</label>
            <select id="id_artesano" name="id_artesano">
                <option value="">-- Todos --</option>
                <?php foreach ($listaArtesanos as $la): ?>
                    <option value="<?= (int)$la['id_artesano'] ?>" <?= (int)$idArtesanoProc === (int)$la['id_artesano'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($la['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-buscar">Generar</button>
        <?php if ($fechaInicio !== '' || $fechaFin !== '' || $idArtesanoProc): ?>
            <a href="reportes.php" class="btn btn-limpiar">Limpiar filtro</a>
        <?php endif; ?>
    </form>

    <?php if ($detalleArtesanoSP): ?>
        <div class="alerta alerta-exito" style="background: #fdfaf7; border-color: #753b24; color: #333;">
            <strong>Resultado Procedimiento Almacenado (<?= htmlspecialchars($detalleArtesanoSP['artesano']) ?>):</strong>
            Ventas participadas: <strong><?= (int)$detalleArtesanoSP['ventas_participadas'] ?></strong> |
            Piezas vendidas: <strong><?= (int)$detalleArtesanoSP['total_piezas_vendidas'] ?></strong> |
            Ingresos generados: <strong style="color: #27ae60;">$<?= number_format((float)$detalleArtesanoSP['ingresos_generados'], 0, ',', '.') ?></strong>
        </div>
    <?php endif; ?>

    <div class="resumen-grid">
        <div class="kpi-card"><span>Ventas Realizadas</span><strong><?= (int)$resumen['total_ventas'] ?></strong></div>
        <div class="kpi-card"><span>Ingresos Totales</span><strong style="color: #27ae60;">$<?= number_format((float)$resumen['ingresos'], 0, ',', '.') ?></strong></div>
        <div class="kpi-card"><span>Promedio por Venta</span><strong>$<?= number_format((float)$resumen['ticket_promedio'], 0, ',', '.') ?></strong></div>
    </div>

    <?php if (!empty($porMetodo) || !empty($topProductos) || !empty($porArtesano)): ?>
        <div class="graficas-grid">
            <div class="caja-grafica">
                <h4>Ingresos por Método de Pago (COP)</h4>
                <div class="canvas-wrapper"><canvas id="graficaMetodos"></canvas></div>
            </div>
            <div class="caja-grafica">
                <h4>Unidades Vendidas por Producto</h4>
                <div class="canvas-wrapper"><canvas id="graficaProductos"></canvas></div>
            </div>
            <div class="caja-grafica">
                <h4>Ingresos Generados por Artesano (COP)</h4>
                <div class="canvas-wrapper"><canvas id="graficaArtesanos"></canvas></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="seccion-reporte">
        <h3>1. Participación por Categoría (Consulta CTE)</h3>
        <table>
            <thead><tr><th>Categoría</th><th>Unidades Vendidas</th><th>Ingresos</th><th>Participación (%)</th></tr></thead>
            <tbody>
                <?php if (empty($porCategoriaCte)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 15px;">Sin datos para el período seleccionado.</td></tr>
                <?php else: ?>
                    <?php foreach ($porCategoriaCte as $catCte): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($catCte['categoria']) ?></strong></td>
                            <td><?= (int)$catCte['unidades'] ?></td>
                            <td>$<?= number_format((float)$catCte['ingresos'], 0, ',', '.') ?></td>
                            <td><span class="badge badge-ok"><?= number_format((float)$catCte['porcentaje'], 2) ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="seccion-reporte">
        <h3>2. Ventas por Método de Pago</h3>
        <table>
            <thead><tr><th>Método de Pago</th><th>Cantidad de Ventas</th><th>Total Recaudado</th></tr></thead>
            <tbody>
                <?php if (empty($porMetodo)): ?>
                    <tr><td colspan="3" style="text-align: center; padding: 15px;">Sin datos para el período seleccionado.</td></tr>
                <?php else: ?>
                    <?php foreach ($porMetodo as $m): ?>
                        <tr>
                            <td><?= ucfirst(htmlspecialchars($m['metodo_pago'])) ?></td>
                            <td><?= (int)$m['cantidad'] ?></td>
                            <td><strong>$<?= number_format((float)$m['total'], 0, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="seccion-reporte">
        <h3>3. Productos Más Vendidos</h3>
        <table>
            <thead><tr><th>Producto</th><th>Categoría</th><th>Unidades Vendidas</th><th>Total Generado</th></tr></thead>
            <tbody>
                <?php if (empty($topProductos)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 15px;">Sin datos para el período seleccionado.</td></tr>
                <?php else: ?>
                    <?php foreach ($topProductos as $tp): ?>
                        <tr>
                            <td><?= htmlspecialchars($tp['producto']) ?></td>
                            <td><?= htmlspecialchars($tp['categoria']) ?></td>
                            <td><?= (int)$tp['unidades_vendidas'] ?></td>
                            <td><strong>$<?= number_format((float)$tp['total_generado'], 0, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="seccion-reporte">
        <h3>4. Ventas por Artesano</h3>
        <table>
            <thead><tr><th>Artesano</th><th>Comunidad</th><th>Piezas Vendidas</th><th>Total Generado</th></tr></thead>
            <tbody>
                <?php if (empty($porArtesano)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 15px;">Sin datos para el período seleccionado.</td></tr>
                <?php else: ?>
                    <?php foreach ($porArtesano as $art): ?>
                        <tr>
                            <td><?= htmlspecialchars($art['artesano']) ?></td>
                            <td><?= htmlspecialchars($art['comunidad']) ?></td>
                            <td><?= (int)$art['piezas_vendidas'] ?></td>
                            <td><strong>$<?= number_format((float)$art['total_ventas'], 0, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.datosReportes = {
    labelsMetodo: <?= json_encode($labelsMetodo, JSON_UNESCAPED_UNICODE) ?>,
    datosMetodo:  <?= json_encode($datosMetodo) ?>,
    labelsProd:   <?= json_encode($labelsProd, JSON_UNESCAPED_UNICODE) ?>,
    datosProd:    <?= json_encode($datosProd) ?>,
    labelsArt:    <?= json_encode($labelsArt, JSON_UNESCAPED_UNICODE) ?>,
    datosArt:     <?= json_encode($datosArt) ?>
};
</script>
<?php require_once 'includes/footer.php'; ?>
