<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/novedades.php";
require_once dirname(__DIR__, 2) . "/core/cdr_tm.php";

requireLogin();
requirePermission("novedades_aprobar");

date_default_timezone_set('America/Bogota');

$usuarioSesion = trim((string) ($_SESSION["usuario"] ?? ""));
$fechaHoraBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');
$msg = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = trim((string) ($_POST["accion"] ?? ""));

    if ($accion === "procesar_cdr") {
        $fechaInicio = trim((string) ($_POST["fecha_inicio"] ?? ""));
        $fechaFin = trim((string) ($_POST["fecha_fin"] ?? ""));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
            $error = "Debes seleccionar una fecha de inicio y fin validas.";
        } elseif ($fechaInicio > $fechaFin) {
            $error = "La fecha de inicio no puede ser mayor a la fecha final.";
        } else {
            try {
                $metaCarga = cdrCargarCsvEnTabla($conn, $_FILES["cdr_file"] ?? [], $fechaInicio, $fechaFin);
                $filasReporte = cdrObtenerFilasReporteTm($conn, $fechaInicio, $fechaFin);
                cdrDescargarReporteTm($fechaInicio, $fechaFin, $filasReporte, $metaCarga);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } else {
        $ids = array_values(array_unique(array_map("intval", $_POST["novedades"] ?? [])));
        $ids = array_filter($ids, static fn ($id) => $id > 0);

        if (!in_array($accion, ["aprobar", "cancelar"], true)) {
            $error = "La accion seleccionada no es valida.";
        } elseif (empty($ids)) {
            $error = "Selecciona al menos una novedad.";
        } else {
            $idsSql = implode(",", array_map("intval", $ids));
            $res = $conn->query("SELECT * FROM novedades WHERE estado = 'Pendiente' AND id IN ($idsSql)");

            if (!$res) {
                $error = "No fue posible consultar las novedades pendientes.";
            } else {
                $pendientesSeleccionadas = [];
                while ($row = $res->fetch_assoc()) {
                    $pendientesSeleccionadas[] = $row;
                }

                if (empty($pendientesSeleccionadas)) {
                    $error = "No se encontraron novedades pendientes para procesar.";
                } else {
                    $conn->begin_transaction();
                    try {
                        $registrosMonitoreoAfectados = 0;

                        if ($accion === "aprobar") {
                            foreach ($pendientesSeleccionadas as $novedad) {
                                $campoDestino = campoTiempoNovedadPorDescripcion((string) ($novedad["descripcion"] ?? ""));
                                $usuariosAfectados = usuariosAfectadosPorNovedad($conn, $novedad);
                                $fechaNovedad = (string) ($novedad["fecha_novedad"] ?? "");
                                $tiempoNovedad = (string) ($novedad["tiempo_novedad"] ?? "00:00:00");

                                foreach ($usuariosAfectados as $usuarioAfectado) {
                                    if (sumarTiempoNovedadMonitoreo($conn, $usuarioAfectado, $fechaNovedad, $campoDestino, $tiempoNovedad)) {
                                        $registrosMonitoreoAfectados++;
                                    }
                                }
                            }
                        }

                        $estadoFinal = $accion === "aprobar" ? "Aprobada" : "Rechazada";
                        $stmtUpdate = $conn->prepare("UPDATE novedades SET estado = ?, aprobado_por = ?, fecha_aprobacion = ? WHERE estado = 'Pendiente' AND id IN ($idsSql)");
                        if (!$stmtUpdate) {
                            throw new RuntimeException("No fue posible preparar la actualizacion final.");
                        }

                        $stmtUpdate->bind_param("sss", $estadoFinal, $usuarioSesion, $fechaHoraBogota);
                        if (!$stmtUpdate->execute()) {
                            throw new RuntimeException("No fue posible actualizar el estado de las novedades.");
                        }
                        $stmtUpdate->close();

                        $conn->commit();

                        if ($accion === "aprobar") {
                            $msg = count($pendientesSeleccionadas) . " novedad(es) aprobada(s). Registros de monitoreo actualizados: " . $registrosMonitoreoAfectados . ".";
                        } else {
                            $msg = count($pendientesSeleccionadas) . " novedad(es) rechazada(s) correctamente.";
                        }
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = "No fue posible procesar las novedades: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

$pendientes = [];
$resPendientes = $conn->query("SELECT * FROM novedades WHERE estado = 'Pendiente' ORDER BY fecha_novedad DESC, fecha_creacion DESC");
if ($resPendientes) {
    while ($row = $resPendientes->fetch_assoc()) {
        $pendientes[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Aprobar Novedades</title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.approve-shell { display:grid; gap:18px; }
.approve-hero,
.approve-card {
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.74);
    border: 1px solid rgba(31, 41, 51, 0.08);
}
.approve-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}
.approve-hero p,
.approve-note,
.modal-head p { color: var(--muted); }
.approve-hero-head {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    flex-wrap:wrap;
}
.approve-actions {
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom: 16px;
}
.approve-table td:nth-child(7) {
    max-width: 360px;
    white-space: normal;
}
.modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 40;
    background: rgba(15, 23, 42, 0.48);
    padding: 28px;
}
.modal-box {
    width: min(520px, 100%);
    margin: 6vh auto 0;
    padding: 24px;
    border-radius: 28px;
    background: rgba(255,255,255,0.96);
    border: 1px solid rgba(31,41,51,0.08);
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
}
.modal-head {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:14px;
    margin-bottom:16px;
}
.modal-actions {
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    margin-top: 18px;
}
@media (max-width: 720px) {
    .modal {
        padding: 14px;
    }
    .modal-box {
        margin-top: 2vh;
    }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="approve-shell">
    <section class="approve-hero">
        <div class="approve-hero-head">
            <div>
                <span class="approve-kicker">Revision administrativa</span>
                <h1>Aprobar Novedades</h1>
                <p>Desde aqui solo se trabajan las novedades pendientes. Al aprobar, se aplica el tiempo sobre <code>monitoreo.t_novedad</code> o <code>monitoreo.t_novedad_a</code> segun la descripcion.</p>
            </div>
            <button type="button" class="btn-filter" onclick="openCdrModal()">Subir Archivo CDR</button>
        </div>
    </section>

    <?php if ($msg !== ""): ?>
        <div class="alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <section class="approve-card">
        <h3>Pendientes</h3>
        <p class="approve-note">Solo se muestran las solicitudes en espera. Las aprobadas o canceladas quedaran para el informe posterior.</p>

        <?php if (!empty($pendientes)): ?>
            <form method="POST" onsubmit="return validarSeleccion();">
                <div class="approve-actions">
                    <button type="submit" name="accion" value="aprobar" class="btn-primary">Aprobar seleccionadas</button>
                    <button type="submit" name="accion" value="cancelar" class="btn-danger">Cancelar seleccionadas</button>
                </div>

                <div class="table-container">
                    <table class="leads-table approve-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                                <th>ID</th>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th>Alcance</th>
                                <th>Objetivo</th>
                                <th>Descripcion</th>
                                <th>Tiempo</th>
                                <th>Creado por</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendientes as $fila): ?>
                            <tr>
                                <td><input type="checkbox" name="novedades[]" value="<?= (int) $fila["id"] ?>"></td>
                                <td><?= (int) $fila["id"] ?></td>
                                <td><?= htmlspecialchars((string) ($fila["fecha_novedad"] ?? "")) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["tipo_novedad"] ?? "")) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["alcance"] ?? "")) ?></td>
                                <td><?= htmlspecialchars(etiquetaObjetivoNovedad($fila, $conn)) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["descripcion"] ?? "")) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["tiempo_novedad"] ?? "")) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["creado_por"] ?? "")) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php else: ?>
            <div class="alert">No hay novedades pendientes por aprobar.</div>
        <?php endif; ?>
    </section>
