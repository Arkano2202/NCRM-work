<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/monitoreo_dia.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";

requireLogin();
requirePermission("tiempos");

date_default_timezone_set('America/Bogota');

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$usuario = trim((string) ($_SESSION["usuario"] ?? ""));
$mensaje = "";
$error = "";

if (!esAgenteTipo($tipo) || $usuario === "") {
    http_response_code(403);
    exit("Acceso denegado");
}

$fechaHoy = date('Y-m-d');
$horaAhora = date('H:i:s');
$estadosDisponibles = estadosMonitoreoDisponibles();

function diferenciaSegundosHoy(string $horaInicio, string $horaFin): int
{
    $inicio = DateTime::createFromFormat('Y-m-d H:i:s', date('Y-m-d') . ' ' . $horaInicio, new DateTimeZone('America/Bogota'));
    $fin = DateTime::createFromFormat('Y-m-d H:i:s', date('Y-m-d') . ' ' . $horaFin, new DateTimeZone('America/Bogota'));

    if (!$inicio || !$fin) {
        return 0;
    }

    return max(0, $fin->getTimestamp() - $inicio->getTimestamp());
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";
    $registroHoy = obtenerRegistroMonitoreoDia($conn, $usuario, $fechaHoy);

    if ($accion === "iniciar") {
        if ($registroHoy !== null) {
            $error = "Ya existe un registro de hoy para este usuario.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO monitoreo (usuario, fecha, h_entrada, h_salida, h_referencia, almuerzo, descanso, formacion, bano, estado)
                VALUES (?, ?, ?, '0', '0', '0', '0', '0', '0', 'Puesto')
            ");

            if ($stmt) {
                $stmt->bind_param("sss", $usuario, $fechaHoy, $horaAhora);
                $stmt->execute();
                $stmt->close();
                $mensaje = "Dia iniciado correctamente.";
            } else {
                $error = "No fue posible iniciar el dia.";
            }
        }
    }

    if ($accion === "finalizar") {
        if ($registroHoy === null) {
            $error = "Aun no has iniciado el dia.";
        } elseif (($registroHoy["h_salida"] ?? "0") !== "0") {
            $error = "Tu jornada ya fue finalizada.";
        } else {
            $stmt = $conn->prepare("UPDATE monitoreo SET h_salida = ?, h_referencia = '0', estado = 'Puesto' WHERE id = ?");
            if ($stmt) {
                $registroId = (int) $registroHoy["id"];
                $stmt->bind_param("si", $horaAhora, $registroId);
                $stmt->execute();
                $stmt->close();
                $mensaje = "Jornada finalizada correctamente.";
            } else {
                $error = "No fue posible finalizar el dia.";
            }
        }
    }

    if ($accion === "iniciar_estado") {
        $estadoSeleccionado = trim((string) ($_POST["estado_control"] ?? ""));

        if ($registroHoy === null) {
            $error = "Debes iniciar el dia antes de usar estados.";
        } elseif (($registroHoy["h_salida"] ?? "0") !== "0") {
            $error = "Tu jornada ya fue finalizada.";
        } elseif (($registroHoy["estado"] ?? "Puesto") !== "Puesto" && ($registroHoy["estado"] ?? "0") !== "0") {
            $error = "Ya tienes un estado activo. Debes detenerlo antes de iniciar otro.";
        } elseif (!isset($estadosDisponibles[$estadoSeleccionado])) {
            $error = "Estado no valido.";
        } else {
            $campoObjetivo = $estadosDisponibles[$estadoSeleccionado];
            if ($campoObjetivo === 'almuerzo' && tiempoTextoASegundos((string) ($registroHoy['almuerzo'] ?? '0')) > 0) {
                $error = "Almuerzo solo se puede activar una vez por dia.";
            } else {
                $stmt = $conn->prepare("UPDATE monitoreo SET h_referencia = ?, estado = ? WHERE id = ?");
                if ($stmt) {
                    $registroId = (int) $registroHoy["id"];
                    $stmt->bind_param("ssi", $horaAhora, $estadoSeleccionado, $registroId);
                    $stmt->execute();
                    $stmt->close();
                    $mensaje = "Estado " . $estadoSeleccionado . " iniciado.";
                } else {
                    $error = "No fue posible iniciar el estado.";
                }
            }
        }
    }

    if ($accion === "detener_estado") {
        if ($registroHoy === null) {
            $error = "No existe jornada iniciada para hoy.";
        } else {
            $estadoActivo = trim((string) ($registroHoy["estado"] ?? "Puesto"));
            $hReferencia = trim((string) ($registroHoy["h_referencia"] ?? "0"));

            if ($estadoActivo === '' || $estadoActivo === '0' || $estadoActivo === 'Puesto') {
                $error = "No hay un estado activo para detener.";
            } elseif ($hReferencia === '' || $hReferencia === '0') {
                $error = "No hay hora de referencia valida para este estado.";
            } elseif (!isset($estadosDisponibles[$estadoActivo])) {
                $error = "El estado activo no es valido.";
            } else {
                $campoObjetivo = $estadosDisponibles[$estadoActivo];
                $segundosActuales = tiempoTextoASegundos((string) ($registroHoy[$campoObjetivo] ?? '0'));
                $segundosNuevos = diferenciaSegundosHoy($hReferencia, $horaAhora);
                $acumulado = segundosATiempoTexto($segundosActuales + $segundosNuevos);
                $registroId = (int) $registroHoy["id"];

                $sql = "UPDATE monitoreo SET {$campoObjetivo} = ?, h_referencia = '0', estado = 'Puesto' WHERE id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("si", $acumulado, $registroId);
                    $stmt->execute();
                    $stmt->close();
                    $mensaje = "Estado " . $estadoActivo . " detenido y acumulado correctamente.";
                } else {
                    $error = "No fue posible detener el estado.";
                }
            }
        }
    }
}

