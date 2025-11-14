<?php
session_start();

// Comprobar sesión / rol
$role = strtolower(trim($_SESSION['rol'] ?? $_SESSION['role'] ?? ''));
if (empty($_SESSION['user_id']) || $role === '') {
    echo '<h1>No has iniciado sesión</h1>';
    echo '<p>Debes <a href="login.php">iniciar sesión</a> para acceder a tu panel.</p>';
    exit;
}
if ($role !== 'paciente') {
    // Si es doctor, redirigir a su panel; si otro rol, mostrar 403
    if ($role === 'doctor') {
        header('Location: dashboard_doctor.php');
        exit;
    }
    header('HTTP/1.1 403 Forbidden');
    echo '<h1>403 - Acceso no autorizado</h1>';
    echo '<p>No tiene permisos para ver esta página. Rol actual: ' . htmlspecialchars($role) . '</p>';
    exit;
}

// Obtener id seguro del paciente desde la sesión (evita undefined index)
$paciente_id = $_SESSION['paciente_id'] ?? $_SESSION['user_id'] ?? null;
if (!$paciente_id) {
    echo '<p>ID de paciente no disponible. Por favor, <a href="login.php">inicia sesión</a>.</p>';
    exit;
}

// Incluir conexión a la base de datos (no se modifica db.php)
include 'db.php';

// Verificar que la conexión exista y esté OK (no se altera la validación que haga db.php)
if (!isset($conn) || (property_exists($conn, 'connect_error') && $conn->connect_error)) {
    echo '<h1>Error de conexión</h1><p>No se pudo conectar a la base de datos. Contacta al administrador.</p>';
    exit;
}

// Consultas seguras
$sql = "SELECT r.*, rec.sintomas, rec.tratamiento AS rec_tratamiento, rec.notas 
        FROM registros r 
        LEFT JOIN recetas rec ON r.id = rec.registro_id 
        WHERE r.paciente_id = ?";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $paciente_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $registros = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
} else {
    $registros = [];
}

$sql_citas = "SELECT c.*, u.nombre AS doctor_nombre FROM citas c LEFT JOIN usuarios u ON u.id = c.doctor_id WHERE c.paciente_id = ? ORDER BY c.fecha DESC, c.hora DESC";
if ($stmt2 = $conn->prepare($sql_citas)) {
    $stmt2->bind_param("i", $paciente_id);
    $stmt2->execute();
    $result_citas = $stmt2->get_result();
    $citas = $result_citas ? $result_citas->fetch_all(MYSQLI_ASSOC) : [];
    $stmt2->close();
} else {
    $citas = [];
}

// No cerrar/alterar la validación de db.php más allá de usar $conn.
// $conn->close();  // opcional: dejar a db.php manejar cierre si lo hace

// --- Añadir helper esc al inicio, antes de usarlo ---
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

// { changed code - generar y cachear recomendaciones IA en sesión }

$IA_TTL = 60 * 60; // 1 hora
$max_recs = 6; // máximo de llamadas IA por carga
$session_key = 'tmp_ia_recs_v1';
$userId = $_SESSION['user_id'] ?? ('guest_' . session_id());

// helper local para consejos extra si IA no aporta o repite tratamiento
if (!function_exists('generarConsejosExtraLocal')) {
    function generarConsejosExtraLocal(array $rec): string {
        $parts = [];
        $diag = trim($rec['diagnostico'] ?? '');
        $sintomas = trim($rec['sintomas'] ?? '');
        $trat = trim($rec['tratamiento'] ?? '');

        $s = mb_strtolower($diag.' '.$sintomas.' '.$trat);
        if (mb_stripos($s,'fiebre') !== false) $parts[] = "Controla la temperatura y toma antipiréticos según indicación.";
        if (mb_stripos($s,'tos') !== false) $parts[] = "Mantén hidratación y evita irritantes. Consulta si empeora.";
        if (mb_stripos($s,'dolor') !== false) $parts[] = "Aplica medidas locales y consulta si el dolor aumenta.";
        if (mb_stripos($s,'respira') !== false || mb_stripos($s,'dificultad') !== false) $parts[] = "Si hay dificultad para respirar, acude a urgencias.";

        if (empty($parts)) $parts[] = "Descansa, hidrátate y sigue las indicaciones de tu médico.";
        $parts[] = "Señales de alarma: fiebre alta, dolor intenso, empeoramiento rápido.";

        return implode(' ', $parts);
    }
}