</div>
</div>
</div>

<div id="cdrModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>Subir Archivo CDR</h3>
                <p>Carga el CSV de llamadas, actualiza <code>cdr_report</code> y descarga el reporte TM desde este mismo CRM.</p>
            </div>
            <button type="button" class="btn-clear" onclick="closeCdrModal()">Cerrar</button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="cdrUploadForm">
            <input type="hidden" name="accion" value="procesar_cdr">

            <div class="form-group">
                <label>Fecha Inicio</label>
                <input type="date" name="fecha_inicio" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
            </div>

            <div class="form-group">
                <label>Fecha Fin</label>
                <input type="date" name="fecha_fin" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
            </div>

            <div class="form-group">
                <label>Archivo CSV</label>
                <input type="file" name="cdr_file" id="cdr_file" accept=".csv" required>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn-primary">Procesar y descargar reporte</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAll(source) {
    document.querySelectorAll('input[name="novedades[]"]').forEach((checkbox) => {
        checkbox.checked = source.checked;
    });
}

function validarSeleccion() {
    const seleccionadas = document.querySelectorAll('input[name="novedades[]"]:checked').length;
    if (seleccionadas === 0) {
        alert("Selecciona al menos una novedad.");
        return false;
    }
    return true;
}

function openCdrModal() {
    document.getElementById("cdrModal").style.display = "block";
}

function closeCdrModal() {
    document.getElementById("cdrModal").style.display = "none";
}

document.getElementById("cdrUploadForm").addEventListener("submit", function (event) {
    const fileInput = document.getElementById("cdr_file");
    if (!fileInput.value) {
        event.preventDefault();
        alert("Debes seleccionar un archivo CSV.");
        return;
    }

    if (!/(\.csv)$/i.test(fileInput.value)) {
        event.preventDefault();
        alert("Solo se permiten archivos con extension .csv");
    }
});

window.addEventListener("click", function (event) {
    const modal = document.getElementById("cdrModal");
    if (event.target === modal) {
        closeCdrModal();
    }
});
</script>

</body>
</html>
