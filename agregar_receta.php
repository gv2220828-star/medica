<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Cargar PHPMailer
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

// Cargar base de datos y helper de IA
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai_helper.php'; // ← Contiene clave, gemini_get_key y obtenerRecomendacionIA

// Iniciar sesión si no está activa
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticación
if (empty($_SESSION['user_id']) || ($_SESSION['rol'] ?? '') !== 'doctor') {
    header('Location: login.php');
    exit;
}

$doctor_id = (int)($_SESSION['user_id'] ?? 0);
$mensaje = '';
$receta_creada = false;

// === GUARDAR RECETA (POST normal) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $paciente_id = isset($_POST['paciente_id']) ? (int)$_POST['paciente_id'] : 0;
    $sintomas = trim($_POST['sintomas'] ?? '');
    $tratamiento = trim($_POST['tratamiento'] ?? '');
    $notas = trim($_POST['notas'] ?? '');
    $archivo_path = '';

    // Subida de archivo
    if (!empty($_FILES['archivo']['name'])) {
        $uploaddir = __DIR__ . '/uploads';
        if (!is_dir($uploaddir)) {
            mkdir($uploaddir, 0755, true);
        }
        $filename = time() . '_' . basename($_FILES['archivo']['name']);
        $dest = $uploaddir . '/' . $filename;
        if (move_uploaded_file($_FILES['archivo']['tmp_name'], $dest)) {
            $archivo_path = 'uploads/' . $filename;
        } else {
            $mensaje = 'Error al subir el archivo.';
        }
    }

    if ($paciente_id === 0 || $sintomas === '') {
        $mensaje = 'Paciente y síntomas son requeridos.';
    } else {
        $conn->autocommit(false);

        try {
            // 1. Insertar registro
            $stmt_registro = $conn->prepare("INSERT INTO registros (paciente_id, diagnostico, tratamiento, archivo_path, fecha) VALUES (?, ?, ?, ?, NOW())");
            $stmt_registro->bind_param('isss', $paciente_id, $sintomas, $tratamiento, $archivo_path);
            if (!$stmt_registro->execute()) {
                throw new Exception('Error al guardar registro: ' . $conn->error);
            }
            $nuevo_registro_id = $conn->insert_id;

            // 2. Insertar receta
            $stmt_receta = $conn->prepare("INSERT INTO recetas (registro_id, sintomas, tratamiento, notas, fecha) VALUES (?, ?, ?, ?, NOW())");
            $stmt_receta->bind_param('isss', $nuevo_registro_id, $sintomas, $tratamiento, $notas);
            if (!$stmt_receta->execute()) {
                throw new Exception('Error al guardar receta: ' . $conn->error);
            }

            $receta_creada = true;
            $mensaje = 'Receta y registro creados exitosamente.';
            enviarRecetaPorEmail($paciente_id, $sintomas, $tratamiento);

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $mensaje = 'Error: ' . $e->getMessage();
        }

        $conn->autocommit(true);
    }
}

// === ENVIAR EMAIL (opcional) ===
function enviarRecetaPorEmail($paciente_id, $sintomas, $tratamiento) {
    global $conn;
    // Aquí puedes implementar el envío de email con PHPMailer
    // Ejemplo básico:
    /*
    $stmt = $conn->prepare("SELECT email FROM pacientes WHERE id = ?");
    $stmt->bind_param('i', $paciente_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $paciente = $result->fetch_assoc();

    if ($paciente && filter_var($paciente['email'], FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'tuemail@gmail.com';
            $mail->Password = 'tu-app-password';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('tuemail@gmail.com', 'Clínica');
            $mail->addAddress($paciente['email']);
            $mail->Subject = 'Tu receta médica';
            $mail->Body = "Síntomas: $sintomas\nTratamiento: $tratamiento";

            $mail->send();
        } catch (Exception $e) {
            error_log("Email no enviado: {$mail->ErrorInfo}");
        }
    }
    */
}

