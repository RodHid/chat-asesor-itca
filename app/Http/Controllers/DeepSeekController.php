<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use App\Models\ChatSession;
use App\Models\ChatInteraction;

class DeepSeekController extends Controller
{
    // URL del documento predefinido
    const INFO_CHAT_DOCUMENT = 'https://www.itca.edu.sv/wp-content/uploads/2024/10/GuiaEstudiantil2025_compressed.pdf';

    public function processFixedDocument(Request $request)
    {
        $request->validate([
            'question' => 'required|string|max:1000',
            'session_id' => 'nullable|string|max:100'
        ]);

        try {
            // Generate or use provided session ID
            $sessionId = $request->session_id ?: 'session_' . Str::random(10);
            
            // Get or create document context
            $documentContext = $this->getOrCreateDocumentContext($sessionId);
            
            if (!$documentContext) {
                $errorMessage = "❌ **Error de Documento**\n\n";
                $errorMessage .= "No se pudo descargar o procesar el documento de ITCA-FEPADE.\n\n";
                $errorMessage .= "**Posibles causas:**\n";
                $errorMessage .= "- Problema de conectividad con el servidor de documentos\n";
                $errorMessage .= "- El documento PDF no está disponible temporalmente\n";
                $errorMessage .= "- Error en el procesamiento del contenido\n\n";
                $errorMessage .= "**Solución:** Intenta nuevamente en unos minutos o contacta al soporte técnico.\n\n";
                $errorMessage .= "**URL del documento:** " . self::INFO_CHAT_DOCUMENT;

                return response()->json([
                    'error' => 'No se pudo procesar el documento predefinido',
                    'response' => $errorMessage,
                    'session_id' => $sessionId
                ], 500);
            }

            // Track start time for response measurement
            $startTime = microtime(true);

            // Send question with pre-loaded context
            $response = $this->askQuestionWithContext($documentContext, $request->question, $sessionId);

            // Calculate response time
            $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

            // Optionally log the interaction to database
            $this->logChatInteraction($sessionId, $request->question, $response, $responseTime, $documentContext);

            return response()->json([
                'response' => $response,
                'session_id' => $sessionId,
                'context_loaded' => true,
                'response_time_ms' => round($responseTime)
            ]);

        } catch (\Exception $e) {
            \Log::error('DeepSeek Error Interno', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Create detailed error message for chat response
            $errorDetails = [
                'error_type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'session_id' => $request->session_id ?? 'no-session',
                'timestamp' => now()->toISOString()
            ];

            // Format error for chat display
            $chatErrorMessage = "❌ **Error del Sistema**\n\n";
            $chatErrorMessage .= "**Tipo:** " . $errorDetails['error_type'] . "\n";
            $chatErrorMessage .= "**Mensaje:** " . $errorDetails['message'] . "\n";
            $chatErrorMessage .= "**Archivo:** " . $errorDetails['file'] . " (línea " . $errorDetails['line'] . ")\n";
            $chatErrorMessage .= "**Sesión ID:** " . $errorDetails['session_id'] . "\n";
            $chatErrorMessage .= "**Hora:** " . $errorDetails['timestamp'] . "\n\n";
            $chatErrorMessage .= "*Por favor, reporta este error al equipo de desarrollo.*";

            return response()->json([
                'error' => 'Error interno del sistema',
                'response' => $chatErrorMessage,
                'debug_info' => $errorDetails,
                'session_id' => $request->session_id ?? null
            ], 500);
        }
    }

    /**
     * Obtiene o crea el contexto del documento para una sesión
     */
    private function getOrCreateDocumentContext($sessionId)
    {
        $cacheKey = "document_context_{$sessionId}";
        
        // Check if context already exists
        $context = Cache::get($cacheKey);
        if ($context) {
            \Log::info("Contexto encontrado en cache para sesión: {$sessionId}");
            return $context;
        }

        // Process document for the first time
        \Log::info("Procesando documento por primera vez para sesión: {$sessionId}");
        
        try {
            $pdfUrl = self::INFO_CHAT_DOCUMENT;
            
            // Download PDF
            $response = Http::timeout(30)->get($pdfUrl);
            if (!$response->successful()) {
                \Log::error("Error descargando PDF: " . $response->status());
                return null;
            }

            // Verify content type
            $contentType = $response->header('Content-Type');
            if (strpos($contentType, 'pdf') === false) {
                \Log::error("Contenido no es PDF válido");
                return null;
            }

            // Save and parse PDF
            $tempFileName = 'temp_' . Str::random(10) . '.pdf';
            Storage::disk('local')->put($tempFileName, $response->body());
            $filePath = Storage::disk('local')->path($tempFileName);

            $parser = new Parser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();

            // Clean up temporary file
            Storage::disk('local')->delete($tempFileName);

            // Create context with pre-loaded instructions
            $context = [
                'document_text' => $text,
                'document_url' => $pdfUrl,
                'processed_at' => now()->toISOString(),
                'total_length' => strlen($text)
            ];

            // Cache context for 2 hours
            Cache::put($cacheKey, $context, now()->addHours(2));
            
            \Log::info("Contexto creado y cacheado para sesión: {$sessionId}");
            return $context;

        } catch (\Exception $e) {
            \Log::error("Error procesando documento: " . $e->getMessage(), [
                'session_id' => $sessionId,
                'pdf_url' => $pdfUrl,
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return null;
        }
    }

    /**
     * Envía pregunta usando el contexto pre-cargado
     */
    private function askQuestionWithContext($context, $question, $sessionId)
    {
        try {
            // Prepare the system message with full document context and instructions
            $systemMessage = "Eres un experto asistente educativo especializado en ITCA-FEPADE. "
                . "Tienes acceso completo al siguiente documento oficial de la institución:\n\n"
                . "DOCUMENTO: Guía Estudiantil ITCA-FEPADE 2025\n"
                . "CONTENIDO DEL DOCUMENTO:\n" . $context['document_text'] . "\n\n"
                . "INSTRUCCIONES IMPORTANTES:\n"
                . "- Responde ÚNICAMENTE basado en la información del documento proporcionado\n"
                . "- Responde siempre en español\n"
                . "- Sé preciso, detallado y útil\n"
                . "- Si la información específica no está en el documento, responde: 'Por el momento no puedo responder esa pregunta, para más información visita el sitio web de ITCA-FEPADE o visita " . self::INFO_CHAT_DOCUMENT . "'\n"
                . "- No inventes información que no esté en el documento\n"
                . "- Cita secciones relevantes cuando sea apropiado\n"
                . "- Proporciona respuestas completas y bien estructuradas\n"
                . "- Usa la información exacta del documento proporcionado\n"
                . "- FORMATO DE RESPUESTA: Usa formato Markdown para mejorar la legibilidad:\n"
                . "  * Usa **negrita** para información importante\n"
                . "  * Usa *cursiva* para énfasis\n"
                . "  * Usa listas con - o números para organizar información\n"
                . "  * Usa ## para títulos de sección cuando sea apropiado\n"
                . "  * Usa > para citas del documento\n"
                . "  * Organiza la información de manera clara y fácil de leer";

            $payload = [
                'model' => 'deepseek-chat',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemMessage
                    ],
                    [
                        'role' => 'user',
                        'content' => $question
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 1500
            ];

            // Send to DeepSeek API
            $deepseekResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('DEEPSEEK_API_KEY'),
                'Content-Type' => 'application/json',
            ])->timeout(90)->post('https://api.deepseek.com/v1/chat/completions', $payload);

            if ($deepseekResponse->successful()) {
                $responseData = $deepseekResponse->json();
                $content = $responseData['choices'][0]['message']['content'] ?? null;
                
                if ($content) {
                    \Log::info("Respuesta exitosa para sesión: {$sessionId}");
                    return $content;
                }
            }

            \Log::warning("Error en respuesta de DeepSeek para sesión {$sessionId}: " . $deepseekResponse->body());
            
            // Create detailed API error message
            $apiError = "❌ **Error de API de DeepSeek**\n\n";
            $apiError .= "**Status Code:** " . $deepseekResponse->status() . "\n";
            $apiError .= "**Sesión:** " . $sessionId . "\n\n";
            
            // Try to parse error details
            $errorData = $deepseekResponse->json();
            if (isset($errorData['error'])) {
                $apiError .= "**Detalle del Error:**\n";
                $apiError .= "- **Tipo:** " . ($errorData['error']['type'] ?? 'No especificado') . "\n";
                $apiError .= "- **Mensaje:** " . ($errorData['error']['message'] ?? 'No especificado') . "\n";
                if (isset($errorData['error']['code'])) {
                    $apiError .= "- **Código:** " . $errorData['error']['code'] . "\n";
                }
            } else {
                $apiError .= "**Respuesta del servidor:** " . substr($deepseekResponse->body(), 0, 200) . "...\n";
            }
            
            $apiError .= "\n*Por favor, intenta nuevamente. Si el problema persiste, contacta al soporte técnico.*";
            
            return $apiError;

        } catch (\Exception $e) {
            \Log::error("Error enviando pregunta para sesión {$sessionId}: " . $e->getMessage());
            
            $contextError = "❌ **Error de Contexto**\n\n";
            $contextError .= "**Tipo:** " . get_class($e) . "\n";
            $contextError .= "**Mensaje:** " . $e->getMessage() . "\n";
            $contextError .= "**Sesión:** " . $sessionId . "\n";
            $contextError .= "**Archivo:** " . basename($e->getFile()) . " (línea " . $e->getLine() . ")\n\n";
            $contextError .= "**Posibles causas:**\n";
            $contextError .= "- Timeout en la conexión con la API\n";
            $contextError .= "- Problema con el contexto del documento\n";
            $contextError .= "- Error en el formato de la solicitud\n\n";
            $contextError .= "*Intenta hacer una nueva pregunta o reinicia la conversación.*";
            
            return $contextError;
        }
    }

    /**
     * Divide el texto en fragmentos manejables respetando palabras completas
     */
    private function splitTextIntoChunks($text, $maxLength)
    {
        $chunks = [];
        $textLength = strlen($text);
        $currentPosition = 0;

        while ($currentPosition < $textLength) {
            $chunkSize = min($maxLength, $textLength - $currentPosition);
            $chunk = substr($text, $currentPosition, $chunkSize);

            // Si no es el último fragmento, buscar el último espacio para no cortar palabras
            if ($currentPosition + $chunkSize < $textLength) {
                $lastSpace = strrpos($chunk, ' ');
                if ($lastSpace !== false && $lastSpace > $chunkSize * 0.8) {
                    $chunk = substr($chunk, 0, $lastSpace);
                    $chunkSize = $lastSpace;
                }
            }

            $chunks[] = trim($chunk);
            $currentPosition += $chunkSize;
        }

        return $chunks;
    }

    /**
     * Procesa un fragmento de texto individual con DeepSeek
     */
    private function processTextChunk($textChunk, $question, $chunkNumber, $totalChunks)
    {
        try {
            // Preparar prompt para DeepSeek con contexto del fragmento
            $customPrompt = "Responde ÚNICAMENTE basado en el siguiente fragmento de texto extraído del documento PDF (fragmento {$chunkNumber} de {$totalChunks}). "
            . "Si la información específica para responder la pregunta no está en este fragmento, responde: 'Por el momento no puedo responder esa pregunta, para más información visita el sitio web de ITCA-FEPADE o visita " . self::INFO_CHAT_DOCUMENT . "'.\n\n"
            . "Fragmento del documento:\n" . $textChunk . "\n\n"
            . "Pregunta: " . $question . "\n\n"
            . "Instrucciones:\n"
            . "- Responde ÚNICAMENTE basado en el fragmento de texto proporcionado\n"
            . "- Responde siempre en español\n"
            . "- Sé preciso y conciso\n"
            . "- No inventes información\n"
            . "- Responde solo con información del fragmento proporcionado\n"
            . "- Responde utilizando la información exacta del documento proporcionado\n"
            . "- Cita secciones relevantes si es posible\n"
            . "- Si encuentras información relevante, proporciona una respuesta completa y detallada";

            $payload = [
                'model' => 'deepseek-chat',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Eres un experto en comprensión de documentos PDF. Usa exclusivamente el fragmento de texto proporcionado para responder.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $customPrompt
                    ]
                ],
                'temperature' => 0.3
            ];

            // Enviar a la API de DeepSeek
            $deepseekResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('DEEPSEEK_API_KEY'),
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.deepseek.com/v1/chat/completions', $payload);

            if ($deepseekResponse->successful()) {
                $responseData = $deepseekResponse->json();
                return $responseData['choices'][0]['message']['content'] ?? null;
            }

            \Log::warning("Error procesando fragmento {$chunkNumber}: " . $deepseekResponse->body());
            return null;

        } catch (\Exception $e) {
            \Log::error("Error procesando fragmento {$chunkNumber}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Limpia el contexto de una sesión específica
     */
    public function clearSession(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string|max:100'
        ]);

        $cacheKey = "document_context_{$request->session_id}";
        Cache::forget($cacheKey);

        return response()->json([
            'message' => 'Sesión limpiada exitosamente',
            'session_id' => $request->session_id
        ]);
    }