// funciones de sesión para cachear
if (!function_exists('get_tmp_recs_from_session')) {
    function get_tmp_recs_from_session(string $session_key, $userId, int $ttl) {
        if (empty($_SESSION[$session_key]) || !is_array($_SESSION[$session_key])) return null;
        $bucket = $_SESSION[$session_key];
        if (($bucket['user'] ?? null) !== $userId) return null;
        if (empty($bucket['ts']) || time() - (int)$bucket['ts'] > $ttl) return null;
        return $bucket['data'] ?? null;
    }
}
if (!function_exists('save_tmp_recs_to_session')) {
    function save_tmp_recs_to_session(string $session_key, $userId, array $data) {
        $_SESSION[$session_key] = ['user'=>$userId,'ts'=>time(),'data'=>$data];
    }
}

// intentar cargar cache en sesión
$recomendaciones_ia = get_tmp_recs_from_session($session_key, $userId, $IA_TTL);
$recomendaciones_ia_raw = [];

if ($recomendaciones_ia === null) {
    // generar recomendaciones
    $recomendaciones_ia = [];
    // incluir helper si existe (no se modifica)
    if (file_exists(__DIR__ . '/openai_helper.php')) include_once __DIR__ . '/openai_helper.php';

    $count = 0;
    foreach ($registros as $rec) {
        $id = isset($rec['id']) ? (int)$rec['id'] : 0;
        if (!$id) continue;

        if ($count >= $max_recs) {
            // seguir mostrando pero no llamar IA más de la cuota
            $recomendaciones_ia[$id] = generarConsejosExtraLocal($rec) . " (límite IA)";
            $recomendaciones_ia_raw[$id] = null;
            continue;
        }

        $texto = trim(
            ($rec['diagnostico'] ?? '') . "\n" .
            ($rec['tratamiento'] ?? '') . "\n" .
            ($rec['sintomas'] ?? '') . "\n" .
            ($rec['notas'] ?? '')
        );

        // si no hay texto suficiente, generar local
        if ($texto === '') {
            $recomendaciones_ia[$id] = generarConsejosExtraLocal($rec);
            $recomendaciones_ia_raw[$id] = null;
            $count++;
            continue;
        }

        // llamar al helper si existe
        if (function_exists('obtenerRecomendacionIA')) {
            $res = obtenerRecomendacionIA($texto);
            $recomendaciones_ia_raw[$id] = $res;

            if (isset($res['error'])) {
                // fallback local cuando hay error IA
                $recomendaciones_ia[$id] = generarConsejosExtraLocal($rec) . " (fallback IA: " . ($res['error'] ?? '') . ")";
            } else {
                $consejos = trim($res['consejos'] ?? $res['raw'] ?? '');
                $trat_rec = trim($rec['tratamiento'] ?? '');

                // si consejos vacíos o demasiado similares al tratamiento, combinar con local para garantizar contenido extra
                $needs_local = false;
                if ($consejos === '') $needs_local = true;
                else {
                    $lc = mb_strtolower(preg_replace('/\s+/',' ',$consejos));
                    $lt = mb_strtolower(preg_replace('/\s+/',' ',$trat_rec));
                    if ($lt !== '' && (mb_stripos($lc,$lt) !== false || mb_stripos($lt,$lc) !== false)) $needs_local = true;
                }

                if ($needs_local) {
                    $local = generarConsejosExtraLocal($rec);
                    $recomendaciones_ia[$id] = ($consejos !== '' ? $consejos . " " : "") . $local;
                } else {
                    $recomendaciones_ia[$id] = $consejos ?: generarConsejosExtraLocal($rec);
                }
            }

            $count++;
        } else {
            // no hay helper -> usar local
            $recomendaciones_ia_raw[$id] = null;
            $recomendaciones_ia[$id] = generarConsejosExtraLocal($rec);
        }
    }

    // guardar en sesión
    save_tmp_recs_to_session($session_key, $userId, $recomendaciones_ia);
}

