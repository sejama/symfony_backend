<?php

namespace App\Service;

use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validador de seguridad para contenido de emails
 * Previene spam, inyección de headers y contenido malicioso
 */
class EmailSecurityValidator
{
    private ValidatorInterface $validator;
    private array $spamPatterns;
    private array $allowedDomains;
    private int $maxBodyLength;
    private int $maxRecipients;
    private bool $spamCheckEnabled;
    
    public function __construct(
        ValidatorInterface $validator,
        array $allowedDomains = [],
        int $maxBodyLength = 10000,
        int $maxRecipients = 5,
        bool $spamCheckEnabled = true
    ) {
        $this->validator = $validator;
        $this->allowedDomains = $allowedDomains;
        $this->maxBodyLength = $maxBodyLength;
        $this->maxRecipients = $maxRecipients;
        $this->spamCheckEnabled = $spamCheckEnabled;
        
        // Patrones de spam refinados para evitar falsos positivos en emails de negocios
        $this->spamPatterns = [
            // Medicamentos ilegales y spam farmacéutico
            '/\b(viagra|cialis|levitra|online.?pharmacy|cheap.?pills|weight.?loss.?pills)\b/i',
            '/\b(enlargement.?pills|potency.?booster)\b/i',
            
            // Finanzas y estafas piramidales
            '/\b(free money|make money fast|work from home earn \$|quick.?cash.?now|get.?rich.?quick)\b/i',
            '/\b(million dollars? inheritance|unclaimed beneficiary|offshore bank account)\b/i',
            '/\b(credit.?repair guarantee|consolidate.?debt fast|refinance.?now.?instant)\b/i',
            '/\b(investment.?opportunity 100%|profit.?guarantee|risk.?free double.?your.?money)\b/i',
            
            // Juegos y apuestas ilegítimas
            '/\b(online.?casino jackpot|free spins winner|lottery winner claim)\b/i',
            
            // Llamadas a la acción agresivas / Phishing
            '/\b(buy now limited offer|click here to claim free|once in a lifetime deal)\b/i',
            '/\b(act now before it expires|urgent account suspended|click here to reset password)\b/i',
            
            // Contenido adulto
            '/\b(xxx video|adult dating singles|meet.?hot.?girls|escort webcam)\b/i',
            
            // Esquemas de estafas clásicas
            '/\b(nigerian.?prince|wire transfer western union unclaimed)\b/i',
            '/\b(send bitcoin to wallet|crypto investment guarantee)\b/i',
            
            // Caracteres y patrones abusivos
            '/\$\$\$+/',              // Múltiples signos de dólar consecutivos
            '/!{4,}/',                // 4 o más signos de exclamación consecutivos
            '/\?{4,}/',               // 4 o más interrogaciones consecutivas
            '/([^\s])\1{6,}/',       // Repetición excesiva del mismo caracter (>6 veces)
        ];
    }
    
    /**
     * Valida el contenido del email por seguridad
     * 
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate(array $data): array
    {
        $errors = [];
        
        // Validar trampa anti-bots (Honeypot) si se envía desde formulario frontend
        if (!empty($data['_hp']) || !empty($data['website_url_hp'])) {
            return [
                'valid' => false,
                'errors' => ['security' => 'Detección automática de spam activada']
            ];
        }
        
        // Validar inyección de headers en subject
        if ($this->detectHeaderInjection($data['subject'] ?? '')) {
            $errors['subject'] = 'Posible inyección de headers detectada en el asunto';
        }
        
        // Validar longitud del contenido
        $bodyLength = strlen($data['body'] ?? '');
        if ($bodyLength > $this->maxBodyLength) {
            $errors['body'] = "El contenido excede el límite de {$this->maxBodyLength} caracteres";
        }
        
        // Detectar patrones de spam (si está habilitado)
        if ($this->spamCheckEnabled) {
            $spamDetected = $this->detectSpam($data['subject'] ?? '', $data['body'] ?? '');
            if ($spamDetected) {
                $errors['content'] = 'El contenido contiene patrones sospechosos de spam';
            }
        }
        
        // Validar número de destinatarios
        $recipientCount = $this->countRecipients($data);
        if ($recipientCount > $this->maxRecipients) {
            $errors['recipients'] = "Número máximo de destinatarios excedido (máximo: {$this->maxRecipients})";
        }
        
        // Validar dominios permitidos si está configurado
        if (!empty($this->allowedDomains)) {
            $domainErrors = $this->validateAllowedDomains($data);
            if (!empty($domainErrors)) {
                $errors = array_merge($errors, $domainErrors);
            }
        }
        
        // Validar que no haya URLs sospechosas
        if ($this->detectSuspiciousUrls($data['body'] ?? '')) {
            $errors['body'] = 'El contenido contiene URLs potencialmente maliciosas';
        }
        
        // Validar caracteres peligrosos en campos críticos
        if ($this->hasDangerousChars($data['subject'] ?? '')) {
            $errors['subject'] = 'El asunto contiene caracteres no permitidos';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Detecta intentos de inyección de headers (CRLF injection)
     */
    private function detectHeaderInjection(string $value): bool
    {
        // Buscar saltos de línea que podrían inyectar headers adicionales
        return preg_match('/[\r\n]/', $value) === 1;
    }
    
