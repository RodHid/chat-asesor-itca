<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

class DeepSeekController extends Controller
{
    // URL del documento predefinido
    const INFO_CHAT_DOCUMENT = 'https://www.itca.edu.sv/wp-content/uploads/2024/10/GuiaEstudiantil2025_compressed.pdf';

    public function processFixedDocument(Request $request)
    {
        $request->validate([
            'question' => 'required|string|max:1000'
        ]);

        try {
            $pdfUrl = self::INFO_CHAT_DOCUMENT;
            $fileName = basename($pdfUrl) ?: 'document.pdf';

            // Descargar el PDF desde la URL
            $response = Http::timeout(30)->get($pdfUrl);

            if (!$response->successful()) {
                return response()->json([
                    'error' => 'No se pudo descargar el documento predefinido',
                    'details' => $response->status()
                ], 400);
            }

            // Verificar que sea un PDF
            $contentType = $response->header('Content-Type');
            if (strpos($contentType, 'pdf') === false) {
                return response()->json(['error' => 'El documento predefinido no es un PDF válido'], 400);
            }

            // Guardar temporalmente el PDF en disco local
            $tempFileName = 'temp_' . Str::random(10) . '.pdf';
            Storage::disk('local')->put($tempFileName, $response->body());
            \Log::info('Archivo guardado: ' . $tempFileName);

            // Obtener la ruta absoluta usando Storage
            $filePath = Storage::disk('local')->path($tempFileName);
            \Log::info('Ruta real obtenida por Storage: ' . $filePath);

            // Validar que el archivo exista usando Storage
            if (!Storage::disk('local')->exists($tempFileName)) {
                \Log::error('Archivo temporal NO encontrado con Storage en: ' . $tempFileName);
                return response()->json(['error' => 'Archivo temporal no encontrado en el servidor'], 500);
            }

            // Leer archivo
            $content = file_get_contents($filePath);
            \Log::info('Archivo leído correctamente, tamaño: ' . strlen($content));

            // Parsear el PDF y extraer texto
            $parser = new Parser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();

            // Eliminar archivo temporal después de extraer texto
            Storage::disk('local')->delete($tempFileName);

            // Dividir el texto en fragmentos manejables
            $maxLength = 30000;
            $textChunks = $this->splitTextIntoChunks($text, $maxLength);

            // Procesar cada fragmento y buscar la respuesta
            $finalResponse = null;
            $responses = [];

            foreach ($textChunks as $index => $chunk) {
                $chunkResponse = $this->processTextChunk($chunk, $request->question, $index + 1, count($textChunks));
                
                if ($chunkResponse) {
                    $responses[] = $chunkResponse;
                    
                    // Si encontramos una respuesta específica (no el mensaje por defecto), la usamos
                    if (!str_contains($chunkResponse, 'Por el momento no puedo responder esa pregunta')) {
                        $finalResponse = $chunkResponse;
                        break; // Salir del bucle si encontramos una respuesta válida
                    }
                }
            }

            // Si no encontramos respuesta específica en ningún fragmento, combinar respuestas o dar mensaje por defecto
            if (!$finalResponse) {
                if (empty($responses)) {
                    $finalResponse = 'Por el momento no puedo responder esa pregunta, para más información visita el sitio web de ITCA-FEPADE o visita ' . self::INFO_CHAT_DOCUMENT;
                } else {
                    // Si todas las respuestas son el mensaje por defecto, usar una sola
                    $finalResponse = $responses[0];
                }
            }

            return response()->json([
                'response' => $finalResponse,
                'chunks_processed' => count($textChunks),
                'total_length' => strlen($text)
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
            . "- Da la respuesta como texto plano, sin ningún tipo de formato\n"
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
}