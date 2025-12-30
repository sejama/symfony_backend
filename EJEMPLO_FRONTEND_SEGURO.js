/**
 * Ejemplo de implementación en el frontend para manejar las medidas de seguridad
 * Incluye verificación de límites antes de enviar y manejo de errores mejorado
 */

// Función para verificar los límites disponibles antes de enviar
async function checkEmailLimits() {
    try {
        const response = await fetch('http://localhost:8080/backend/public/api/email/stats');
        const data = await response.json();
        
        if (data.success) {
            return data.stats;
        }
        return null;
    } catch (error) {
        console.error('Error al verificar límites:', error);
        return null;
    }
}

// Función mejorada para enviar email con manejo de seguridad
async function sendSecureEmail(formData) {
    try {
        // 1. Verificar límites disponibles antes de enviar
        const stats = await checkEmailLimits();
        
        if (stats) {
            // Mostrar advertencia si está cerca del límite
            if (stats.remainingMinute === 0) {
                alert('Has alcanzado el límite de envíos por minuto. Por favor espera un momento.');
                return { success: false, reason: 'rate_limit' };
            }
            
            // Informar al usuario sobre los límites
            console.log(`Envíos disponibles: ${stats.remainingMinute} en el próximo minuto, ${stats.remainingHour} en la próxima hora`);
        }
        
        // 2. Enviar el email
        const response = await fetch('http://localhost:8080/backend/public/api/email/send', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        // 3. Manejar diferentes tipos de respuestas
        if (response.ok) {
            return { success: true, data: result };
        }
        
        // Manejar rate limit
        if (response.status === 429) {
            const retryMinutes = Math.ceil(result.retryAfter / 60);
            return {
                success: false,
                reason: 'rate_limit',
                message: `${result.message}. Por favor intenta nuevamente en ${retryMinutes} minuto(s).`
            };
        }
        
        // Manejar errores de validación o seguridad
        if (response.status === 400) {
            let errorMsg = 'Error de validación: ';
            
            if (result.errors) {
                // Mostrar todos los errores de validación
                errorMsg += Object.values(result.errors).join(', ');
            } else {
                errorMsg += result.error || result.message;
            }
            
            return {
                success: false,
                reason: 'validation',
                message: errorMsg
            };
        }
        
        // Error del servidor
        return {
            success: false,
            reason: 'server_error',
            message: result.message || 'Error al enviar el mensaje'
        };
        
    } catch (error) {
        console.error('Error al enviar email:', error);
        return {
            success: false,
            reason: 'network_error',
            message: 'Error de conexión. Por favor verifica tu conexión a internet.'
        };
    }
}

// Ejemplo de uso en el formulario
document.addEventListener('DOMContentLoaded', function () {
    const contactForm = document.getElementById('contactForm');
    const submitButton = document.getElementById('submitButton');
    const successMessage = document.getElementById('submitSuccessMessage');
    const errorMessage = document.getElementById('submitErrorMessage');
    
    if (contactForm) {
        // Mostrar límites disponibles al cargar la página (opcional)
        checkEmailLimits().then(stats => {
            if (stats && stats.remainingDay < 5) {
                console.warn(`Advertencia: Solo quedan ${stats.remainingDay} envíos disponibles hoy`);
            }
        });
        
        // Validación en tiempo real
        const inputs = contactForm.querySelectorAll('input, textarea');
        inputs.forEach(input => {
            input.addEventListener('input', () => {
                validateForm();
            });
        });
        
        // Validar formulario
        function validateForm() {
            const name = document.getElementById('name').value.trim();
            const empresa = document.getElementById('empresa').value.trim();
            const email = document.getElementById('email').value.trim();
            const phone = document.getElementById('phone').value.trim();
            const message = document.getElementById('message').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (name && empresa && emailRegex.test(email) && phone && message) {
                submitButton.classList.remove('disabled');
                return true;
            } else {
                submitButton.classList.add('disabled');
                return false;
            }
        }
        
        // Manejar el envío del formulario
        contactForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (!validateForm()) {
                return;
            }

            // Deshabilitar el botón mientras se envía
            submitButton.disabled = true;
            submitButton.textContent = 'Enviando...';
            
            // Ocultar mensajes previos
            successMessage.classList.add('d-none');
            errorMessage.classList.add('d-none');

            // Obtener los datos del formulario
            const name = document.getElementById('name').value.trim();
            const empresa = document.getElementById('empresa').value.trim();
            const email = document.getElementById('email').value.trim();
            const phone = document.getElementById('phone').value.trim();
            const message = document.getElementById('message').value.trim();

            // Construir el cuerpo del email en HTML
            const emailBody = `
                <h2>Nuevo mensaje de contacto desde el sitio web</h2>
                <p><strong>Nombre:</strong> ${name}</p>
                <p><strong>Empresa:</strong> ${empresa}</p>
                <p><strong>Email:</strong> ${email}</p>
                <p><strong>Teléfono:</strong> ${phone}</p>
                <p><strong>Mensaje:</strong></p>
                <p>${message.replace(/\n/g, '<br>')}</p>
            `;

            // Preparar datos del email
            const emailData = {
                to: 'contacto@taponesfos.com',
                subject: `Mensaje de contacto de ${name} - ${empresa}`,
                body: emailBody,
                from: email,
                isHtml: true
            };
            
            // Enviar con manejo de seguridad
            const result = await sendSecureEmail(emailData);
            
            if (result.success) {
                // Mostrar mensaje de éxito
                successMessage.classList.remove('d-none');
                // Limpiar el formulario
                contactForm.reset();
                submitButton.classList.add('disabled');
            } else {
                // Mostrar mensaje de error específico
                errorMessage.classList.remove('d-none');
                
                // Crear elemento para mostrar el error detallado
                const errorText = errorMessage.querySelector('div') || errorMessage;
                
                if (result.reason === 'rate_limit') {
                    errorText.textContent = result.message;
                    // Opcional: deshabilitar el formulario temporalmente
                    setTimeout(() => {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Enviar';
                    }, 60000); // Esperar 1 minuto
                } else if (result.reason === 'validation') {
                    errorText.textContent = result.message;
                } else {
                    errorText.textContent = result.message || 'Error al enviar el mensaje. Por favor intenta nuevamente.';
                }
            }
            
            // Rehabilitar el botón si no es rate limit
            if (result.reason !== 'rate_limit') {
                submitButton.disabled = false;
                submitButton.textContent = 'Enviar';
            }
        });
    }
});
