<?php
include 'auth.php';
require_login();
require_role('paciente');
include 'db.php';
$uid = $_SESSION['user_id'] ?? 0;

// Depuración
error_log("Acceso a dashboard_paciente.php: user_id=$uid, rol={$_SESSION['rol']}");

// Opcional: Descomenta para depuración manual
// var_dump($_SESSION); exit;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Paciente</title>
<style>
:root{--bg:#0b132b;--panel:#112240;--accent:#c75b12;--muted:#9aa6b2;--gold:#c9a15b}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041022);color:var(--muted)}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;padding:20px;background:linear-gradient(180deg,rgba(17,18,36,0.98),rgba(8,10,18,0.92));overflow-y:auto;border-right:1px solid rgba(255,255,255,0.02);z-index:1000}
.content-with-sidebar{margin-left:280px;padding:28px}
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:20px;box-shadow:0 8px 24px rgba(3,6,18,0.6)}
.menu-link{display:block;padding:10px 8px;color:var(--muted);text-decoration:none;margin-bottom:6px;border-radius:6px}
.menu-link:hover{background:rgba(255,255,255,0.02);color:var(--gold)}
</style>
</head>
<body>
  <?php include 'sidebar.php'; ?>

  <main class="content-with-sidebar">
    <div class="card">
      <h1 style="margin-top:0;color:#fff">Hola, <?php echo htmlspecialchars($_SESSION['nombre'] ?? 'Paciente'); ?></h1>
      <p class="small">Accede a tus citas, registros y facturas desde el menú.</p>
      <div style="margin-top:12px">
        <a class="btn" href="agendar_cita.php" style="background:linear-gradient(90deg,#c75b12,#9a3f10);color:#fff;padding:10px 12px;border-radius:8px;text-decoration:none">Pedir cita</a>
        <a class="btn" href="ver_registros.php" style="background:#1f3a3d;color:var(--gold);padding:10px 12px;border-radius:8px;text-decoration:none;margin-left:8px">Mis registros</a>
      </div>
    </div>
  </main>
</body>
</html>