    /**
     * Genera un mensaje de error formateado para mostrar en el chat
     */
    private function formatErrorForChat($title, $details = [])
    {
        $errorMessage = "❌ **{$title}**\n\n";
        
        foreach ($details as $key => $value) {
            $errorMessage .= "**" . ucfirst(str_replace('_', ' ', $key)) . ":** {$value}\n";
        }
        
        $errorMessage .= "\n*Si el problema persiste, contacta al equipo de soporte técnico.*";
        
        return $errorMessage;
    }

    /**
     * Endpoint para obtener información de debug (solo en desarrollo)
     */
    public function getDebugInfo(Request $request)
    {
        if (!app()->environment('local', 'development')) {
            return response()->json(['error' => 'Endpoint no disponible en producción'], 403);
        }

        $request->validate([
            'session_id' => 'nullable|string|max:100'
        ]);

        $sessionId = $request->session_id;
        $debugInfo = [
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'cache_driver' => config('cache.default'),
            'deepseek_api_configured' => !empty(env('DEEPSEEK_API_KEY')),
            'document_url' => self::INFO_CHAT_DOCUMENT,
            'timestamp' => now()->toISOString()
        ];

        if ($sessionId) {
            $cacheKey = "document_context_{$sessionId}";
            $context = Cache::get($cacheKey);
            $debugInfo['session_info'] = [
                'session_id' => $sessionId,
                'context_exists' => !is_null($context),
                'context_size' => $context ? strlen($context['document_text']) : 0,
                'cached_at' => $context['processed_at'] ?? null
            ];
        }

        return response()->json([
            'debug_info' => $debugInfo,
            'formatted_message' => $this->formatErrorForChat('Debug Information', $debugInfo)
        ]);
    }