// helper esc si no existe
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

// { end changed code }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel del paciente — Rediseño</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --bg:#f6fafb;
            --nav:#03283a;
            --accent:#ff5a5f;
            --accent-2:#ff8a00;
            --muted:#475569;
            --card:#ffffff;
            --glass: rgba(255,255,255,0.7);
        }
        *{box-sizing:border-box}
        html,body{height:100%;margin:0;font-family:Inter,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#eaf6f6);color:var(--muted)}
        .app{display:flex;min-height:100vh;align-items:stretch}
        
        /* SIDEBAR - Posición fija */
        aside{
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background:linear-gradient(180deg,var(--nav),#071829);
            color:#fff;
            padding:34px 28px;
            border-right:6px solid rgba(255,255,255,0.03);
            z-index: 1000;
            overflow-y: auto;
        }
        aside .brand{font-weight:800;font-size:26px;margin-bottom:18px;letter-spacing:0.6px}
        aside .user{font-size:15px;color:#cfeff1;margin-bottom:18px}
        
        /* MAIN - Con margen izquierdo para no solaparse con sidebar */
        main{
            margin-left: 250px; /* Ancho del sidebar */
            flex:1;
            padding:40px 48px;
            position: relative;
            z-index: 1;
        }
        
        .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px}
        h1{margin:0;color:var(--accent);font-size:40px;line-height:1;font-weight:800;text-shadow:0 4px 18px rgba(255,90,95,0.12)}
        .subtitle{color:var(--muted);font-size:16px;margin-top:6px}
        .controls{display:flex;gap:14px;align-items:center}
        
        /* Pill grande */
        .pill{display:inline-block;background:linear-gradient(90deg,var(--accent),var(--accent-2));color:#fff;padding:12px 18px;border-radius:999px;font-weight:800;box-shadow:0 8px 30px rgba(255,138,0,0.12);font-size:15px}
        
        /* GRID Y CARDS */
        .grid{display:grid;grid-template-columns:1fr;gap:26px}
        .card{
            background:var(--card);
            padding:28px;
            border-radius:14px;
            box-shadow:0 14px 40px rgba(10,20,30,0.06);
            border:1px solid rgba(10,20,30,0.03);
            position: relative;
        }
        .card h2{margin:0 0 12px 0;font-size:22px;color:#0f1724}

        /* TABLA GRANDE */
        table{width:100%;border-collapse:separate;border-spacing:0;font-size:16px}
        thead th{background:linear-gradient(90deg,var(--accent),var(--accent-2));color:#fff;padding:18px 20px;text-align:left;font-weight:800;font-size:16px;border-bottom:6px solid rgba(255,255,255,0.06)}
        tbody td{padding:20px;border-bottom:1px solid #eef6f7;vertical-align:top;font-size:15px}
        tbody tr{transition:transform .12s ease,background .12s}
        tbody tr:hover{transform:translateY(-4px);background:linear-gradient(90deg, rgba(255,138,0,0.03), rgba(255,90,95,0.02))}
        td strong{font-size:17px;color:#0b1220;display:block;margin-bottom:6px}
        .small-muted{color:#64748b;font-size:14px}
        
        /* PDF botón grande */
        .pdf-link{display:inline-block;padding:10px 14px;border-radius:12px;background:#0d6efd;color:#fff;text-decoration:none;font-weight:800;box-shadow:0 10px 30px rgba(13,110,253,0.14);font-size:14px}
        .pdf-link:hover{transform:translateY(-3px)}
        
        /* BOTÓN VER CITAS (modal) */
        .btn-big{padding:12px 16px;border-radius:12px;background:#10b981;color:#fff;border:none;font-weight:800;cursor:pointer;box-shadow:0 10px 30px rgba(16,185,129,0.12);font-size:15px}
        
        /* NO DATA */
        .no-data{color:#7a8a91;padding:24px;text-align:center;font-size:16px}
        
        /* Modal */
        .modal{display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(2,6,23,0.6);z-index:9999;align-items:center;justify-content:center}
        .modal .panel{background:linear-gradient(180deg,#fff,#fbffff);padding:24px;border-radius:14px;width:92%;max-width:1000px;max-height:88vh;overflow:auto;box-shadow:0 30px 80px rgba(2,6,23,0.32)}
        .modal h3{margin-top:0;font-size:20px}
        
        /* Responsive */
        @media (max-width:1000px){
            aside{
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            aside.show{
                transform: translateX(0);
            }
            main{
                margin-left: 0;
            }
            h1{font-size:28px}
            thead th, tbody td{font-size:15px}
            .pdf-link{padding:8px 12px}
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main>
        <div class="top">
            <div>
                <h1>Panel del paciente</h1>
                <div class="subtitle">Bienvenido — <?php echo esc($_SESSION['user_id']); ?></div>
            </div>
            <div class="controls">
                <!-- elemento visual grande en vez de botones pequeños -->
                <span class="pill">Tus registros</span>
                <button id="openCitas" class="btn-big" aria-haspopup="dialog">Ver mis citas</button>
            </div>
        </div>

        <div class="grid">
            <section class="card">
                <h2 style="margin:0 0 12px 0;font-size:20px">Registros y Recetas</h2>
                <?php if (!empty($registros)): ?>
                    <table aria-label="Registros">
                        <thead>
                            <tr>
                                <th>Diagnóstico</th>
                                <th>Tratamiento</th>
                                <th>Síntomas</th>
                                <th class="col-small">Recomendación IA</th>
                                <th class="col-small">Receta (PDF)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registros as $r): ?>
                                <?php
                                    // Evitar mostrar duplicados: si rec_tratamiento coincide con tratamiento mostrar una sola versión
                                    $tratamiento = trim($r['rec_tratamiento'] ?? $r['tratamiento'] ?? '');
                                    $diagnostico = trim($r['diagnostico'] ?? '');
                                    $sintomas = trim($r['sintomas'] ?? '');
                                    $nota = trim($r['notas'] ?? '');
                                    $ia_text = $recomendaciones_ia[$r['id']] ?? '';
                                    // Preparar objeto JS con datos mínimos para PDF
                                    $jsRecord = [
                                        'id' => $r['id'],
                                        'paciente' => $_SESSION['user_id'],
                                        'diagnostico' => $diagnostico,
                                        'tratamiento' => $tratamiento,
                                        'sintomas' => $sintomas,
                                        'notas' => $nota,
                                        'fecha' => $r['fecha'] ?? ''
                                    ];
                                ?>
                                <tr data-record='<?php echo htmlspecialchars(json_encode($jsRecord, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>'>
                                    <td><strong><?php echo esc($diagnostico); ?></strong><div class="small-muted"><?php echo esc($nota); ?></div></td>
                                    <td><?php echo nl2br(esc($tratamiento)); ?></td>
                                    <td class="small-muted"><?php echo nl2br(esc($sintomas)); ?></td>
                                    <td class="small-muted"><?php echo esc(mb_substr($ia_text,0,220)); ?><?php if (mb_strlen($ia_text) > 220) echo '...'; ?></td>
                                    <td>
                                        <a href="#" class="pdf-link js-download" title="Descargar receta PDF">Descargar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">No tienes registros médicos.</div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2 style="margin:0 0 12px 0;font-size:20px">Mis citas</h2>
                <p class="small-muted">Lista de próximas y pasadas citas. Puedes verlas en el modal haciendo clic abajo.</p>
                <p><a href="#" id="openCitas" class="pdf-link" style="background:#198754">Abrir mis citas</a></p>
            </section>
        </div>
    </main>

    <!-- Modal Citas -->
    <div id="citasModal" class="modal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="panel">
            <h3>Mis citas</h3>
            <?php if (!empty($citas)): ?>
                <table>
                    <thead><tr><th>Fecha</th><th>Hora</th><th>Médico</th><th>Notas</th></tr></thead>
                    <tbody>
                        <?php foreach ($citas as $c): ?>
                            <tr>
                                <td><?php echo esc($c['fecha']); ?></td>
                                <td><?php echo esc($c['hora']); ?></td>
                                <td><?php echo esc($c['doctor_nombre']); ?></td>
                                <td class="small-muted"><?php echo esc($c['notas']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="no-data">No tienes citas programadas.</p>
            <?php endif; ?>
            <div style="text-align:right;margin-top:12px">
                <a href="#" id="closeCitas" class="pdf-link" style="background:#6c757d">Cerrar</a>
            </div>
        </div>
    </div>

    <!-- jsPDF CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        // Abrir/Cerrar modal citas
        document.getElementById('openCitas').addEventListener('click', function(e){
            e.preventDefault();
            var m = document.getElementById('citasModal');
            m.style.display = 'flex';
            m.setAttribute('aria-hidden','false');
        });
        document.getElementById('closeCitas').addEventListener('click', function(e){
            e.preventDefault();
            var m = document.getElementById('citasModal');
            m.style.display = 'none';
            m.setAttribute('aria-hidden','true');
        });
        window.addEventListener('click', function(e){
            var m = document.getElementById('citasModal');
            if (e.target === m) { m.style.display = 'none'; m.setAttribute('aria-hidden','true'); }
        });

        // Descargar PDF con jsPDF
        (function(){
            const { jsPDF } = window.jspdf;
            document.querySelectorAll('.js-download').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    // buscar la fila padre y el data-record
                    var tr = this.closest('tr');
                    if (!tr) return;
                    var data = tr.getAttribute('data-record');
                    try {
                        var obj = JSON.parse(data);
                    } catch(err) {
                        alert('Error al leer datos de la receta.');
                        return;
                    }

                    var doc = new jsPDF({unit:'pt', format:'a4'});
                    var y = 40;
                    doc.setFontSize(20);
                    doc.text('Receta Médica', 40, y); y += 28;
                    doc.setFontSize(12);
                    doc.text('Paciente: ' + (obj.paciente || ''), 40, y); y += 18;
                    doc.text('Fecha: ' + (obj.fecha || ''), 40, y); y += 22;
                    doc.setFontSize(14);
                    doc.text('Diagnóstico:', 40, y); y += 18;
                    doc.setFontSize(12);
                    doc.text(doc.splitTextToSize(obj.diagnostico || '-', 500), 40, y); y += (Math.ceil((obj.diagnostico||'').length/80) * 14) + 8;
                    doc.setFontSize(14);
                    doc.text('Tratamiento:', 40, y); y += 18;
                    doc.setFontSize(12);
                    doc.text(doc.splitTextToSize(obj.tratamiento || '-', 500), 40, y); y += (Math.ceil((obj.tratamiento||'').length/80) * 14) + 8;
                    if (obj.sintomas) {
                        doc.setFontSize(14);
                        doc.text('Síntomas:', 40, y); y += 18;
                        doc.setFontSize(12);
                        doc.text(doc.splitTextToSize(obj.sintomas || '-', 500), 40, y); y += (Math.ceil((obj.sintomas||'').length/80) * 14) + 8;
                    }
                    if (obj.notas) {
                        doc.setFontSize(14);
                        doc.text('Notas:', 40, y); y += 18;
                        doc.setFontSize(12);
                        doc.text(doc.splitTextToSize(obj.notas || '-', 500), 40, y); y += (Math.ceil((obj.notas||'').length/80) * 14) + 8;
                    }

                    doc.save('receta_' + (obj.id || 'receta') + '.pdf');
                });
            });
        })();
    </script>
</body>
</html>