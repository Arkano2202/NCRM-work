<?php
require_once dirname(__DIR__, 2) . "/core/session_config.php";
require_once dirname(__DIR__, 2) . "/core/auth.php";
require_once dirname(__DIR__, 2) . "/core/db.php";
require_once dirname(__DIR__, 2) . "/core/permissions.php";
require_once dirname(__DIR__, 2) . "/core/novedades.php";
require_once dirname(__DIR__, 2) . "/core/i18n.php";

requireLogin();
requirePermission("novedades");

date_default_timezone_set('America/Bogota');

$tipo = (int) ($_SESSION["tipo"] ?? 0);
$userId = (int) ($_SESSION["user_id"] ?? 0);
$usuarioSesion = trim((string) ($_SESSION["usuario"] ?? ""));
$pertenece = trim((string) ($_SESSION["pertenece"] ?? ""));
$rol = getRol($tipo);
$fechaHoyBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
$fechaHoraBogota = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d H:i:s');

$msg = "";
$error = "";

$agentesVisibles = usuariosVisiblesParaNovedades($conn, $tipo, $userId, $pertenece);
$agentesIndex = indiceUsuariosVisiblesPorUsuario($agentesVisibles);
$gruposDisponibles = opcionesGrupalesNovedades($conn, $tipo, $userId, $pertenece);

$form = [
    "tipo_novedad" => "",
    "fecha_novedad" => date('Y-m-d'),
    "alcance" => "individual",
    "usuario_objetivo" => "",
    "grupo_objetivo" => $gruposDisponibles[0]["value"] ?? "",
    "tiempo_novedad" => "00:00:00",
    "motivo" => "",
    "descripcion_otros" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form["tipo_novedad"] = trim((string) ($_POST["tipo_novedad"] ?? ""));
    $form["fecha_novedad"] = trim((string) ($_POST["fecha_novedad"] ?? date('Y-m-d')));
    $form["alcance"] = trim((string) ($_POST["alcance"] ?? "individual"));
    $form["usuario_objetivo"] = trim((string) ($_POST["usuario_objetivo"] ?? ""));
    $form["grupo_objetivo"] = trim((string) ($_POST["grupo_objetivo"] ?? ($gruposDisponibles[0]["value"] ?? "")));
    $form["motivo"] = trim((string) ($_POST["motivo"] ?? ""));
    $form["descripcion_otros"] = trim((string) ($_POST["descripcion_otros"] ?? ""));
    $form["tiempo_novedad"] = trim((string) ($_POST["tiempo_novedad"] ?? "00:00:00"));

    $descripcion = $form["motivo"] === "Otros" ? $form["descripcion_otros"] : $form["motivo"];
    $tiposValidos = opcionesTipoNovedad();
    $motivosValidos = opcionesMotivoNovedad();

    if (!in_array($form["tipo_novedad"], $tiposValidos, true)) {
        $error = "Selecciona un tipo de novedad valido.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form["fecha_novedad"])) {
        $error = "Selecciona una fecha valida.";
    } elseif (!in_array($form["motivo"], $motivosValidos, true)) {
        $error = "Selecciona un motivo valido.";
    } elseif ($descripcion === "") {
        $error = "Debes completar la descripcion de la novedad.";
    } elseif ($form["tiempo_novedad"] === "00:00:00") {
        $error = "Debes indicar un tiempo mayor a cero.";
    } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $form["tiempo_novedad"])) {
        $error = "El tiempo de la novedad no es valido.";
    } elseif ($form["alcance"] === "individual") {
        if (!isset($agentesIndex[$form["usuario_objetivo"]])) {
            $error = "El agente seleccionado no esta dentro de tu alcance.";
        }
    } elseif ($form["alcance"] === "grupo") {
        $grupoResolvido = resolverObjetivoGrupalNovedades($conn, $tipo, $userId, $pertenece, $form["grupo_objetivo"]);
        if ($grupoResolvido === null) {
            $error = "El objetivo grupal no es valido para tu perfil.";
        } else {
            $form["grupo_objetivo"] = $grupoResolvido;
        }
    } else {
        $error = "Selecciona un alcance valido.";
    }

    if ($error === "") {
        $usuarioObjetivo = $form["alcance"] === "individual" ? $form["usuario_objetivo"] : null;
        $grupoObjetivo = $form["alcance"] === "grupo" ? $form["grupo_objetivo"] : null;

        $stmt = $conn->prepare("
            INSERT INTO novedades (
                tipo_novedad, fecha_novedad, alcance, usuario_objetivo, grupo_objetivo,
                tiempo_novedad, descripcion, estado, creado_por, fecha_creacion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pendiente', ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param(
                "sssssssss",
                $form["tipo_novedad"],
                $form["fecha_novedad"],
                $form["alcance"],
                $usuarioObjetivo,
                $grupoObjetivo,
                $form["tiempo_novedad"],
                $descripcion,
                $usuarioSesion,
                $fechaHoraBogota
            );

            if ($stmt->execute()) {
                $msg = "Novedad registrada correctamente y enviada a aprobacion.";
                $form = [
                    "tipo_novedad" => "",
                    "fecha_novedad" => date('Y-m-d'),
                    "alcance" => "individual",
                    "usuario_objetivo" => "",
                    "grupo_objetivo" => $gruposDisponibles[0]["value"] ?? "",
                    "tiempo_novedad" => "00:00:00",
                    "motivo" => "",
                    "descripcion_otros" => "",
                ];
            } else {
                $error = "No fue posible guardar la novedad.";
            }
            $stmt->close();
        } else {
            $error = "No fue posible preparar el registro de la novedad.";
        }
    }
}

