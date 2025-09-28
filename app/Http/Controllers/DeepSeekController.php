<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

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
                return response()->json([
                    'error' => 'No se pudo procesar el documento predefinido'
                ], 500);
            }

            // Send question with pre-loaded context
            $response = $this->askQuestionWithContext($documentContext, $request->question, $sessionId);

            return response()->json([
                'response' => $response,
                'session_id' => $sessionId,
                'context_loaded' => true
            ]);

        } catch (\Exception $e) {
            \Log::error('DeepSeek Error Interno', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Error interno',
                'exception_message' => $e->getMessage()
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
            \Log::error("Error procesando documento: " . $e->getMessage());
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
            return 'Por el momento no puedo responder esa pregunta, para más información visita el sitio web de ITCA-FEPADE o visita ' . self::INFO_CHAT_DOCUMENT;

        } catch (\Exception $e) {
            \Log::error("Error enviando pregunta para sesión {$sessionId}: " . $e->getMessage());
            return 'Error procesando la consulta. Por favor, intenta nuevamente.';
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
}