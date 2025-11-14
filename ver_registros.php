<?php
include 'auth.php';
include 'db.php';

// Asegurar sesión/rol
$user_rol = $_SESSION['rol'] ?? '';
$user_id = intval($_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? $_SESSION['id'] ?? 0);

// Determinar filtro de paciente.
// Si es paciente, obtener su paciente_id desde tabla pacientes (WHERE user_id = $user_id)
$filter_paciente = isset($_GET['paciente_id']) ? intval($_GET['paciente_id']) : 0;

if ($user_rol === 'paciente') {
    // priorizar valor en sesión
    $session_pid = intval($_SESSION['paciente_id'] ?? 0);
    if ($session_pid > 0) {
        $filter_paciente = $session_pid;
    } else if ($user_id > 0) {
        // intentar obtener paciente_id desde tabla pacientes (WHERE user_id = ?)
        $stmt = $conn->prepare("SELECT id FROM pacientes WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            if ($stmt->execute()) {
                $res_pid = $stmt->get_result();
                $rpid = $res_pid ? $res_pid->fetch_assoc() : null;
                if (!empty($rpid['id'])) {
                    $filter_paciente = intval($rpid['id']);
                    $_SESSION['paciente_id'] = $filter_paciente; // cachear
                } else {
                    // sin paciente asociado → forzar valor que devuelva 0 filas
                    $filter_paciente = -1;
                }
            }
            $stmt->close();
        } else {
            // si falla el prepare, forzar no mostrar
            $filter_paciente = -1;
        }
    } else {
        $filter_paciente = -1;
    }
}

// Obtener lista de pacientes solo para mostrar en el select (doctor).
$pacientes = [];
if ($user_rol === 'doctor') {
    $q = $conn->query("SELECT id, nombre FROM pacientes ORDER BY nombre");
    if ($q !== false) {
        while ($p = $q->fetch_assoc()) $pacientes[] = $p;
        $q->free();
    } else {
        error_log('ver_registros.php - pacientes query error: ' . $conn->error);
    }
}

// Construir consulta segura (el valor viene forzado por intval arriba)
$where = "";
if ($filter_paciente > 0) {
    $where = "WHERE r.paciente_id = " . intval($filter_paciente);
} elseif ($filter_paciente === -1) {
    $where = "WHERE 0"; // devolver nada
}

