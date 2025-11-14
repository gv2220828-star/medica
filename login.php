<?php
include 'db.php';
session_start();
if (!empty($_SESSION['user_id'])) {
    if ($_SESSION['rol']==='doctor') header('Location: dashboard_doctor.php'); else header('Location: dashboard_paciente.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $conn->real_escape_string($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $stmt = $conn->prepare("SELECT id, password, rol, nombre, paciente_id FROM usuarios WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $usuario);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    if ($r && password_verify($pass, $r['password'])) {
        $_SESSION['user_id'] = $r['id'];
        $_SESSION['rol'] = $r['rol'];
        $_SESSION['nombre'] = $r['nombre'];
        $_SESSION['paciente_id'] = $r['paciente_id'] ?? null;

        if ($r['rol'] === 'doctor') header('Location: dashboard_doctor.php');
        else header('Location: dashboard_paciente.php');
        exit;
    } else {
        $error = "Usuario o contraseña inválidos.";
    }
}
?>
<!doctype html>
<html lang="es">              
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login - Medica</title>
<style>
:root{--bg:#0b132b;--panel:#112240;--accent:#c75b12;--muted:#9aa6b2;--gold:#c9a15b}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041022);color:var(--muted)}
.container-center{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:28px;box-shadow:0 8px 24px rgba(3,6,18,0.6);width:420px}
.input, select, textarea{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);background:rgba(255,255,255,0.02);color:var(--muted);outline:none}
.btn{padding:10px 14px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
.btn-primary{background:linear-gradient(90deg,var(--accent),#9a3f10);color:#fff}
.small{font-size:.85rem;color:#7f8a90;margin-top:6px}
.msg{padding:10px;border-radius:8px;margin-bottom:12px}
.msg.error{background:rgba(200,40,40,0.08);color:#ffb3b3}
</style>
</head>
<body>
<div class="container-center">
  <div class="card">
    <h2 style="margin-top:0;color:#fff">Iniciar sesión</h2>
    <?php if($error): ?><div class="msg error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST" novalidate>
      <label class="small">Usuario</label>
      <input name="username" class="input" required>
      <label class="small" style="margin-top:8px">Contraseña</label>
      <input name="password" type="password" class="input" required>
      <div style="display:flex;gap:10px;margin-top:12px">
        <button class="btn btn-primary" type="submit">Entrar</button>
        <a href="registro_usuario.php" class="btn" style="background:#1f3a3d;color:var(--gold);text-decoration:none;padding:10px 12px;border-radius:10px;display:inline-flex;align-items:center">Registrar</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>