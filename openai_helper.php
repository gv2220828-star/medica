<?php
/**
 * openai_helper.php - Gemini IA Helper 
 * Incluye clave, funciones y obtenerRecomendacionIA con fallback extenso
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// === CLAVE API FIJA ===
if (!defined('GENERATIVE_API_KEY')) {
    define('GENERATIVE_API_KEY', 'AIzaSyDkFn8OcTvLqnr3tn8BLwUdJpkyTg0y-0M');
}

// === Función para obtener clave ===
if (!function_exists('gemini_get_key')) {
    function gemini_get_key(): ?string {
        if (defined('GENERATIVE_API_KEY') && GENERATIVE_API_KEY && strpos(GENERATIVE_API_KEY, 'AIzaSy') === 0) {
            return GENERATIVE_API_KEY;
        }
        return !empty($_SESSION['GENERATIVE_API_KEY']) ? trim($_SESSION['GENERATIVE_API_KEY']) : null;
    }
}

// === Función principal: obtener recomendación IA ===
if (!function_exists('obtenerRecomendacionIA')) {
    function obtenerRecomendacionIA(string $texto): array {
        $texto = trim($texto);
        if ($texto === '') {
            return ['error' => 'Texto vacío', 'error_code' => 400, 'raw' => ''];
        }

        $apiKey = gemini_get_key();
        if (empty($apiKey)) {
            return ['error' => 'API key no configurada', 'error_code' => 401, 'raw' => ''];
        }

        // Prompt mejorado y más específico
        $prompt = "Eres un asistente médico experto. Analiza el siguiente caso clínico y responde ÚNICAMENTE con un objeto JSON válido (sin markdown, sin bloques de código, solo el JSON puro) con esta estructura exacta:

{
  \"enfermedad\": \"diagnóstico probable basado en síntomas\",
  \"tratamiento\": \"plan de tratamiento específico y medicamentos recomendados\",
  \"consejos\": \"recomendaciones adicionales para el paciente\"
}

Caso clínico:
{$texto}

Responde solo con el JSON, sin texto adicional antes o después.";

        // TODOS LOS MODELOS DISPONIBLES en orden de prioridad (máximo fallback)
        $modelos = [
            // Gemini 2.5 (más potentes y recientes)
            'gemini-2.5-flash',
            'gemini-2.5-pro',
            'gemini-2.5-flash-lite',
            
            // Gemini 2.0 (estables)
            'gemini-2.0-flash',
            'gemini-2.0-flash-001',
            'gemini-2.0-flash-lite',
            'gemini-2.0-flash-lite-001',
            
            // Gemini 1.5 (fallback adicional si existen en tu cuenta)
            'gemini-1.5-flash',
            'gemini-1.5-flash-001',
            'gemini-1.5-flash-latest',
            'gemini-1.5-pro',
            'gemini-1.5-pro-001',
            'gemini-1.5-pro-latest',
            
            // Gemini 1.0 (legacy, última opción)
            'gemini-pro',
            'gemini-1.0-pro'
        ];

        $lastError = null;
        $lastRaw = '';
        $lastHttpCode = 0;
        
        // Intentar con cada modelo
        foreach ($modelos as $model) {
            // Configuración según el modelo
            if (strpos($model, '2.5-pro') !== false) {
                $maxTokens = 3000;
                $topK = 64;
            } elseif (strpos($model, '2.5') !== false) {
                $maxTokens = 2000;
                $topK = 64;
            } elseif (strpos($model, '2.0') !== false) {
                $maxTokens = 1500;
                $topK = 40;
            } else {
                $maxTokens = 1000;
                $topK = 40;
            }
            
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'topK' => $topK,
                    'topP' => 0.95,
                    'maxOutputTokens' => $maxTokens,
                    'stopSequences' => []
                ],
                'safetySettings' => [
                    [
                        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                        'threshold' => 'BLOCK_ONLY_HIGH'
                    ],
                    [
                        'category' => 'HARM_CATEGORY_HATE_SPEECH',
                        'threshold' => 'BLOCK_ONLY_HIGH'
                    ],
                    [
                        'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                        'threshold' => 'BLOCK_ONLY_HIGH'
                    ],
                    [
                        'category' => 'HARM_CATEGORY_HARASSMENT',
                        'threshold' => 'BLOCK_ONLY_HIGH'
                    ]
                ]
            ];

            $endpoint = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key=" . rawurlencode($apiKey);

            // Intentar hasta 2 veces con este modelo (con delay en caso de 503)
            for ($intento = 1; $intento <= 2; $intento++) {
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json; charset=utf-8'
                    ],
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_FOLLOWLOCATION => true
                ]);

                $resp = curl_exec($ch);
                $err = curl_error($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // Log para debugging
                error_log("Gemini API [{$model}] Intento {$intento}/{2}: HTTP {$http}");

                if ($err) {
                    $lastError = "Error de conexión: {$err}";
                    $lastRaw = '';
                    $lastHttpCode = 500;
                    continue;
                }

                // Si es 503 (sobrecargado) o 429 (rate limit), esperar y reintentar
                if ($http === 503 || $http === 429) {
                    $lastError = ($http === 503) ? "Modelo {$model} sobrecargado" : "Límite de tasa excedido";
                    $lastRaw = $resp;
                    $lastHttpCode = $http;
                    
                    if ($intento < 2) {
                        $delay = ($http === 503) ? 2 : 3;
                        error_log("Esperando {$delay} segundos antes de reintentar...");
                        sleep($delay);
                        continue;
                    } else {
                        // Pasar al siguiente modelo
                        error_log("Modelo {$model} no disponible tras {$intento} intentos, probando siguiente...");
                        break;
                    }
                }

                // Si es 404 (modelo no existe), pasar inmediatamente al siguiente
                if ($http === 404) {
                    $lastError = "Modelo {$model} no encontrado";
                    $lastRaw = $resp;
                    $lastHttpCode = $http;
                    error_log("Modelo {$model} no existe, probando siguiente...");
                    break; // No reintentar, pasar al siguiente modelo
                }

                // Si es 403 (sin permisos), no tiene sentido continuar con otros modelos
                if ($http === 403) {
                    $errorData = @json_decode($resp, true);
                    $errorMsg = isset($errorData['error']['message']) ? $errorData['error']['message'] : 'API key inválida o sin permisos';
                    return [
                        'error' => "HTTP 403: {$errorMsg}",
                        'error_code' => 403,
                        'raw' => $resp,
                        'hint' => 'Verifica que tu API key sea válida y tenga permisos para Gemini API en Google Cloud Console.'
                    ];
                }

                // Si es otro error HTTP, intentar con siguiente modelo
                if ($http !== 200) {
                    $errorData = @json_decode($resp, true);
                    $lastError = "HTTP {$http}";
                    
                    if (isset($errorData['error']['message'])) {
                        $lastError .= ": " . $errorData['error']['message'];
                    } elseif ($http === 400) {
                        $lastError .= ": Solicitud inválida";
                    }
                    
                    $lastRaw = is_string($resp) ? $resp : json_encode($resp);
                    $lastHttpCode = $http;
                    continue;
                }

                // ÉXITO HTTP 200 - procesar respuesta
                $responseData = @json_decode($resp, true);
                
                if (!$responseData) {
                    $lastError = 'Respuesta no es JSON válido';
                    $lastRaw = is_string($resp) ? substr($resp, 0, 500) : '';
                    $lastHttpCode = 502;
                    continue;
                }

                // Verificar estructura de respuesta
                if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                    $reason = $responseData['candidates'][0]['finishReason'] ?? 'unknown';
                    
                    if ($reason === 'SAFETY') {
                        return [
                            'error' => 'Respuesta bloqueada por filtros de seguridad',
                            'error_code' => 451,
                            'raw' => json_encode($responseData, JSON_UNESCAPED_UNICODE),
                            'hint' => 'El contenido fue bloqueado por políticas de seguridad. Intenta reformular los síntomas.'
                        ];
                    }
                    
                    // Si es MAX_TOKENS pero hay texto parcial, intentar usarlo
                    if ($reason === 'MAX_TOKENS' && isset($responseData['candidates'][0]['content']['parts'][0])) {
                        $partialText = '';
                        foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                            if (isset($part['text'])) {
                                $partialText .= $part['text'];
                            }
                        }
                        
                        if (!empty($partialText)) {
                            error_log("Gemini API: Respuesta truncada (MAX_TOKENS) pero procesable con modelo {$model}");
                            $generated = trim($partialText);
                            
                            // Limpiar respuesta
                            $generated = preg_replace('/^```json\s*/i', '', $generated);
                            $generated = preg_replace('/\s*```$/i', '', $generated);
                            $generated = trim($generated);
                            
                            // Intentar completar JSON incompleto
                            if (substr($generated, -1) !== '}') {
                                $lastComma = strrpos($generated, ',');
                                $lastQuote = strrpos($generated, '"');
                                
                                if ($lastQuote !== false && $lastQuote > $lastComma) {
                                    if (substr($generated, -1) !== '"') {
                                        $generated .= '"';
                                    }
                                }
                                $generated .= '}';
                            }
                            
                            // Parsear JSON
                            $inner = @json_decode($generated, true);
                            
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $generated, $matches)) {
                                    $inner = @json_decode($matches[0], true);
                                }
                                
                                if (!is_array($inner)) {
                                    $lastError = 'MAX_TOKENS: JSON incompleto no recuperable';
                                    $lastRaw = $generated;
                                    $lastHttpCode = 502;
                                    continue;
                                }
                            }
                            
                            // Validar campos requeridos
                            $enfermedad = trim($inner['enfermedad'] ?? '');
                            $tratamiento = trim($inner['tratamiento'] ?? '');
                            $consejos = trim($inner['consejos'] ?? '');
                            
                            if (!empty($enfermedad) || !empty($tratamiento) || !empty($consejos)) {
                                error_log("Gemini API: Éxito con respuesta parcial de modelo {$model}");
                                return [
                                    'enfermedad' => $enfermedad,
                                    'tratamiento' => $tratamiento,
                                    'consejos' => $consejos,
                                    'raw' => $generated,
                                    'model_used' => $model,
                                    'success' => true,
                                    'warning' => 'Respuesta truncada pero procesada correctamente'
                                ];
                            }
                        }
                    }
                    
                    $lastError = "Estructura de respuesta inválida. Razón: {$reason}";
                    $lastRaw = json_encode($responseData, JSON_UNESCAPED_UNICODE);
                    $lastHttpCode = 502;
                    continue;
                }

                $generated = trim($responseData['candidates'][0]['content']['parts'][0]['text']);

                if ($generated === '') {
                    $lastError = 'Respuesta vacía de la API';
                    $lastRaw = json_encode($responseData, JSON_UNESCAPED_UNICODE);
                    $lastHttpCode = 502;
                    continue;
                }

                // Limpiar respuesta (remover markdown si existe)
                $generated = preg_replace('/^```json\s*/i', '', $generated);
                $generated = preg_replace('/\s*```$/i', '', $generated);
                $generated = trim($generated);

                // Parsear JSON
                $inner = @json_decode($generated, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Intentar extraer JSON si está embebido en texto
                    if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $generated, $matches)) {
                        $inner = @json_decode($matches[0], true);
                    }
                    
                    if (!is_array($inner)) {
                        $lastError = 'JSON inválido en respuesta: ' . json_last_error_msg();
                        $lastRaw = $generated;
                        $lastHttpCode = 502;
                        continue;
                    }
                }

                // Validar campos requeridos
                $enfermedad = trim($inner['enfermedad'] ?? '');
                $tratamiento = trim($inner['tratamiento'] ?? '');
                $consejos = trim($inner['consejos'] ?? '');

                if (empty($enfermedad) && empty($tratamiento) && empty($consejos)) {
                    $lastError = 'Respuesta sin contenido útil';
                    $lastRaw = $generated;
                    $lastHttpCode = 502;
                    continue;
                }

                // ÉXITO TOTAL - devolver resultado
                error_log("Gemini API: ✓ Éxito con modelo {$model}");
                return [
                    'enfermedad' => $enfermedad,
                    'tratamiento' => $tratamiento,
                    'consejos' => $consejos,
                    'raw' => $generated,
                    'model_used' => $model,
                    'success' => true
                ];
            }
        }

        // Si llegamos aquí, ningún modelo funcionó
        $hintMessage = 'Todos los modelos están sobrecargados o no disponibles. ';
        if ($lastHttpCode === 503 || $lastHttpCode === 429) {
            $hintMessage .= 'Intenta de nuevo en 1-2 minutos.';
        } elseif ($lastHttpCode === 404) {
            $hintMessage .= 'Los modelos no están disponibles en tu región o cuenta.';
        } else {
            $hintMessage .= 'Verifica tu conexión y configuración de API.';
        }
        
        return [
            'error' => $lastError ?: 'Todos los modelos fallaron',
            'error_code' => $lastHttpCode ?: 503,
            'raw' => $lastRaw,
            'hint' => $hintMessage,
            'models_tried' => count($modelos)
        ];
    }
}
?>