    /**
     * Detecta patrones comunes de spam
     */
    private function detectSpam(string $subject, string $body): bool
    {
        // Normalizar contenido para evitar falsos positivos por indentación HTML.
        $plainBody = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = trim($subject . ' ' . $plainBody);
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;
        
        foreach ($this->spamPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        // Detectar exceso de mayúsculas (típico de spam), evitando falsos positivos en mensajes cortos.
        $upperCount = preg_match_all('/[A-Z]/', $content);
        $totalAlpha = preg_match_all('/[A-Za-z]/', $content);
        if ($totalAlpha >= 120 && ($upperCount / $totalAlpha) > 0.7) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Cuenta el total de destinatarios (to + cc + bcc)
     */
    private function countRecipients(array $data): int
    {
        $count = 1; // "to" siempre existe
        
        if (isset($data['cc']) && is_array($data['cc'])) {
            $count += count($data['cc']);
        }
        
        if (isset($data['bcc']) && is_array($data['bcc'])) {
            $count += count($data['bcc']);
        }
        
        return $count;
    }
    
    /**
     * Valida que los destinatarios pertenezcan a dominios permitidos
     */
    private function validateAllowedDomains(array $data): array
    {
        $errors = [];
        
        // Validar destinatario principal
        if (!$this->isAllowedDomain($data['to'] ?? '')) {
            $errors['to'] = 'El dominio del destinatario no está en la lista de permitidos';
        }
        
        // Validar CC
        if (isset($data['cc']) && is_array($data['cc'])) {
            foreach ($data['cc'] as $cc) {
                if (!$this->isAllowedDomain($cc)) {
                    $errors['cc'] = 'Uno o más dominios en CC no están permitidos';
                    break;
                }
            }
        }
        
        // Validar BCC
        if (isset($data['bcc']) && is_array($data['bcc'])) {
            foreach ($data['bcc'] as $bcc) {
                if (!$this->isAllowedDomain($bcc)) {
                    $errors['bcc'] = 'Uno o más dominios en BCC no están permitidos';
                    break;
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Verifica si un email pertenece a un dominio permitido
     */
    private function isAllowedDomain(string $email): bool
    {
        if (empty($this->allowedDomains)) {
            return true; // Si no hay whitelist, todos están permitidos
        }
        
        $domain = substr(strrchr($email, "@"), 1);
        return in_array($domain, $this->allowedDomains, true);
    }
    
    /**
     * Detecta URLs potencialmente maliciosas o acortadores de URL
     */
    private function detectSuspiciousUrls(string $content): bool
    {
        // Acortadores de URL comunes usados en spam
        $suspiciousDomains = ['bit.ly', 'tinyurl.com', 'goo.gl', 't.co'];
        
        foreach ($suspiciousDomains as $domain) {
            if (stripos($content, $domain) !== false) {
                return true;
            }
        }
        
        // Detectar exceso de URLs (típico de spam)
        $urlCount = preg_match_all('/(https?:\/\/[^\s]+)/', $content);
        if ($urlCount > 5) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Detecta caracteres potencialmente peligrosos
     */
    private function hasDangerousChars(string $value): bool
    {
        // Buscar caracteres nulos y de control
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1;
    }
    
    /**
     * Sanitiza el contenido HTML para prevenir XSS
     */
    public function sanitizeHtml(string $html): string
    {
        // Permitir solo tags seguros
        $allowedTags = '<p><br><strong><em><u><h1><h2><h3><h4><ul><ol><li><a>';
        return strip_tags($html, $allowedTags);
    }
}
