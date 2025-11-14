<?php
require_once 'db.php';
if (session_status() == PHP_SESSION_NONE) session_start();

$mensaje = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registro_id = isset($_POST['registro_id']) ? (int)$_POST['registro_id'] : 0;
    $monto = isset($_POST['monto']) ? (float)$_POST['monto'] : 0;

    if ($registro_id === 0 || $monto <= 0) {
        $mensaje = 'Datos inválidos.';
    } else {
        try {
            // ===== LLAMADA DIRECTA A STRIPE API SIN COMPOSER =====
            $stripe_key = 'sk_test_TUCLAVESTRIPE'; // Reemplaza con tu clave secreta
            $url = 'https://api.stripe.com/v1/checkout/sessions';

            $post_data = http_build_query([
                'payment_method_types[]' => 'card',
                'line_items[0][price_data][currency]' => 'usd',
                'line_items[0][price_data][product_data][name]' => "Factura Registro #{$registro_id}",
                'line_items[0][price_data][unit_amount]' => (int)($monto * 100),
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'success_url' => 'http://localhost/practicas/medica/exito.php?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => 'http://localhost/practicas/medica/cancel.php',
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $stripe_key . ':',
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                $mensaje = 'Error de conexión: ' . $curl_error;
            } elseif ($httpCode !== 200) {
                $error_data = json_decode($response, true);
                $mensaje = 'Error Stripe: ' . ($error_data['error']['message'] ?? "HTTP {$httpCode}");
            } else {
                $session_data = json_decode($response, true);
                
                if (isset($session_data['id'])) {
                    // Guardar factura en BD
                    $stmt = $conn->prepare("INSERT INTO facturas (registro_id, monto, estado) VALUES (?, ?, 'pendiente')");
                    if ($stmt) {
                        $stmt->bind_param('id', $registro_id, $monto);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // Redirigir a Stripe Checkout
                    header('Location: ' . $session_data['url']);
                    exit;
                } else {
                    $mensaje = 'No se recibió ID de sesión de Stripe.';
                }
            }
        } catch (Exception $e) {
            $mensaje = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Generar Factura</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container" style="max-width:500px">
    <h3>Generar Factura con Pago Stripe</h3>
    <?php if ($mensaje): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($mensaje); ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <label class="form-label">Registro ID</label>
            <input type="number" name="registro_id" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Monto (USD)</label>
            <input type="number" name="monto" step="0.01" class="form-control" required>
        </div>
        <button class="btn btn-success">Pagar con Stripe</button>
        <a href="dashboard_doctor.php" class="btn btn-secondary">Volver</a>
    </form>
</div>
</body>
</html>