<?php
include 'db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string($_POST['username'] ?? '');
    $nombre = $conn->real_escape_string($_POST['nombre'] ?? '');
    $correo = $conn->real_escape_string($_POST['correo'] ?? '');
    $password = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
    $rol = ($_POST['rol'] === 'doctor') ? 'doctor' : 'paciente';

    $stmt = $conn->prepare("INSERT INTO usuarios (username, nombre, correo, password, rol) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $username, $nombre, $correo, $password, $rol);
    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        if ($rol === 'paciente') {
            $carnet = $conn->real_escape_string($_POST['carnet'] ?? '');
            $edad = intval($_POST['edad'] ?? 0);
            $genero = $conn->real_escape_string($_POST['genero'] ?? 'M');
            // Si tu tabla pacientes tiene user_id como FK:
            if (mysqli_fetch_row($conn->query("SHOW COLUMNS FROM pacientes LIKE 'user_id'"))) {
                $st2 = $conn->prepare("INSERT INTO pacientes (user_id, carnet, nombre, edad, genero, fecha_registro) VALUES (?, ?, ?, ?, ?, NOW())");
                $st2->bind_param("issis", $user_id, $carnet, $nombre, $edad, $genero);
                $st2->execute();
            } else {
                $st2 = $conn->prepare("INSERT INTO pacientes (carnet, nombre, edad, genero, fecha_registro) VALUES (?, ?, ?, ?, NOW())");
                $st2->bind_param("ssis", $carnet, $nombre, $edad, $genero);
                $st2->execute();
            }
        }
        header('Location: login.php');
        exit;
    } else {
        $error = "Error al registrar: " . $conn->error;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registrar usuario</title>
<style>
:root{--bg:#0b132b;--panel:#112240;--accent:#c75b12;--muted:#9aa6b2;--gold:#c9a15b}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041022);color:var(--muted)}
.container{max-width:920px;margin:40px auto;padding:20px}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;padding:20px;background:linear-gradient(180deg,rgba(17,18,36,0.98),rgba(8,10,18,0.92));overflow-y:auto;border-right:1px solid rgba(255,255,255,0.02);z-index:1000}
.content-with-sidebar{margin-left:280px;padding:28px}
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:20px;box-shadow:0 8px 24px rgba(3,6,18,0.6)}
.input, select, textarea{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);background:rgba(255,255,255,0.02);color:var(--muted);outline:none}
.btn{padding:10px 14px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
.btn-primary{background:linear-gradient(90deg,var(--accent),#9a3f10);color:#fff}
.small{font-size:.85rem;color:#7f8a90;margin-top:6px}
.msg.error{background:rgba(200,40,40,0.08);color:#ffb3b3;padding:10px;border-radius:8px;margin-bottom:12px}
</style>
</head>
<body>
  <aside class="sidebar">
    <div style="color:var(--gold);font-weight:800;margin-bottom:12px">MEDICA • Registro</div>
    <nav>
      <a style="display:block;color:var(--muted);text-decoration:none;margin-bottom:6px" href="login.php">Iniciar sesión</a>
    </nav>
  </aside>

  <main class="content-with-sidebar">
    <div class="card">
      <h2 style="margin-top:0;color:#fff">Registrar usuario</h2>
      <?php if($error): ?><div class="msg error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <form method="POST" novalidate>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <div style="flex:1;min-width:220px">
            <label class="small">Usuario</label>
            <input name="username" class="input" required>
          </div>
          <div style="flex:1;min-width:220px">
            <label class="small">Nombre</label>
            <input name="nombre" class="input" required>
          </div>
        </div>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
          <div style="flex:1;min-width:220px">
            <label class="small">Correo</label>
            <input name="correo" type="email" class="input">
          </div>
          <div style="flex:1;min-width:220px">
            <label class="small">Contraseña</label>
            <input name="password" type="password" class="input" required>
          </div>
        </div>

        <div style="margin-top:12px">
          <label class="small">Rol</label>
          <select name="rol" class="input" required>
            <option value="paciente">Paciente</option>
            <option value="doctor">Doctor</option>
          </select>
        </div>

        <div id="pacienteFields" style="margin-top:12px">
          <div style="display:flex;gap:12px;flex-wrap:wrap">
            <div style="flex:1;min-width:220px">
              <label class="small">Carnet (opcional)</label>
              <input name="carnet" class="input">
            </div>
            <div style="flex:1;min-width:220px">
              <label class="small">Edad</label>
              <input name="edad" type="number" min="0" class="input">
            </div>
          </div>
          <div style="margin-top:8px">
            <label class="small">Género</label>
            <select name="genero" class="input">
              <option value="M">M</option><option value="F">F</option><option value="O">O</option>
            </select>
          </div>
        </div>

        <div style="margin-top:14px;display:flex;gap:10px">
          <button class="btn btn-primary" type="submit">Registrar</button>
          <a href="login.php" class="btn" style="background:#1f3a3d;color:var(--gold);padding:10px 12px;border-radius:10px;text-decoration:none">Volver</a>
        </div>
      </form>
    </div>
  </main>

<script>
document.querySelector('select[name="rol"]').addEventListener('change', function(){
  document.getElementById('pacienteFields').style.display = this.value === 'paciente' ? 'block' : 'none';
});
</script>
</body>
</html>