<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// token CSRF
if (empty($_SESSION['apply_ui_token'])) {
    $_SESSION['apply_ui_token'] = bin2hex(random_bytes(16));
}

// Handler robusto: aplicar/reanudar UI global (reemplaza el handler anterior)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_global_ui'], $_POST['token'])) {
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['ok' => false, 'modified' => [], 'skipped' => [], 'backed_up' => [], 'errors' => [], 'progress' => null];
    $token = (string)($_POST['token'] ?? '');
    if (!hash_equals($_SESSION['apply_ui_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'token inválido']);
        exit;
    }

    // Bloque a insertar (marcadores START/END)
    $block = <<<'HTML'
<!-- START: REDESIGN UI GLOBAL - DO NOT REMOVE -->
<style>
:root{ --bg:#fbfdfe; --surface:#ffffff; --muted:#62727b; --text:#071428;
--primary:#0b7bff; --accent:#ff6b6b; --success:#16a34a; --warning:#f59e0b; --danger:#dc2626;
--radius-lg:14px; --radius-md:10px; --radius-sm:8px; --shadow-1:0 8px 30px rgba(7,18,40,0.06); --shadow-2:0 20px 60px rgba(7,18,40,0.10);
--space:18px; --font-sans: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, Arial; --transition:180ms cubic-bezier(.2,.9,.2,1); }
body.theme-dark{ --bg:#061323; --surface:#071725; --muted:#9fb3c1; --text:#e6f2fb; --primary:#4aa3ff; --accent:#ff8b8b; }
/* Compact styles (global) */
*{box-sizing:border-box} html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:var(--font-sans);-webkit-font-smoothing:antialiased}
a{color:var(--primary);text-decoration:none}
.app-layout{display:flex;min-height:100vh} .sidebar{width:300px;padding:24px;background:linear-gradient(180deg,var(--surface),rgba(0,0,0,0.01));border-right:1px solid rgba(11,123,255,0.04)} .main{flex:1;padding:28px}
h1{font-size:36px;font-weight:800;margin:0;line-height:1.05} .card{background:var(--surface);padding:20px;border-radius:var(--radius-lg);box-shadow:var(--shadow-1);border:1px solid rgba(7,18,40,0.03)}
.btn{display:inline-flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:0;font-weight:800;cursor:pointer;transition:transform var(--transition),box-shadow var(--transition)}
.btn-primary{background:linear-gradient(90deg,var(--primary),#38a3ff);color:#fff;box-shadow:var(--shadow-1)}
.table-wrap{overflow:auto;border-radius:12px;border:1px solid rgba(7,18,40,0.04)} .ui-table{width:100%;border-collapse:collapse;min-width:880px;font-size:15px}
.ui-table thead th{background:linear-gradient(90deg,var(--primary),#38a3ff);color:#fff;padding:16px;text-align:left;font-weight:800;position:sticky;top:0;z-index:2}
.input, textarea, select{width:100%;padding:12px 14px;border-radius:10px;border:1px solid rgba(7,18,40,0.06);background:transparent;font-size:15px;color:var(--text)}
.modal{position:fixed;left:0;top:0;width:100%;height:100%;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,0.5);z-index:9999;padding:24px}
.pill{padding:8px 12px;border-radius:999px;background:linear-gradient(90deg,var(--primary),#38a3ff);color:#fff;font-weight:800}
.no-data{color:var(--muted);padding:18px;text-align:center} :focus{outline:3px solid rgba(11,123,255,0.14);outline-offset:2px}
@media (max-width:980px){ .sidebar{display:none} .ui-table{min-width:650px} }
</style>

<script>
(function(){ const key='ui-theme'; function apply(t){ if(t==='dark') document.body.classList.add('theme-dark'); else document.body.classList.remove('theme-dark'); }
const saved = localStorage.getItem(key) || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); apply(saved);
window.toggleTheme = function(){ const cur = document.body.classList.contains('theme-dark') ? 'dark' : 'light'; const next = cur==='dark' ? 'light' : 'dark'; apply(next); localStorage.setItem(key,next); };
if(!document.getElementById('skip-to-content')){ const a=document.createElement('a'); a.href='#main-content'; a.id='skip-to-content'; a.textContent='Saltar al contenido'; a.style.position='absolute'; a.style.left='-9999px'; document.documentElement.prepend(a); }
})();
</script>
<!-- END: REDESIGN UI GLOBAL -->
HTML;

    $root = realpath(__DIR__);
    $progressFile = $root . DIRECTORY_SEPARATOR . '.apply_ui_progress.json';

    // Recolectar lista de archivos .php aplicables (orden determinista)
    $all = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (strtolower($f->getExtension()) !== 'php') continue;
        $path = $f->getPathname();
        if (preg_match('#[\\\\/](PHPMailer|vendor)[\\\\/]#i', $path)) continue;
        if (realpath($path) === realpath(__FILE__)) continue;
        $all[] = $path;
    }
    sort($all, SORT_STRING);

    // cargar progreso si existe
    $startIndex = 0;
    if (is_file($progressFile)) {
        $data = @json_decode(@file_get_contents($progressFile), true);
        if (is_array($data) && isset($data['index'])) $startIndex = (int)$data['index'];
    }

    $resp['progress'] = ['total'=>count($all), 'startIndex'=>$startIndex];

    for ($i = $startIndex; $i < count($all); $i++) {
        $path = $all[$i];
        // guardar progreso inmediato
        @file_put_contents($progressFile, json_encode(['index'=>$i], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $content = @file_get_contents($path);
        if ($content === false) {
            $resp['errors'][$path] = 'no se pudo leer';
            continue;
        }
        if (strpos($content, 'START: REDESIGN UI GLOBAL') !== false) {
            $resp['skipped'][] = $path;
            continue;
        }
        // backup
        $bak = $path . '.bak';
        if (!@copy($path, $bak)) {
            $resp['errors'][$path] = 'no se pudo crear backup';
            continue;
        }
        $resp['backed_up'][] = $bak;

        // insertar
        $pos = strpos($content, '?>');
        if ($pos !== false) {
            $new = substr($content, 0, $pos + 2) . PHP_EOL . $block . PHP_EOL . substr($content, $pos + 2);
        } else {
            $new = $block . PHP_EOL . $content;
        }

        $written = @file_put_contents($path, $new, LOCK_EX);
        if ($written === false) {
            // restaurar
            @copy($bak, $path);
            $resp['errors'][$path] = 'no se pudo escribir (restaurado)';
            continue;
        }
        $resp['modified'][] = $path;
        // allow short pause if needed (avoid timeout)
        // usleep(20000);
    }

    // terminado: borrar progreso si todo fue procesado
    if (is_file($progressFile)) {
        @unlink($progressFile);
    }

    $resp['ok'] = true;
    echo json_encode($resp);
    exit;
}
?>
<!-- BOTÓN VISUAL para aplicar en todos los archivos (no altera lógica existente) -->
<div style="margin-top:12px">
  <button id="applyUiBtn" class="btn btn-ghost btn-sm" title="Aplicar apariencia en todos los archivos">Aplicar UI global</button>
  <span id="applyUiStatus" style="margin-left:12px;color:var(--muted);font-size:13px"></span>
</div>

<script>
document.getElementById('applyUiBtn').addEventListener('click', function(e){
    if (!confirm('Confirma aplicar el rediseño en todos los archivos PHP del proyecto (excluye PHPMailer).')) return;
    var btn = this;
    btn.disabled = true;
    var status = document.getElementById('applyUiStatus');
    status.textContent = 'Aplicando...';
    fetch(location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'apply_global_ui=1&token=' + encodeURIComponent('<?php echo $_SESSION['apply_ui_token']; ?>')
    }).then(function(r){ return r.json(); })
    .then(function(json){
        btn.disabled = false;
        if (json && json.ok) {
            status.textContent = 'Hecho. Archivos modificados: ' + (json.modified ? json.modified.length : 0);
            console.log('modified', json.modified, 'skipped', json.skipped, 'errors', json.errors);
            alert('Aplicación completada. Modificados: ' + (json.modified ? json.modified.length : 0) + '. Ver consola para detalle.');
        } else {
            status.textContent = 'Error';
            alert('Error aplicando: ' + (json && json.error ? json.error : 'unknown'));
            console.error(json);
        }
    }).catch(function(err){
        btn.disabled = false;
        status.textContent = 'Error de red';
        alert('Error de red: ' + err);
        console.error(err);
    });
});
</script>

<?php
// Normalizar rol (la BD usa 'rol')
$role = strtolower(trim($_SESSION['rol'] ?? $_SESSION['role'] ?? ''));

// Menús
$navPaciente = [
    ['href'=>'dashboard_paciente.php','label'=>'Inicio'],
    ['href'=>'agendar_cita.php','label'=>'Agendar cita'],
    ['href'=>'ver_registros.php','label'=>'Mis registros'],
    
];

$navDoctor = [
    ['href'=>'dashboard_doctor.php','label'=>'Inicio'],
    ['href'=>'agregar_receta.php','label'=>'Recetas'],
    ['href'=>'ver_registros.php','label'=>'Ver registros'],
    ['href'=>'registro_paciente.php','label'=>'Registrar paciente'],
    ['href'=>'registro_usuario.php','label'=>'Registrar usuario'],
    ['href'=>'reportes.php','label'=>'Reportes'],
    ['href'=>'generar_factura.php','label'=>'Generar factura'],
    ['href'=>'agendar_cita.php','label'=>'Agendar citas'],
    // añade aquí más enlaces que tu doctor necesite
];

function renderLink($item) {
    $activeClass = (basename($_SERVER['PHP_SELF']) === basename($item['href'])) ? ' active' : '';
    $href = htmlspecialchars($item['href']);
    $label = htmlspecialchars($item['label']);
    return "<a href=\"{$href}\" class=\"sb-link{$activeClass}\">{$label}</a>";
}
?>
<style>
/* Estilos mínimos y organizados para el sidebar */
.sidebar {
    width:260px;
    background:#0b2545;
    color:#fff;
    padding:20px;
    box-sizing:border-box;
    position:fixed;
    left:0;
    top:0;
    bottom:0;
    z-index:1000;
    overflow:auto;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}
.sidebar .brand { margin-bottom:18px; }
.sidebar .brand h2 { margin:0; font-size:18px; letter-spacing:0.6px; }
.sidebar .profile { margin-top:18px; display:flex; align-items:center; gap:10px; }
.profile .avatar {
    width:42px; height:42px; border-radius:8px; background:#21314a; display:inline-flex; align-items:center; justify-content:center;
    font-weight:700; color:#fff;
}
.nav-wrap { margin-top:8px; }
.sb-link { display:block; color:#fff; padding:10px; border-radius:6px; text-decoration:none; margin-bottom:6px; transition:background .12s; }
.sb-link:hover { background:rgba(255,255,255,0.04); }
.sb-link.active { background:rgba(255,255,255,0.06); }
.sidebar .logout { display:block; color:#fff; padding:10px; border-radius:6px; text-decoration:none; background:#d6453b; text-align:center; }
.sidebar-note { font-size:12px; color:rgba(255,255,255,0.7); margin-top:12px; }
.sidebar-spacer { width:260px; flex-shrink:0; }
@media (max-width:800px){ .sidebar { position:relative; width:100%; } .sidebar-spacer { width:100%; } }
</style>

<aside class="sidebar" role="navigation" aria-label="Menú principal">
    <div>
        <div class="brand">
            <h2>MEDICA</h2>
            <p style="margin:6px 0 0;font-size:13px;opacity:.9;">
                <?php echo htmlspecialchars($_SESSION['nombre'] ?? ($_SESSION['user_id'] ?? 'Usuario')); ?><br>
                <small style="opacity:.8"><?php echo htmlspecialchars(ucfirst($role)); ?></small>
            </p>
        </div>

        <nav class="nav-wrap" aria-label="Navegación">
            <?php
            if ($role === 'paciente') {
                foreach ($navPaciente as $item) echo renderLink($item);
            } else {
                foreach ($navDoctor as $item) echo renderLink($item);
            }
            ?>
        </nav>
    </div>

    <div>
        <a href="logout.php" class="logout" role="button">Cerrar sesión</a>
        <?php if ($role === 'paciente'): ?>
            <p class="sidebar-note">Solo puedes ver y gestionar tus citas y registros.</p>
        <?php else: ?>
            <p class="sidebar-note">Menú de Doctor — accesos administrativos y de atención.</p>
        <?php endif; ?>
    </div>
</aside>

<!-- Espacio para que el contenido principal no quede bajo el sidebar -->
<div class="sidebar-spacer" aria-hidden="true"></div>