$sql = "SELECT r.*, p.nombre AS paciente FROM registros r JOIN pacientes p ON p.id = r.paciente_id $where ORDER BY r.fecha DESC";
$res = $conn->query($sql);
$rows = [];
if ($res !== false) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
} else {
    error_log('ver_registros.php - registros query error: ' . $conn->error);
    // $rows queda vacío; la interfaz mostrará "No hay registros."
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ver registros</title>
    <style>
        :root {
            --bg: #0b132b;
            --accent: #c75b12;
            --muted: #9aa6b2;
            --gold: #c9a15b;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; font-family: Inter, system-ui, Segoe UI, Roboto, Arial; background: linear-gradient(180deg, var(--bg), #041022); color: var(--muted); }
        .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 260px; padding: 20px; background: linear-gradient(180deg, rgba(17,18,36,0.98), rgba(8,10,18,0.92)); overflow-y: auto; border-right: 1px solid rgba(255,255,255,0.02); }
        .content-with-sidebar { margin-left: 280px; padding: 28px; }
        .card { background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); border-radius: 12px; padding: 20px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.03); }
        .input { padding: 8px; border-radius: 8px; background: rgba(255,255,255,0.02); color: var(--muted); border: 1px solid rgba(255,255,255,0.03); }
        .btn { padding: 8px 10px; border-radius: 8px; background: #1f3a3d; color: var(--gold); text-decoration: none; cursor: pointer; border: none; }
        /* Modal */
        .modal { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:10000; overflow:auto; }
        .modal-content { background: linear-gradient(180deg, rgba(17,18,36,0.98), rgba(8,10,18,0.92)); margin:5% auto; padding:20px; border-radius:12px; width:90%; max-width:500px; color:var(--muted); position:relative; box-shadow:0 4px 8px rgba(0,0,0,0.2); }
        .close { position:absolute; top:10px; right:15px; font-size:24px; cursor:pointer; color:var(--gold); }
        .modal-field { margin-bottom:15px; }
        .modal-field label { display:block; font-weight:bold; margin-bottom:5px; color:#fff; }
        .modal-field p { margin:0; word-wrap:break-word; color:var(--muted); }
        .file-link a { color: var(--gold); text-decoration:none; }
        .file-link a:hover { text-decoration:underline; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main class="content-with-sidebar">
        <div class="card">
            <h2 style="margin-top: 0; color: #fff">Registros</h2>

            <form method="GET" style="margin-bottom: 14px">
                <label class="small">Filtrar por paciente</label>
                <select name="paciente_id" class="input" onchange="this.form.submit()">
                    <?php if ($user_rol === 'doctor'): ?>
                        <option value="">Todos</option>
                        <?php foreach ($pacientes as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>" <?php if ($filter_paciente == $p['id']) echo 'selected'; ?>><?php echo htmlspecialchars($p['nombre']); ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- paciente no puede cambiar; mostrar su nombre si existe -->
                        <?php
                            $nombre = '-';
                            if ($filter_paciente > 0) {
                                $s = $conn->prepare("SELECT nombre FROM pacientes WHERE id = ? LIMIT 1");
                                if ($s) {
                                    $s->bind_param('i', $filter_paciente);
                                    if ($s->execute()) {
                                        $r = $s->get_result()->fetch_assoc();
                                        if (!empty($r['nombre'])) $nombre = $r['nombre'];
                                    }
                                    $s->close();
                                }
                            }
                        ?>
                        <option value="<?php echo $filter_paciente > 0 ? (int)$filter_paciente : ''; ?>"><?php echo htmlspecialchars($nombre); ?></option>
                    <?php endif; ?>
                </select>
            </form>

            <table class="table">
                <thead>
                    <tr>
                        <th>Paciente</th>
                        <th>Diagnóstico</th>
                        <th>Fecha</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="4" style="padding:18px;color:var(--muted)">No hay registros.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['paciente']); ?></td>
                            <td><?php echo htmlspecialchars(mb_substr($r['diagnostico'] ?? '', 0, 120)); ?></td>
                            <td><?php echo htmlspecialchars($r['fecha']); ?></td>
                            <td>
                                <button class="btn view-btn" data-record='<?php echo json_encode($r, JSON_UNESCAPED_UNICODE); ?>'>Ver</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Modal -->
        <div id="myModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <div id="modal-body"></div>
            </div>
        </div>
    </main>

    <script>
        function openModal(data) {
            const modal = document.getElementById('myModal');
            const modalBody = document.getElementById('modal-body');
            if (!modal || !modalBody) return;
            let content = `
                <div class="modal-field">
                    <label>Paciente:</label>
                    <p>${escapeHtml(data.paciente)}</p>
                </div>
                <div class="modal-field">
                    <label>Diagnóstico:</label>
                    <p>${escapeHtml(data.diagnostico)}</p>
                </div>
                <div class="modal-field">
                    <label>Tratamiento:</label>
                    <p>${escapeHtml(data.tratamiento || 'No especificado')}</p>
                </div>
                <div class="modal-field">
                    <label>Fecha:</label>
                    <p>${escapeHtml(data.fecha)}</p>
                </div>
            `;
            if (data.archivo_path) {
                content += `
                    <div class="modal-field file-link">
                        <label>Archivo:</label>
                        <p><a href="${escapeHtml(data.archivo_path)}" target="_blank">Descargar archivo</a></p>
                    </div>
                `;
            }
            modalBody.innerHTML = content;
            modal.style.display = 'block';
        }

        function closeModal() {
            const modal = document.getElementById('myModal');
            if (modal) modal.style.display = 'none';
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.view-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const data = JSON.parse(this.getAttribute('data-record'));
                    openModal(data);
                });
            });
        });

        window.onclick = function(event) {
            const modal = document.getElementById('myModal');
            if (modal && event.target === modal) modal.style.display = 'none';
        };
    </script>
</body>
</html>