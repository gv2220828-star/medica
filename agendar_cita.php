<?php
session_start();
include 'db.php';

$role = strtolower(trim($_SESSION['rol'] ?? $_SESSION['role'] ?? ''));
$isPaciente = ($role === 'paciente');

$session_paciente_id = $_SESSION['paciente_id'] ?? null;
$session_user_id = $_SESSION['user_id'] ?? null;

$error = '';
$success = '';
$pacientes = [];
$doctores = [];

// Cargar doctores (para el select)
$res2 = $conn->query("SELECT id, nombre FROM usuarios WHERE rol = 'doctor' ORDER BY nombre");
if ($res2) $doctores = $res2->fetch_all(MYSQLI_ASSOC);

// Si no es paciente, cargar pacientes para el select
if (!$isPaciente) {
    $res = $conn->query("SELECT id, nombre FROM pacientes ORDER BY nombre");
    if ($res) $pacientes = $res->fetch_all(MYSQLI_ASSOC);
}

// manejar POST (guardar cita)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_paciente_id = intval($_POST['paciente_id'] ?? 0);
    $doctor_id = intval($_POST['doctor_id'] ?? 0);
    $fecha = trim($_POST['fecha'] ?? '');
    $hora = trim($_POST['hora'] ?? '');
    $notas = trim($_POST['notas'] ?? '');

    if ($isPaciente) {
        $paciente_id = null;

        // 1) Si la sesión trae paciente_id, verificar que exista en pacientes
        if (!empty($session_paciente_id)) {
            $chk = $conn->prepare("SELECT id FROM pacientes WHERE id = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('i', $session_paciente_id);
                $chk->execute();
                $rchk = $chk->get_result();
                if ($rchk && $rchk->num_rows) {
                    $paciente_id = (int)$session_paciente_id;
                }
                $chk->close();
            }
        }

        // 2) Si no se resolvió, intentar obtener paciente_id desde usuarios.paciente_id
        if (empty($paciente_id) && !empty($session_user_id)) {
            $q = $conn->prepare("SELECT paciente_id FROM usuarios WHERE id = ? LIMIT 1");
            if ($q) {
                $q->bind_param('i', $session_user_id);
                $q->execute();
                $rq = $q->get_result();
                if ($rq && $row = $rq->fetch_assoc()) {
                    $maybe = intval($row['paciente_id']);
                    if ($maybe > 0) {
                        $chk2 = $conn->prepare("SELECT id FROM pacientes WHERE id = ? LIMIT 1");
                        if ($chk2) {
                            $chk2->bind_param('i', $maybe);
                            $chk2->execute();
                            $rchk2 = $chk2->get_result();
                            if ($rchk2 && $rchk2->num_rows) {
                                $paciente_id = $maybe;
                            }
                            $chk2->close();
                        }
                    }
                }
                $q->close();
            }
        }

        // Nota: no hay columna pacientes.user_id en tu esquema, por eso se eliminó esa comprobación.

        // Si aún no existe, crear paciente mínimo (solo nombre) y actualizar usuarios.paciente_id
        if (empty($paciente_id) && !empty($session_user_id)) {
            $nombre = $_SESSION['nombre'] ?? 'Paciente';
            $stmtc = $conn->prepare("INSERT INTO pacientes (nombre) VALUES (?)");
            if ($stmtc) {
                $stmtc->bind_param('s', $nombre);
                if ($stmtc->execute()) {
                    $newId = $conn->insert_id;
                    $paciente_id = (int)$newId;
                    $_SESSION['paciente_id'] = $paciente_id;
                    // actualizar usuarios.paciente_id para mantener consistencia
                    $up = $conn->prepare("UPDATE usuarios SET paciente_id = ? WHERE id = ?");
                    if ($up) {
                        $up->bind_param('ii', $newId, $session_user_id);
                        $up->execute();
                        $up->close();
                    }
                }
                $stmtc->close();
            } else {
                // Si falla la preparación, registrar error para depuración
                error_log('agendar_cita: no se pudo preparar INSERT INTO pacientes (nombre)');
            }
        }

        if (empty($paciente_id)) {
            $error = 'No se pudo identificar el paciente desde la sesión. Contacta al administrador.';
        }
    } else {
        $paciente_id = $posted_paciente_id;
    }

    // Validaciones adicionales
    if ($error === '') {
        if (empty($paciente_id) || $paciente_id <= 0) $error = 'Paciente inválido.';
        if ($doctor_id <= 0) $error = 'Médico inválido.';
        if ($fecha === '' || $hora === '') $error = 'Fecha y hora son obligatorias.';
    }

    // Comprobar que el doctor existe y tiene rol 'doctor'
    if ($error === '') {
        $chkdoc = $conn->prepare("SELECT id FROM usuarios WHERE id = ? AND rol = 'doctor' LIMIT 1");
        if ($chkdoc) {
            $chkdoc->bind_param('i', $doctor_id);
            $chkdoc->execute();
            $rd = $chkdoc->get_result();
            if (!($rd && $rd->num_rows)) {
                $error = 'Médico no válido.';
            }
            $chkdoc->close();
        } else {
            $error = 'Error verificando médico.';
        }
    }

    // Insert seguro con logging de errores (si todo ok)
    if ($error === '') {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $sql = "INSERT INTO citas (paciente_id, doctor_id, fecha, hora, estado, notas) VALUES (?, ?, ?, ?, 'pendiente', ?)";
        try {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iisss', $paciente_id, $doctor_id, $fecha, $hora, $notas);
            $stmt->execute();
            $stmt->close();
            $success = 'Cita agendada correctamente.';
        } catch (Exception $e) {
            error_log('ERROR al insertar cita: ' . $e->getMessage());
            if (isset($conn) && $conn->error) error_log('MySQLi conn error: ' . $conn->error);
            $error = 'Error guardando la cita. Revisa los logs del servidor para más detalles.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Agendar cita</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body{font-family:Arial,Helvetica,sans-serif;background:#f6f7fb;margin:0}
        .container{max-width:980px;margin:30px auto;padding:18px}
        .card{background:#fff;padding:16px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,.04)}
        label{display:block;margin:8px 0 4px}
        input,select,textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box}
        .row{display:flex;gap:12px}
        .row > *{flex:1}
        .btn{display:inline-block;padding:8px 14px;border-radius:6px;background:#4CAF50;color:#fff;text-decoration:none;border:0;cursor:pointer}
        .btn.secondary{background:#666}
        .msg.error{background:#fdecea;color:#c0392b;padding:10px;border-radius:6px;margin-bottom:10px}
        .msg.success{background:#e9f7ef;color:#27ae60;padding:10px;border-radius:6px;margin-bottom:10px}
        @media(max-width:700px){ .row{flex-direction:column} }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="container" style="margin-left:280px;">
        <div class="card">
            <h2>Agendar cita</h2>

            <?php if ($error !== ''): ?>
                <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="msg success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php if ($isPaciente): ?>
                    <input type="hidden" name="paciente_id" value="<?php echo (int)($paciente_id ?? $session_paciente_id ?? 0); ?>">
                    <p>Agendando como paciente: <strong><?php echo htmlspecialchars($_SESSION['nombre'] ?? 'Paciente'); ?></strong></p>
                <?php else: ?>
                    <label for="paciente_id">Paciente</label>
                    <select name="paciente_id" id="paciente_id" required>
                        <option value="">Selecciona paciente</option>
                        <?php foreach($pacientes as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <label for="doctor_id">Médico</label>
                <select name="doctor_id" id="doctor_id" required>
                    <option value="">Selecciona médico</option>
                    <?php foreach($doctores as $d): ?>
                        <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="row" style="margin-top:10px">
                    <div>
                        <label for="fecha">Fecha</label>
                        <input type="date" name="fecha" id="fecha" required>
                    </div>
                    <div>
                        <label for="hora">Hora</label>
                        <input type="time" name="hora" id="hora" required>
                    </div>
                </div>

                <label for="notas">Notas</label>
                <textarea name="notas" id="notas" rows="4"></textarea>

                <div style="margin-top:12px">
                    <button type="submit" class="btn">Agendar cita</button>
                    <a href="dashboard_paciente.php" class="btn secondary" style="margin-left:8px">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>