    /**
     * Log chat interaction to database (optional)
     */
    private function logChatInteraction(string $sessionId, string $question, string $response, float $responseTimeMs, array $documentContext = null)
    {
        try {
            // Only log if database is properly configured
            if (config('database.default') === 'pgsql' && !empty(config('database.connections.pgsql.database'))) {
                
                // Find or create chat session
                $chatSession = ChatSession::findOrCreateBySessionId($sessionId);
                
                // Update session info if we have document context
                if ($documentContext && !$chatSession->document_processed_at) {
                    $chatSession->update([
                        'document_url' => $documentContext['document_url'] ?? null,
                        'document_length' => $documentContext['total_length'] ?? null,
                        'document_processed_at' => $documentContext['processed_at'] ?? now(),
                    ]);
                }

                // Increment question count
                $chatSession->incrementQuestions();

                // Log the interaction
                ChatInteraction::logInteraction(
                    $sessionId,
                    $question,
                    $response,
                    round($responseTimeMs),
                    str_contains($response, '❌') ? 'error' : 'success',
                    [
                        'document_loaded' => !is_null($documentContext),
                        'response_length' => strlen($response),
                    ]
                );

                \Log::info("Chat interaction logged for session: {$sessionId}");
            }
        } catch (\Exception $e) {
            // Don't let logging errors break the main functionality
            \Log::warning("Failed to log chat interaction: " . $e->getMessage());
        }
    }
}