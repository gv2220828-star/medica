<?php
// Conexión MySQL (ajusta credenciales si es necesario)
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'medica_db';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    // En producción maneja mejor los errores
    die("Conexión BD fallida: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
?>