$registroHoy = obtenerRegistroMonitoreoDia($conn, $usuario, $fechaHoy);
$yaInicio = $registroHoy !== null;
$yaFinalizo = $yaInicio && (($registroHoy["h_salida"] ?? "0") !== "0");
$estadoActual = trim((string) ($registroHoy["estado"] ?? "0"));
$estadoActual = $estadoActual === '' || $estadoActual === '0' ? 'Puesto' : $estadoActual;
$estadoActivo = $yaInicio && !$yaFinalizo && $estadoActual !== 'Puesto';
$segundosCronometro = 0;

if ($estadoActivo) {
    $segundosCronometro = diferenciaSegundosHoy((string) ($registroHoy["h_referencia"] ?? "0"), date('H:i:s'));
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t("times.title")) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.times-shell {
    display: grid;
    gap: 18px;
}

.times-hero,
.times-card {
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.74);
    border: 1px solid rgba(31, 41, 51, 0.08);
}

.times-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}

.times-hero p,
.times-meta,
.times-status-note {
    color: var(--muted);
}

.times-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    border-radius: 999px;
    font-weight: 700;
}

.times-status.active {
    background: rgba(31, 143, 98, 0.12);
    color: #16734f;
}

.times-status.done {
    background: rgba(43, 60, 78, 0.12);
    color: #334155;
}

.times-status.pending {
    background: rgba(182, 70, 47, 0.12);
    color: var(--brand-dark);
}

.times-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.times-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-top: 18px;
}

.times-meta strong {
    display: block;
    margin-bottom: 4px;
    color: #253242;
}

.times-control-grid {
    display: grid;
    grid-template-columns: minmax(220px, 320px) auto;
    gap: 14px;
    align-items: end;
    margin-top: 18px;
}

.times-timer {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 140px;
    padding: 12px 18px;
    border-radius: 18px;
    background: rgba(15, 118, 110, 0.12);
    color: #0d5f59;
    font-size: 1.25rem;
    font-weight: 700;
}