// === API JSON: Sugerencia IA ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');

    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        echo json_encode(['error' => 'JSON inválido o vacío', 'raw' => $raw], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sintomas = trim($data['sintomas'] ?? '');
    $registro_id = isset($data['registro_id']) ? (int)$data['registro_id'] : 0;

    if ($sintomas === '' && $registro_id <= 0) {
        echo json_encode(['error' => 'Faltan parámetros: sintomas o registro_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_SESSION['user_id']) || ($_SESSION['rol'] ?? '') !== 'doctor') {
        echo json_encode(['error' => 'No autorizado. Inicia sesión.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Asegurarse de que la función exista
    if (!function_exists('obtenerRecomendacionIA')) {
        echo json_encode(['error' => 'Función IA no disponible'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $texto = trim($sintomas);
    if ($texto === '') {
        echo json_encode(['error' => 'No hay texto para analizar'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resIA = obtenerRecomendacionIA($texto);

    if (isset($resIA['error'])) {
        echo json_encode([
            'error' => $resIA['error'],
            'raw' => $resIA['raw'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'enfermedad' => trim($resIA['enfermedad'] ?? ''),
        'tratamiento' => trim($resIA['tratamiento'] ?? ''),
        'consejos' => trim($resIA['consejos'] ?? ''),
        'raw' => $resIA['raw'] ?? ''
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// === TEST ENDPOINT: Verificar configuración ===
if (isset($_GET['test_api'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $apiKey = gemini_get_key();
    $keyValid = !empty($apiKey) && strpos($apiKey, 'AIzaSy') === 0;
    
    echo json_encode([
        'api_key_configured' => $keyValid,
        'api_key_prefix' => $keyValid ? substr($apiKey, 0, 10) . '...' : 'N/A',
        'curl_enabled' => function_exists('curl_init'),
        'php_version' => phpversion(),
        'openssl_enabled' => extension_loaded('openssl'),
        'helper_loaded' => function_exists('obtenerRecomendacionIA'),
        'session_active' => session_status() === PHP_SESSION_ACTIVE
    ], JSON_PRETTY_PRINT);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Agregar Receta (IA)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #iaStatus { font-size: .9rem; color: #6c757d; }
        #iaPanel { white-space: pre-wrap; background: #0f1724; color: #e6eef8; padding: 12px; border-radius: 8px; margin-top: .5rem; font-family: monospace; font-size: .85rem; }
        .field-small { font-size: .9rem; color: #6c757d; }
    </style>
</head>
<body class="p-4">
<div class="container" style="max-width:820px">
    <h3>Agregar Receta (asistente IA)</h3>

    <?php if ($mensaje): ?>
        <div class="alert alert-<?= $receta_creada ? 'success' : 'danger' ?>">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <form id="recetaForm" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <div class="row g-3">

            <!-- Paciente -->
            <div class="col-md-6">
                <label class="form-label">Paciente</label>
                <select name="paciente_id" id="paciente_id" class="form-select" required>
                    <option value="">Seleccionar</option>
                    <?php
                    $q = $conn->query("SELECT id, nombre FROM pacientes ORDER BY nombre");
                    while ($r = $q->fetch_assoc()) {
                        echo '<option value="'.(int)$r['id'].'">'.htmlspecialchars($r['nombre']).'</option>';
                    }
                    ?>
                </select>
            </div>

            <!-- Registro ID (opcional) -->
            <div class="col-md-6">
                <label class="form-label">Registro ID (opcional)</label>
                <input type="number" name="registro_id" id="registro_id" class="form-control" placeholder="ID registro si aplica" readonly>
                <div class="field-small">Se generará automáticamente.</div>
            </div>

            <!-- Síntomas -->
            <div class="col-12">
                <label class="form-label">Síntomas / Descripción clínica</label>
                <textarea name="sintomas" id="sintomas" class="form-control" rows="4" required></textarea>
                <div class="field-small">Describe síntomas, antecedentes o hallazgos.</div>
            </div>

            <!-- Botón IA -->
            <div class="col-12 d-flex gap-2 align-items-center">
                <button type="button" id="sugerirBtn" class="btn btn-outline-primary">Sugerir diagnóstico (IA)</button>
                <div id="iaStatus"></div>
            </div>

            <!-- Diagnóstico IA -->
            <div class="col-12">
                <label class="form-label">Diagnóstico sugerido (IA)</label>
                <input type="text" id="enfermedad" class="form-control" readonly placeholder="Aquí aparecerá el diagnóstico sugerido">
            </div>

            <!-- Tratamiento -->
            <div class="col-12">
                <label class="form-label">Tratamiento sugerido (puedes editar)</label>
                <textarea name="tratamiento" id="tratamiento" class="form-control" rows="5"></textarea>
            </div>

            <!-- Notas -->
            <div class="col-12">
                <label class="form-label">Notas (opcional)</label>
                <textarea name="notas" id="notas" class="form-control" rows="3"></textarea>
            </div>

            <!-- Archivo -->
            <div class="col-12">
                <label class="form-label">Archivo (opcional)</label>
                <input type="file" name="archivo" class="form-control" accept=".pdf,.jpg,.png,.doc,.docx">
            </div>

            <!-- Botones -->
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Guardar receta</button>
                <a href="dashboard_doctor.php" class="btn btn-secondary">Volver</a>
            </div>

            <!-- Debug IA -->
            <div class="col-12">
                <h6>Salida IA (debug)</h6>
                <div id="iaPanel">Aquí aparecerá la respuesta cruda de la IA.</div>
            </div>
        </div>
    </form>
</div>

<script>
document.getElementById('sugerirBtn').addEventListener('click', async function() {
    const sintomas = document.getElementById('sintomas').value.trim();
    const status = document.getElementById('iaStatus');
    const panel = document.getElementById('iaPanel');
    const enfermedadField = document.getElementById('enfermedad');
    const tratamientoField = document.getElementById('tratamiento');

    if (!sintomas) {
        status.textContent = '⚠️ Escribe síntomas primero.';
        status.style.color = '#dc3545';
        setTimeout(() => { status.textContent = ''; }, 3000);
        return;
    }

    // UI feedback
    status.textContent = '🔄 Consultando IA...';
    status.style.color = '#0d6efd';
    this.disabled = true;
    this.textContent = 'Procesando...';
    panel.textContent = 'Esperando respuesta...';
    panel.style.background = '#f8f9fa';
    enfermedadField.value = '';
    tratamientoField.value = '';

    try {
        const res = await fetch('agregar_receta.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ sintomas })
        });

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }

        const raw = await res.text();
        console.log('Respuesta IA (raw):', raw);

        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error(`Respuesta no es JSON válido: ${e.message}\n\nRespuesta: ${raw.substring(0, 200)}`);
        }

        if (data.error) {
            status.textContent = '❌ Error: ' + data.error;
            status.style.color = '#dc3545';
            panel.textContent = 'Detalles del error:\n' + (data.raw || 'Sin detalles');
            panel.style.background = '#fff3cd';
            
            // Mostrar sugerencias según el error
            if (data.error_code === 403) {
                panel.textContent += '\n\n💡 Sugerencia: Verifica que tu API key sea válida y tenga permisos para Gemini API.';
            } else if (data.error_code === 404) {
                panel.textContent += '\n\n💡 Sugerencia: El modelo solicitado no existe. Se ha actualizado a gemini-1.5-flash.';
            } else if (data.error_code === 429) {
                panel.textContent += '\n\n💡 Sugerencia: Has excedido el límite de solicitudes. Espera unos minutos.';
            }
        } else {
            // Éxito
            status.textContent = '✅ Sugerencia recibida exitosamente';
            status.style.color = '#198754';
            
            enfermedadField.value = data.enfermedad || 'No especificado';
            tratamientoField.value = data.tratamiento || 'No especificado';
            
            panel.textContent = '📋 Respuesta completa de IA:\n\n' + 
                               '🔬 Diagnóstico: ' + (data.enfermedad || 'N/A') + '\n\n' +
                               '💊 Tratamiento: ' + (data.tratamiento || 'N/A') + '\n\n' +
                               '💡 Consejos: ' + (data.consejos || 'N/A') + '\n\n' +
                               '📄 Raw: ' + (data.raw || 'N/A');
            panel.style.background = '#d1e7dd';
        }
    } catch (e) {
        status.textContent = '❌ Error de conexión';
        status.style.color = '#dc3545';
        panel.textContent = '⚠️ Error al conectar con el servidor:\n' + e.message + 
                           '\n\n💡 Verifica:\n' +
                           '- Que el servidor esté ejecutándose\n' +
                           '- Tu conexión a internet\n' +
                           '- Los logs de PHP para más detalles';
        panel.style.background = '#f8d7da';
        console.error('Error completo:', e);
    } finally {
        this.disabled = false;
        this.textContent = 'Sugerir diagnóstico (IA)';
        setTimeout(() => {
            if (status.textContent.includes('Error')) {
                status.textContent = '';
            }
        }, 8000);
    }
});
</script>
</body>
</html>