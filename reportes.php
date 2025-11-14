<?php
include 'auth.php';

include 'db.php';
$pacientes = $conn->query("SELECT id, nombre FROM pacientes ORDER BY nombre");
$doctores = $conn->query("SELECT id, nombre FROM usuarios WHERE rol='doctor' ORDER BY nombre");
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$pid = intval($_GET['paciente_id'] ?? 0);
$did = intval($_GET['doctor_id'] ?? 0);

$where = [];
if ($pid) $where[] = "r.paciente_id = $pid";
if ($did) $where[] = "c.doctor_id = $did";
if ($desde) $where[] = "r.fecha >= '".$conn->real_escape_string($desde)."'";
if ($hasta) $where[] = "r.fecha <= '".$conn->real_escape_string($hasta)."'";
$whereSql = $where ? "WHERE ".implode(" AND ", $where) : "";
$sql = "SELECT r.*, p.nombre AS paciente, u.nombre AS doctor FROM registros r JOIN pacientes p ON p.id = r.paciente_id LEFT JOIN usuarios u ON u.id = (SELECT doctor_id FROM citas WHERE paciente_id=r.paciente_id LIMIT 1) $whereSql ORDER BY r.fecha DESC";
$res = $conn->query($sql);
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reportes</title>
<style>
:root{--bg:#0b132b;--muted:#9aa6b2;--gold:#c9a15b;--accent:#c75b12}*{box-sizing:border-box}html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041022);color:var(--muted)}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;padding:20px;background:linear-gradient(180deg,rgba(17,18,36,0.98),rgba(8,10,18,0.92));overflow-y:auto}
.content-with-sidebar{margin-left:280px;padding:28px}.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:20px}
.input{padding:10px;border-radius:8px;background:rgba(255,255,255,0.02);color:var(--muted);border:1px solid rgba(255,255,255,0.03)}
.table{width:100%;border-collapse:collapse}
.table th, .table td{padding:8px;text-align:left;border-bottom:1px solid rgba(255,255,255,0.03)}
</style>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <main class="content-with-sidebar">
    <div class="card">
      <h2 style="margin-top:0;color:#fff">Reportes</h2>
      <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <div style="flex:1;min-width:200px"><label class="small">Paciente</label>
          <select name="paciente_id" class="input"><option value="">Todos</option><?php while($p=$pacientes->fetch_assoc()): ?><option value="<?php echo $p['id'];?>" <?php if($pid==$p['id']) echo 'selected'; ?>><?php echo htmlspecialchars($p['nombre']);?></option><?php endwhile; ?></select>
        </div>
        <div style="flex:1;min-width:200px"><label class="small">Doctor</label>
          <select name="doctor_id" class="input"><option value="">Todos</option><?php while($d=$doctores->fetch_assoc()): ?><option value="<?php echo $d['id'];?>" <?php if($did==$d['id']) echo 'selected'; ?>><?php echo htmlspecialchars($d['nombre']);?></option><?php endwhile; ?></select>
        </div>
        <div style="flex:1;min-width:160px"><label class="small">Desde</label><input type="date" name="desde" value="<?php echo htmlspecialchars($desde);?>" class="input"></div>
        <div style="flex:1;min-width:160px"><label class="small">Hasta</label><input type="date" name="hasta" value="<?php echo htmlspecialchars($hasta);?>" class="input"></div>
        <div style="align-self:end"><button class="btn" type="submit" style="background:linear-gradient(90deg,var(--accent),#9a3f10);color:#fff;padding:10px 12px;border-radius:8px">Filtrar</button></div>
      </form>

      <table class="table"><thead><tr><th>Paciente</th><th>Doctor</th><th>Diagnóstico</th><th>Fecha</th></tr></thead><tbody>
        <?php while($r=$res->fetch_assoc()): ?>
          <tr><td><?php echo htmlspecialchars($r['paciente']);?></td><td><?php echo htmlspecialchars($r['doctor']);?></td><td><?php echo htmlspecialchars(substr($r['diagnostico'],0,120));?></td><td><?php echo $r['fecha'];?></td></tr>
        <?php endwhile; ?>
      </tbody></table>
    </div>
  </main>
</body>
</html>