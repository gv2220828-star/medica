<?php
include 'auth.php';
require_login();
require_role('doctor');
include 'db.php';
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard Doctor</title>
<style>
:root{--bg:#0b132b;--panel:#112240;--accent:#c75b12;--muted:#9aa6b2;--gold:#c9a15b}
*{box-sizing:border-box}
html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041022);color:var(--muted)}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;padding:20px;background:linear-gradient(180deg,rgba(17,18,36,0.98),rgba(8,10,18,0.92));overflow-y:auto;border-right:1px solid rgba(255,255,255,0.02);z-index:1000}
.content-with-sidebar{margin-left:280px;padding:28px}
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:20px;box-shadow:0 8px 24px rgba(3,6,18,0.6)}
.menu-link{display:block;padding:10px 8px;color:var(--muted);text-decoration:none;margin-bottom:6px;border-radius:6px}
.menu-link:hover{background:rgba(255,255,255,0.02);color:var(--gold)}
.btn{padding:8px 12px;border-radius:8px;border:0;cursor:pointer;background:linear-gradient(90deg,var(--accent),#9a3f10);color:#fff;font-weight:600}
.quick-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
.quick-actions a{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;text-decoration:none;background:rgba(255,255,255,0.02);color:var(--muted)}
</style>
</head>
<body>
  <?php include 'sidebar.php'; ?>

  <main class="content-with-sidebar">
    <div class="card">
      <h1 style="margin-top:0;color:#fff">Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre'] ?? 'Doctor'); ?></h1>
      <p class="small">Resumen rápido:</p>
      <?php
      $tot_citas = $conn->query("SELECT COUNT(*) AS cnt FROM citas")->fetch_assoc()['cnt'] ?? 0;
      $tot_reg = $conn->query("SELECT COUNT(*) AS cnt FROM registros")->fetch_assoc()['cnt'] ?? 0;
      ?>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div style="background:rgba(255,255,255,0.02);padding:16px;border-radius:10px;min-width:160px">
          <div style="color:var(--gold);font-weight:700"><?php echo $tot_citas; ?></div>
          <div class="small">Citas totales</div>
        </div>
        <div style="background:rgba(255,255,255,0.02);padding:16px;border-radius:10px;min-width:160px">
          <div style="color:var(--gold);font-weight:700"><?php echo $tot_reg; ?></div>
          <div class="small">Registros</div>
        </div>
      </div>

      <div class="quick-actions">
        <a href="agendar_cita.php">Agendar cita</a>
        <a href="registro_paciente.php">Agregar paciente</a>
        <a href="ver_registros.php">Ver registros</a>
      </div>
    </div>
  </main>
</body>
</html>
