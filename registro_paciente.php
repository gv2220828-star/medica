<?php
// Esta página se deja pública para permitir registro de pacientes.
// Si un doctor logueado la visita, puede usarla para agregar pacientes también.
include 'db.php';
$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $conn->real_escape_string($_POST['nombre'] ?? '');
    $carnet = $conn->real_escape_string($_POST['carnet'] ?? '');
    $edad = intval($_POST['edad'] ?? 0);
    $genero = $conn->real_escape_string($_POST['genero'] ?? 'M');
    $stmt = $conn->prepare("INSERT INTO pacientes (carnet, nombre, edad, genero, fecha_registro) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("ssis", $carnet, $nombre, $edad, $genero);
    if ($stmt->execute()) { $success = "Paciente registrado."; } else { $error = $conn->error; }
}
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registrar paciente</title>
<style>
:root{--bg:#0b132b;--panel:#112240;--accent:#c75b12;--muted:#9aa6b2;--gold:#c9a15b}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041022);color:var(--muted)}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;padding:20px;background:linear-gradient(180deg,rgba(17,18,36,0.98),rgba(8,10,18,0.92));overflow-y:auto;border-right:1px solid rgba(255,255,255,0.02);z-index:1000}
.content-with-sidebar{margin-left:280px;padding:28px}
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:20px;box-shadow:0 8px 24px rgba(3,6,18,0.6)}
.input{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);background:rgba(255,255,255,0.02);color:var(--muted);outline:none}
.btn{padding:10px 14px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
.btn-primary{background:linear-gradient(90deg,var(--accent),#9a3f10);color:#fff}
.msg{padding:10px;border-radius:8px;margin-bottom:12px}
.msg.error{background:rgba(200,40,40,0.08);color:#ffb3b3}
.msg.success{background:rgba(100,180,60,0.06);color:#cfeecf}
</style>
</head>
<body>
  <!-- Si hay sesión activa, mostrar sidebar; si no, se presenta sin sidebar -->
  <?php if(session_status() === PHP_SESSION_NONE) session_start(); if(!empty($_SESSION['user_id'])) include 'sidebar.php'; ?>

  <main class="content-with-sidebar">
    <div class="card" style="max-width:720px">
      <h2 style="margin-top:0;color:#fff">Agregar Paciente</h2>
      <?php if($error): ?><div class="msg error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <?php if($success): ?><div class="msg success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
      <form method="POST">
        <label class="small">Nombre</label>
        <input name="nombre" class="input" required>
        <label class="small" style="margin-top:8px">Carnet</label>
        <input name="carnet" class="input">
        <div style="display:flex;gap:12px;margin-top:8px">
          <div style="flex:1"><label class="small">Edad</label><input type="number" name="edad" class="input" min="0"></div>
          <div style="flex:1"><label class="small">Género</label><select name="genero" class="input"><option value="M">M</option><option value="F">F</option><option value="O">O</option></select></div>
        </div>
        <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Guardar paciente</button> <a class="btn" href="ver_registros.php" style="background:#1f3a3d;color:var(--gold);text-decoration:none;padding:10px 12px;border-radius:10px">Volver</a></div>
      </form>
    </div>
  </main>
</body>
</html>