$misNovedades = [];
$stmtPendientes = $conn->prepare("
    SELECT id, tipo_novedad, fecha_novedad, alcance, usuario_objetivo, grupo_objetivo, tiempo_novedad, descripcion, estado, creado_por, fecha_creacion
    FROM novedades
    WHERE creado_por = ?
      AND fecha_novedad = ?
    ORDER BY fecha_creacion DESC
");
if ($stmtPendientes) {
    $stmtPendientes->bind_param("ss", $usuarioSesion, $fechaHoyBogota);
    $stmtPendientes->execute();
    $resPendientes = $stmtPendientes->get_result();
    while ($row = $resPendientes->fetch_assoc()) {
        $misNovedades[] = $row;
    }
    $stmtPendientes->close();
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(strtolower(appLanguage())) ?>">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(t("news.title")) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(appUrl('assets/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(appUrl('assets/css/dashboard.css')) ?>">
<style>
.novedades-shell { display:grid; gap:18px; }
.novedades-hero,
.novedades-card {
    padding: 22px 24px;
    border-radius: 28px;
    background: rgba(255, 255, 255, 0.74);
    border: 1px solid rgba(31, 41, 51, 0.08);
}
.novedades-kicker {
    font-size: 0.78rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}
.novedades-hero p,
.novedades-note,
.novedades-meta { color: var(--muted); }
.novedades-layout {
    display:grid;
    grid-template-columns: minmax(360px, 520px) minmax(0, 1fr);
    gap: 18px;
}
.novedades-form-grid {
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}
.novedades-form-grid .full { grid-column: 1 / -1; }
.novedades-time-grid {
    display:grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 120px;
    gap: 10px;
    align-items: end;
}
.novedades-time-display {
    display:flex;
    align-items:center;
    justify-content:center;
    min-height: 48px;
    border-radius: 16px;
    background: rgba(181, 85, 47, 0.12);
    color: var(--brand-dark);
    font-weight: 700;
}
.novedades-inline-note {
    margin-top: 8px;
    color: var(--muted);
    font-size: 0.95rem;
}
.novedades-actions {
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    margin-top: 18px;
}
.novedades-table-note {
    margin-bottom: 14px;
    color: var(--muted);
}
.novedades-history-wrap {
    max-height: 345px;
    overflow-y: auto;
    border-radius: 22px;
}
@media (max-width: 980px) {
    .novedades-layout { grid-template-columns: 1fr; }
}
@media (max-width: 760px) {
    .novedades-form-grid,
    .novedades-time-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<?php require_once dirname(__DIR__, 2) . "/views/partials/sidebar.php"; ?>

<div class="main">
<?php require_once dirname(__DIR__, 2) . "/views/partials/topbar.php"; ?>

<div class="content">
<div class="novedades-shell">
    <section class="novedades-hero">
        <span class="novedades-kicker"><?= htmlspecialchars(t("news.kicker")) ?></span>
        <h1><?= htmlspecialchars(t("news.title")) ?></h1>
        <p><?= htmlspecialchars(t("news.subtitle")) ?></p>
    </section>

    <?php if ($msg !== ""): ?>
        <div class="alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="novedades-layout">
        <section class="novedades-card">
            <h3><?= htmlspecialchars(t("news.register")) ?></h3>
            <p class="novedades-note"><?= htmlspecialchars(t("news.role_scope")) ?>: <strong><?= htmlspecialchars(strtoupper($rol)) ?></strong>. <?= htmlspecialchars(t("news.scope_help")) ?></p>

            <form method="POST" id="novedadForm">
                <div class="novedades-form-grid">
                    <div>
                        <label><?= htmlspecialchars(t("news.type")) ?></label>
                        <select name="tipo_novedad" required>
                            <option value=""><?= htmlspecialchars(t("common.select_option")) ?></option>
                            <?php foreach (opcionesTipoNovedad() as $tipoNovedad): ?>
                            <option value="<?= htmlspecialchars($tipoNovedad) ?>" <?= $form["tipo_novedad"] === $tipoNovedad ? "selected" : "" ?>>
                                <?= htmlspecialchars($tipoNovedad) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label><?= htmlspecialchars(t("news.date")) ?></label>
                        <input type="date" name="fecha_novedad" value="<?= htmlspecialchars($form["fecha_novedad"]) ?>" required>
                    </div>

                    <div>
                        <label><?= htmlspecialchars(t("news.scope")) ?></label>
                        <select name="alcance" id="alcance" onchange="syncNovedadScope()" required>
                            <option value="individual" <?= $form["alcance"] === "individual" ? "selected" : "" ?>><?= htmlspecialchars(t("news.scope.individual")) ?></option>
                            <option value="grupo" <?= $form["alcance"] === "grupo" ? "selected" : "" ?>><?= htmlspecialchars(t("news.scope.group")) ?></option>
                        </select>
                    </div>

                    <div id="individualBlock">
                        <label><?= htmlspecialchars(t("news.agent")) ?></label>
                        <select name="usuario_objetivo" id="usuario_objetivo">
                            <option value=""><?= htmlspecialchars(t("news.select_agent")) ?></option>
                            <?php foreach ($agentesVisibles as $agente): ?>
                            <option value="<?= htmlspecialchars((string) $agente["Usuario"]) ?>" <?= $form["usuario_objetivo"] === (string) $agente["Usuario"] ? "selected" : "" ?>>
                                <?= htmlspecialchars((string) $agente["Nombre"]) ?> · <?= htmlspecialchars((string) $agente["Usuario"]) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="groupBlock" class="<?= count($gruposDisponibles) === 1 ? "full" : "" ?>" style="display:none;">
                        <?php if (count($gruposDisponibles) > 1): ?>
                            <label><?= htmlspecialchars(t("news.group_target")) ?></label>
                            <select name="grupo_objetivo" id="grupo_objetivo">
                                <?php foreach ($gruposDisponibles as $opcion): ?>
                                <option value="<?= htmlspecialchars((string) $opcion["value"]) ?>" <?= $form["grupo_objetivo"] === (string) $opcion["value"] ? "selected" : "" ?>>
                                    <?= htmlspecialchars((string) $opcion["label"]) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <label><?= htmlspecialchars(t("news.group_target")) ?></label>
                            <div class="novedades-inline-note"><?= htmlspecialchars((string) ($gruposDisponibles[0]["label"] ?? "No disponible")) ?></div>
                            <input type="hidden" name="grupo_objetivo" value="<?= htmlspecialchars((string) ($gruposDisponibles[0]["value"] ?? "")) ?>">
                        <?php endif; ?>
                    </div>

                    <div class="full">
                        <label><?= htmlspecialchars(t("news.duration")) ?></label>
                        <div class="novedades-time-grid">
                            <select id="horas" onchange="actualizarTiempoNovedad()">
                                <?php for ($i = 0; $i <= 10; $i++): ?>
                                    <option value="<?= $i ?>" <?= substr($form["tiempo_novedad"], 0, 2) === str_pad((string) $i, 2, "0", STR_PAD_LEFT) ? "selected" : "" ?>>
                                        <?= str_pad((string) $i, 2, "0", STR_PAD_LEFT) ?>h
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <select id="minutos" onchange="actualizarTiempoNovedad()">
                                <?php for ($i = 0; $i < 60; $i++): ?>
                                    <option value="<?= $i ?>" <?= substr($form["tiempo_novedad"], 3, 2) === str_pad((string) $i, 2, "0", STR_PAD_LEFT) ? "selected" : "" ?>>
                                        <?= str_pad((string) $i, 2, "0", STR_PAD_LEFT) ?>m
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <div class="novedades-time-display" id="tiempoDisplay">00:00:00</div>
                        </div>
                        <input type="hidden" id="tiempo_novedad" name="tiempo_novedad" value="<?= htmlspecialchars($form["tiempo_novedad"]) ?>">
                    </div>

                    <div class="full">
                        <label><?= htmlspecialchars(t("news.reason")) ?></label>
                        <select id="motivo" name="motivo" onchange="syncDescripcionOtros()" required>
                            <option value=""><?= htmlspecialchars(t("news.select_reason")) ?></option>
                            <?php foreach (opcionesMotivoNovedad() as $motivo): ?>
                            <option value="<?= htmlspecialchars($motivo) ?>" <?= $form["motivo"] === $motivo ? "selected" : "" ?>>
                                <?= htmlspecialchars($motivo) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="full" id="otrosBlock" style="display:none;">
                        <label><?= htmlspecialchars(t("common.description")) ?></label>
                        <textarea name="descripcion_otros" id="descripcion_otros" placeholder="Describe el detalle especifico de la novedad..."><?= htmlspecialchars($form["descripcion_otros"]) ?></textarea>
                    </div>
                </div>

                <div class="novedades-actions">
                    <button type="submit" class="btn-primary"><?= htmlspecialchars(t("news.register_button")) ?></button>
                </div>
            </form>
        </section>

        <section class="novedades-card">
            <h3><?= htmlspecialchars(t("news.latest")) ?></h3>
            <p class="novedades-table-note"><?= htmlspecialchars(t("news.latest_help")) ?></p>

            <div class="table-container novedades-history-wrap">
                <table class="leads-table">
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars(t("common.date")) ?></th>
                            <th><?= htmlspecialchars(t("common.type")) ?></th>
                            <th><?= htmlspecialchars(t("common.scope")) ?></th>
                            <th><?= htmlspecialchars(t("common.target")) ?></th>
                            <th><?= htmlspecialchars(t("common.duration")) ?></th>
                            <th><?= htmlspecialchars(t("common.description")) ?></th>
                            <th><?= htmlspecialchars(t("common.status")) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($misNovedades)): ?>
                            <?php foreach ($misNovedades as $fila): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($fila["fecha_novedad"] ?? "")) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["tipo_novedad"] ?? "")) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["alcance"] ?? "")) ?></td>
                                <td><?= htmlspecialchars(etiquetaObjetivoNovedad($fila, $conn)) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["tiempo_novedad"] ?? "")) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["descripcion"] ?? "")) ?></td>
                                <td><?= htmlspecialchars((string) ($fila["estado"] ?? "")) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7"><?= htmlspecialchars(t("news.none")) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