@media (max-width: 760px) {
    .times-control-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="times-shell">
    <section class="times-hero">
        <span class="times-kicker"><?= htmlspecialchars(t("times.kicker")) ?></span>
        <h1><?= htmlspecialchars(t("times.title")) ?></h1>
        <p><?= htmlspecialchars(t("times.subtitle")) ?></p>
    </section>

    <?php if ($mensaje !== ""): ?>
        <div class="alert-success"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <section class="times-card">
        <?php if (!$yaInicio): ?>
            <span class="times-status pending"><?= htmlspecialchars(t("times.pending")) ?></span>
            <p class="times-status-note" style="margin-top: 12px;"><?= htmlspecialchars(t("times.pending_help")) ?></p>
            <form method="POST" class="times-actions">
                <input type="hidden" name="accion" value="iniciar">
                <button type="submit" class="btn-primary"><?= htmlspecialchars(t("times.start_day")) ?></button>
            </form>
        <?php elseif ($yaFinalizo): ?>
            <span class="times-status done"><?= htmlspecialchars(t("times.finished")) ?></span>
            <p class="times-status-note" style="margin-top: 12px;"><?= htmlspecialchars(t("times.finished_help")) ?></p>
        <?php else: ?>
            <span class="times-status active"><?= htmlspecialchars(t("times.started")) ?></span>
            <p class="times-status-note" style="margin-top: 12px;"><?= htmlspecialchars(t("times.started_help")) ?></p>
            <form method="POST" class="times-actions" onsubmit="return confirmarFinalizarJornada();">
                <input type="hidden" name="accion" value="finalizar">
                <button type="submit" class="btn-danger"><?= htmlspecialchars(t("times.end_day")) ?></button>
            </form>

            <div class="times-control-grid">
                <form method="POST">
                    <div class="form-group">
                        <label><?= htmlspecialchars(t("times.operational_state")) ?></label>
                        <select name="estado_control" <?= $estadoActivo ? 'disabled' : '' ?>>
                            <?php foreach ($estadosDisponibles as $label => $campo): ?>
                                <?php
                                $almuerzoUsado = $campo === 'almuerzo' && tiempoTextoASegundos((string) ($registroHoy['almuerzo'] ?? '0')) > 0;
                                $selected = $estadoActivo && $estadoActual === $label;
                                ?>
                                <option value="<?= htmlspecialchars($label) ?>" <?= $selected ? 'selected' : '' ?> <?= $almuerzoUsado && !$selected ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($label) ?><?= $almuerzoUsado && !$selected ? ' (' . htmlspecialchars(t("times.already_used")) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($estadoActivo): ?>
                        <input type="hidden" name="accion" value="detener_estado">
                        <button type="submit" class="btn-danger"><?= htmlspecialchars(t("common.stop")) ?></button>
                    <?php else: ?>
                        <input type="hidden" name="accion" value="iniciar_estado">
                        <button type="submit" class="btn-primary"><?= htmlspecialchars(t("common.start")) ?></button>
                    <?php endif; ?>
                </form>

                <div>
                    <strong style="display:block; margin-bottom:8px; color:#253242;"><?= htmlspecialchars(t("times.timer")) ?></strong>
                    <div id="times-timer" class="times-timer"><?= htmlspecialchars(segundosATiempoTexto($segundosCronometro)) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="times-grid">
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("common.user")) ?></strong>
                <span><?= htmlspecialchars($usuario) ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("common.date")) ?></strong>
                <span><?= htmlspecialchars($fechaHoy) ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("times.entry_time")) ?></strong>
                <span><?= htmlspecialchars($registroHoy["h_entrada"] ?? "0") ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("times.exit_time")) ?></strong>
                <span><?= htmlspecialchars($registroHoy["h_salida"] ?? "0") ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("times.current_state")) ?></strong>
                <span><?= htmlspecialchars($estadoActual) ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("times.reference_time")) ?></strong>
                <span><?= htmlspecialchars($registroHoy["h_referencia"] ?? "0") ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("common.lunch")) ?></strong>
                <span><?= htmlspecialchars($registroHoy["almuerzo"] ?? "0") ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("common.break")) ?></strong>
                <span><?= htmlspecialchars($registroHoy["descanso"] ?? "0") ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("common.training")) ?></strong>
                <span><?= htmlspecialchars($registroHoy["formacion"] ?? "0") ?></span>
            </div>
            <div class="times-meta">
                <strong><?= htmlspecialchars(t("common.bathroom")) ?></strong>
                <span><?= htmlspecialchars($registroHoy["bano"] ?? "0") ?></span>
            </div>
        </div>
    </section>
</div>
</div>
</div>

<?php if ($estadoActivo): ?>
<script>
let segundosCronometro = <?= (int) $segundosCronometro ?>;
const timerElement = document.getElementById("times-timer");

function formatearSegundos(segundos) {
    const horas = Math.floor(segundos / 3600).toString().padStart(2, "0");
    const minutos = Math.floor((segundos % 3600) / 60).toString().padStart(2, "0");
    const resto = (segundos % 60).toString().padStart(2, "0");
    return `${horas}:${minutos}:${resto}`;
}

window.setInterval(() => {
    segundosCronometro += 1;
    if (timerElement) {
        timerElement.textContent = formatearSegundos(segundosCronometro);
    }
}, 1000);
</script>
<?php endif; ?>

<script>
function confirmarFinalizarJornada() {
    return window.confirm("¿Estas realmente seguro de finalizar la jornada? Si finalizas no podras seguir marcando los leads.");
}
</script>

</body>
</html>
