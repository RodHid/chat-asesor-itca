<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asesor vocacional</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --user-bubble: #d1e7dd;
            --bot-bubble: #e2e3e5;
            --primary-color: #4a6cf7;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .chat-container {
            height: 85vh;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1);
            background-color: white;
        }
        
        .chat-header {
            background: linear-gradient(135deg, var(--primary-color), #6c5ce7);
            color: white;
            padding: 18px 25px;
            position: relative;
        }
        
        .document-info {
            background-color: rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 10px;
            font-size: 0.9rem;
            backdrop-filter: blur(5px);
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background-color: #f8fafc;
            display: flex;
            flex-direction: column;
        }
        
        .message {
            max-width: 85%;
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .user-message {
            align-self: flex-end;
            background-color: var(--user-bubble);
            border-radius: 15px 15px 0 15px;
        }
        
        .bot-message {
            align-self: flex-start;
            background-color: var(--bot-bubble);
            border-radius: 15px 15px 15px 0;
        }
        
        .message-content {
            padding: 15px 18px;
        }
        
        .message-time {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 5px;
            text-align: right;
        }
        
        .chat-input {
            padding: 18px;
            background-color: white;
            border-top: 1px solid #e9ecef;
        }
        
        .pdf-icon {
            color: #e74c3c;
            font-size: 1.2rem;
            margin-right: 5px;
        }
        
        .typing-indicator {
            display: none;
            padding: 12px 15px;
            background-color: #f1f3f5;
            border-radius: 8px;
            margin-top: 10px;
            width: fit-content;
            align-self: flex-start;
        }
        
        .dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #6c757d;
            margin: 0 3px;
            animation: bounce 1.5s infinite;
        }
        
        .dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .dot:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        
        .status-badge {
            font-size: 0.8rem;
            padding: 4px 10px;
            border-radius: 12px;
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        .document-badge {
            background-color: #e74c3c;
            color: white;
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 4px;
            margin-right: 5px;
        }
        
        .welcome-card {
            background-color: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
        }
        
        .feature-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: #eef4ff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 1.5rem;
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                <div class="text-center mb-4">
                    <h1 class="fw-bold" style="color: #4a6cf7;">
                        <i class="bi bi-robot"></i> Asesor vocacional
                    </h1>
                </div>
                
                <div class="chat-container">
                    <div class="chat-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>Chat</h5>
                            </div>
                            <div>
                                <span class="status-badge">
                                    <i class="bi bi-circle-fill"></i> Conectado
                                </span>
                            </div>
                        </div>
                        
                        <div class="document-info mt-3" hidden>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-file-earmark-pdf pdf-icon"></i>
                                <strong>Documento actual:</strong>
                            </div>
                            <div class="mt-1 d-flex align-items-center">
                                <span class="document-badge">PDF</span>
                                <span id="documentUrl">documento-configurado.pdf</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="chat-messages" id="chatMessages">
                        <div class="message bot-message">
                            <div class="message-content">
                                <strong><i class="bi bi-robot"></i> Asistente:</strong>
                                <p class="mb-0">¡Hola! Estoy listo para responder tus preguntas sobre ITCA-FEPADE.</p>
                            </div>
                            <div class="message-time">Ahora</div>
                        </div>
                    </div>
                    
                    <div class="typing-indicator" id="typingIndicator">
                        <div class="d-inline-block">
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </div>
                        <span class="ms-2">Asistente está escribiendo...</span>
                    </div>
                    
                    <div class="chat-input">
                        <form id="documentForm">
                            <div class="input-group">
                                <textarea class="form-control" id="question" rows="1" 
                                    placeholder="Escribe tu pregunta sobre el documento..." 
                                    style="resize: none; min-height: 50px;" required></textarea>
                                <button class="btn btn-primary" type="submit" id="submitBtn">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </div>
                            <div class="mt-2 text-end">
                                <small class="text-muted">Presiona Enter para enviar, Shift+Enter para nueva línea</small>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Axios -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    
    <script>
        // URL del documento predefinida
        const DOCUMENT_URL = "{{ env('INFO_CHAT_DOCUMENT', 'https://www.itca.edu.sv/wp-content/uploads/2024/10/GuiaEstudiantil2025_compressed.pdf')}}";
        const DOCUMENT_NAME = DOCUMENT_URL.split('/').pop() || 'GuiaEstudiantil2025_compressed.pdf';
        
        // Mostrar nombre del documento en la interfaz
        document.getElementById('documentUrl').textContent = DOCUMENT_NAME;
        
        // Configurar textarea para permitir nueva línea con Shift+Enter
        const textarea = document.getElementById('question');
        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('documentForm').dispatchEvent(new Event('submit'));
            }
        });
        
        // Manejar envío del formulario
        document.getElementById('documentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const question = textarea.value.trim();
            if (!question) return;
            
            const chatMessages = document.getElementById('chatMessages');
            const typingIndicator = document.getElementById('typingIndicator');
            const submitBtn = document.getElementById('submitBtn');
            
            // Deshabilitar botón mientras se procesa
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass"></i>';
            
            // Mostrar mensaje del usuario
            const userMessageHtml = `
                <div class="message user-message">
                    <div class="message-content">
                        <strong><i class="bi bi-person"></i> Tú:</strong>
                        <p class="mb-0">${question}</p>
                    </div>
                    <div class="message-time">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                </div>
            `;
            
            chatMessages.insertAdjacentHTML('beforeend', userMessageHtml);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            
            // Mostrar indicador de escritura
            typingIndicator.style.display = 'block';
            chatMessages.scrollTop = chatMessages.scrollHeight;
            
            // Limpiar campo de entrada
            textarea.value = '';
            textarea.style.height = 'auto';
            
            // Enviar solicitud al servidor
            axios.post('/process-fixed-document', {
                question: question
            })
            .then(response => {
                // Ocultar indicador de escritura
                typingIndicator.style.display = 'none';
                
                // Mostrar respuesta del asistente
                const botMessageHtml = `
                    <div class="message bot-message">
                        <div class="message-content">
                            <strong><i class="bi bi-robot"></i> Asistente:</strong>
                            <p class="mb-0">${response.data.response}</p>
                        </div>
                        <div class="message-time">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                    </div>
                `;
                
                chatMessages.insertAdjacentHTML('beforeend', botMessageHtml);
                chatMessages.scrollTop = chatMessages.scrollHeight;
                
                // Restaurar botón
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
            })
            .catch(error => {
                // Ocultar indicador de escritura
                typingIndicator.style.display = 'none';
                
                // Mostrar mensaje de error
                const errorMessageHtml = `
                    <div class="message bot-message">
                        <div class="message-content">
                            <strong><i class="bi bi-robot"></i> Asistente:</strong>
                            <p class="mb-0 text-danger">❌ Error: ${error.response?.data?.error || 'Error al procesar la solicitud'}</p>
                        </div>
                        <div class="message-time">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                    </div>
                `;
                
                chatMessages.insertAdjacentHTML('beforeend', errorMessageHtml);
                chatMessages.scrollTop = chatMessages.scrollHeight;
                
                // Restaurar botón
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
            });
        });
        
        // Auto-ajustar altura del textarea
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        
        // Auto-scroll al cargar
        window.onload = function() {
            const chatMessages = document.getElementById('chatMessages');
            chatMessages.scrollTop = chatMessages.scrollHeight;
        };
    </script>
</body>
</html>