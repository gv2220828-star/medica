<?php

// Inicializa sesión y funciones de autorización
// Depuración: Verificar si session_start() tiene errores
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        error_log("Error al iniciar sesión en auth.php");
    }
}

// Función para depuración de sesión
function debug_session($message) {
    error_log($message . ": user_id=" . ($_SESSION['user_id'] ?? 'null') . ", rol=" . ($_SESSION['rol'] ?? 'null'));
}

function require_login() {
    debug_session("require_login");
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function require_role($roles) {
    debug_session("require_role");
    $rol_actual = trim(strtolower($_SESSION['rol'] ?? null)); // Normalizar rol
    if (is_string($roles)) $roles = [$roles];
    $roles = array_map('strtolower', $roles); // Normalizar roles requeridos
    if (!$rol_actual || !in_array($rol_actual, $roles, true)) {
        http_response_code(403);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>403</title></head><body style="font-family:Arial;padding:24px"><h2>403 - Acceso no autorizado</h2><p>No tiene permisos para ver esta página. Rol actual: ' . htmlspecialchars($rol_actual) . '</p><p><a href="login.php">Volver a iniciar sesión</a></p></body></html>';
        exit;
    }
}

// Lista de roles permitidos globalmente
$allowed_roles = ['admin', 'doctor', 'paciente'];

// Si usas este archivo para comprobar acceso, primero comprobar sesión
if (empty($_SESSION['user_id']) || (!isset($_SESSION['role']) && !isset($_SESSION['rol']))) {
    // dejar que la página muestre el mensaje de "No has iniciado sesión"
    return;
}

$role = strtolower(trim($_SESSION['role'] ?? $_SESSION['rol'] ?? ''));

if (!in_array($role, $allowed_roles, true)) {
    header('HTTP/1.1 403 Forbidden');
    echo '<h1>403 - Acceso no autorizado</h1>';
    echo '<p>No tiene permisos para ver esta página. Rol actual: ' . htmlspecialchars($role) . '</p>';
    echo '<p><a href="login.php">Volver a iniciar sesión</a></p>';
    exit;
}
?>