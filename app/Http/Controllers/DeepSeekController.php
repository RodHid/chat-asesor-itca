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

            // Limitar el texto para evitar exceso de tokens (ejemplo: máximo 30000 caracteres)
            $maxLength = 30000;
            if (strlen($text) > $maxLength) {
                $text = substr($text, 0, $maxLength) . "\n\n[Texto truncado para cumplir con límite de tokens]";
            }

            // Eliminar archivo temporal después de extraer texto
            Storage::disk('local')->delete($tempFileName);

            // Preparar prompt para DeepSeek
            $customPrompt = "Responde ÚNICAMENTE basado en el siguiente texto extraído del documento PDF. "
            . "Si la información no está en el texto, responde: 'Por el momento no puedo responder esa pregunta, para mas información visita " . self::INFO_CHAT_DOCUMENT . "'.\n\n"
            . "Texto del documento:\n" . $text . "\n\n"
            . "Pregunta: " . $request->question . "\n\n"
            . "Instrucciones:\n"
            . "- Sé preciso y conciso\n"
            . "- No inventes información\n"
            . "- Cita secciones relevantes si es posible\n"
            . "- No utilices formato Markdown, ni HTML, ni símbolos como asteriscos, guiones dobles, comillas dobles o triples\n"
            . "- Da la respuesta como texto plano, sin ningún tipo de formato";

            $payload = [
                'model' => 'deepseek-chat',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Eres un experto en comprensión de documentos PDF. Usa exclusivamente el texto proporcionado.'
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
                $content = $deepseekResponse->json()['choices'][0]['message']['content'];
                return response()->json([
                    'response' => $content
                ]);
            }

            return response()->json([
                'error' => 'Error en la API de DeepSeek',
                'details' => $deepseekResponse->json()
            ], 500);

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
}