</div>
</div>

<script>
function actualizarTiempoNovedad() {
    const horas = String(document.getElementById("horas").value).padStart(2, "0");
    const minutos = String(document.getElementById("minutos").value).padStart(2, "0");
    const valor = `${horas}:${minutos}:00`;
    document.getElementById("tiempo_novedad").value = valor;
    document.getElementById("tiempoDisplay").textContent = valor;
}

function syncDescripcionOtros() {
    const motivo = document.getElementById("motivo").value;
    const otrosBlock = document.getElementById("otrosBlock");
    const textarea = document.getElementById("descripcion_otros");
    const isOtros = motivo === "Otros";

    otrosBlock.style.display = isOtros ? "block" : "none";
    if (textarea) {
        textarea.required = isOtros;
        if (!isOtros) {
            textarea.value = "";
        }
    }
}

function syncNovedadScope() {
    const alcance = document.getElementById("alcance").value;
    const individualBlock = document.getElementById("individualBlock");
    const groupBlock = document.getElementById("groupBlock");
    const agenteSelect = document.getElementById("usuario_objetivo");

    const groupInput = document.getElementById("grupo_objetivo");
    const isIndividual = alcance === "individual";

    individualBlock.style.display = isIndividual ? "block" : "none";
    groupBlock.style.display = isIndividual ? "none" : "block";

    if (agenteSelect) {
        agenteSelect.required = isIndividual;
    }

    if (groupInput) {
        groupInput.required = !isIndividual;
    }
}

document.getElementById("novedadForm").addEventListener("submit", function (event) {
    if (document.getElementById("tiempo_novedad").value === "00:00:00") {
        event.preventDefault();
        alert("Debes indicar un tiempo mayor a cero.");
    }
});

actualizarTiempoNovedad();
syncDescripcionOtros();
syncNovedadScope();
</script>

</body>
</html>
