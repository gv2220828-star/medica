<?php
include 'auth.php';
include 'db.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;

$pacientes = $conn->query("SELECT p.id, p.nombre, u.correo FROM pacientes p LEFT JOIN usuarios u ON (u.id = p.user_id) ORDER BY p.nombre");
$doctores = $conn->query("SELECT id, nombre, correo FROM usuarios WHERE rol='doctor' ORDER BY nombre");
$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipientes = $_POST['destinos'] ?? [];
    $asunto = $conn->real_escape_string($_POST['asunto'] ?? '');
    $mensaje = $conn->real_escape_string($_POST['mensaje'] ?? '');
    $emails = [];
    if (in_array('todos_pacientes', $recipientes)) {
        $r = $conn->query("SELECT correo FROM usuarios JOIN pacientes ON pacientes.user_id = usuarios.id")->fetch_all(MYSQLI_ASSOC);
        foreach($r as $e) $emails[] = $e['correo'];
    } else {
        if (in_array('pacientes', $recipientes) && isset($_POST['pacientes_list'])) {
            foreach($_POST['pacientes_list'] as $pid) {
                $row = $conn->query("SELECT correo FROM usuarios u JOIN pacientes p ON p.user_id = u.id WHERE p.id = ".intval($pid))->fetch_assoc();
                if ($row['correo']) $emails[] = $row['correo'];
            }
        }
        if (in_array('doctores', $recipientes) && isset($_POST['doctores_list'])) {
            foreach($_POST['doctores_list'] as $did) {
                $row = $conn->query("SELECT correo FROM usuarios WHERE id = ".intval($did))->fetch_assoc();
                if ($row['correo']) $emails[] = $row['correo'];
            }
        }
    }
    $mail = new PHPMailer(true);
    try {
        $mail->setFrom('no-reply@example.com','Medica');
        foreach(array_unique($emails) as $to) {
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($to);
                $mail->Subject = $asunto;
                $mail->Body = $mensaje;
                $mail->send();
                $mail->clearAddresses();
            }
        }
        $success = "Notificaciones enviadas.";
    } catch (Exception $e) {
        $error = "Error envío: " . $mail->ErrorInfo;
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Notificar</title>
<style>
:root{--bg:#0b132b;--muted:#9aa6b2;--gold:#c9a15b;--accent:#c75b12}*{box-sizing:border-box}html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041022);color:var(--muted)}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;padding:20px;background:linear-gradient(180deg,rgba(17,18,36,0.98),rgba(8,10,18,0.92));overflow-y:auto}
.content-with-sidebar{margin-left:280px;padding:28px}.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:20px}
.input{width:100%;padding:12px;border-radius:10px;background:rgba(255,255,255,0.02);color:var(--muted);border:1px solid rgba(255,255,255,0.03)}
.btn{padding:10px 14px;border-radius:10px;border:0;cursor:pointer}
.btn-primary{background:linear-gradient(90deg,var(--accent),#9a3f10);color:#fff}
</style>
</head>
<body>
  <?php include 'sidebar.php'; ?>

  <main class="content-with-sidebar">
    <div class="card" style="max-width:920px">
      <h2 style="margin-top:0;color:#fff">Enviar notificaciones</h2>
      <?php if($error): ?><div class="msg error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <?php if($success): ?><div class="msg success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
      <form method="POST">
        <label class="small">Destinatarios</label>
        <div style="display:flex;gap:8px;margin-bottom:8px">
          <label><input type="checkbox" name="destinos[]" value="pacientes"> Pacientes</label>
          <label><input type="checkbox" name="destinos[]" value="doctores"> Doctores</label>
        </div>
        <label class="small">Seleccionar pacientes (opcional)</label>
        <select name="pacientes_list[]" class="input" multiple>
          <?php $ps = $conn->query("SELECT id, nombre FROM pacientes ORDER BY nombre"); while($p=$ps->fetch_assoc()): ?>
            <option value="<?php echo $p['id'];?>"><?php echo htmlspecialchars($p['nombre']);?></option>
          <?php endwhile; ?>
        </select>

        <label class="small" style="margin-top:8px">Asunto</label>
        <input name="asunto" class="input" required>
        <label class="small" style="margin-top:8px">Mensaje</label>
        <textarea name="mensaje" class="input" style="min-height:120px" required></textarea>
        <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Enviar</button></div>
      </form>
    </div>
  </main>
</body>
</html>