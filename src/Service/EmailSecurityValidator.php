<?php

namespace App\Service;

use Symfony\Component\Validator\Constraints as Assert;
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
    
    public function __construct(
        ValidatorInterface $validator,
        array $allowedDomains = [],
        int $maxBodyLength = 10000,
        int $maxRecipients = 5
    ) {
        $this->validator = $validator;
        $this->allowedDomains = $allowedDomains;
        $this->maxBodyLength = $maxBodyLength;
        $this->maxRecipients = $maxRecipients;
        
        // Patrones comunes de spam - Lista extendida y rigurosa
        $this->spamPatterns = [
            // Medicamentos y salud
            '/\b(viagra|cialis|levitra|pharmacy|prescription|pills|medication|weight.?loss|diet.?pills)\b/i',
            '/\b(enlargement|enhancement|potency|impotence|erectile)\b/i',
            
            // Finanzas y dinero
            '/\b(free money|make money fast|work from home|earn.?\$|quick.?cash|get.?rich)\b/i',
            '/\b(million dollars?|inheritance|beneficiary|offshore|tax.?haven)\b/i',
            '/\b(credit.?repair|debt.?free|consolidate.?debt|refinance.?now)\b/i',
            '/\b(investment.?opportunity|profit.?guarantee|risk.?free|double.?your.?money)\b/i',
            
            // Juegos y apuestas
            '/\b(casino|lottery|jackpot|winner|prize|congratulations.?you.?won)\b/i',
            '/\b(slot.?machine|poker|betting|gambling|odds)\b/i',
            
            // Llamadas a la acción urgentes/agresivas
            '/\b(buy now|click here|limited time|act now|order now|apply now)\b/i',
            '/\b(urgent|immediate|expires|don\'?t miss|last chance|hurry)\b/i',
            '/\b(once in a lifetime|exclusive deal|special promotion|limited offer)\b/i',
            '/\b(call now|subscribe now|sign up now|join now)\b/i',
            
            // Contenido adulto
            '/\b(xxx|adult|porn|sex|dating|singles|meet.?women|meet.?men)\b/i',
            '/\b(escort|webcam|live.?chat|hot.?girls)\b/i',
            
            // Esquemas y fraudes
            '/\b(mlm|multi.?level|pyramid|ponzi|get.?paid.?to)\b/i',
            '/\b(nigerian|prince|inheritance|unclaimed|beneficiary)\b/i',
            '/\b(wire.?transfer|western.?union|money.?gram|bitcoin.?wallet)\b/i',
            
            // Ofertas sospechosas
            '/\b(100%.?free|completely.?free|no.?cost|no.?fees|no.?obligation)\b/i',
            '/\b(guarantee|certified|approved|verified|authentic)\b/i',
            '/\b(trial|sample|gift|bonus|reward)\b/i',
            
            // Réplicas y falsificaciones
            '/\b(replica|knock.?off|designer.?copy|authentic.?copy|watches)\b/i',
            
            // SEO spam y enlaces
            '/\b(seo|search.?engine|rank.?first|increase.?traffic)\b/i',
            '/\b(unsubscribe|opt.?out|remove.?me)\b/i',
            
            // Phishing y seguridad
            '/\b(verify.?account|confirm.?identity|update.?information|suspended.?account)\b/i',
            '/\b(security.?alert|unusual.?activity|reset.?password|validate)\b/i',
            
            // Caracteres y patrones sospechosos
            '/\$\$\$+/',              // Múltiples signos de dólar
            '/!!+/',                  // Múltiples exclamaciones
            '/\?{3,}/',               // Múltiples interrogaciones
            '/[A-Z\s]{20,}/',         // Texto largo en mayúsculas
            '/(.)\1{5,}/',            // Repetición excesiva de caracteres
            
            // Números sospechosos
            '/\b\d{3,}-?\d{3,}-?\d{4,}\b/', // Posibles números de teléfono spam
            '/\$\d{4,}/',             // Cantidades grandes de dinero
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
        
        // Validar inyección de headers en subject
        if ($this->detectHeaderInjection($data['subject'] ?? '')) {
            $errors['subject'] = 'Posible inyección de headers detectada en el asunto';
        }
        
        // Validar longitud del contenido
        $bodyLength = strlen($data['body'] ?? '');
        if ($bodyLength > $this->maxBodyLength) {
            $errors['body'] = "El contenido excede el límite de {$this->maxBodyLength} caracteres";
        }
        
        // Detectar patrones de spam
        $spamDetected = $this->detectSpam($data['subject'] ?? '', $data['body'] ?? '');
        if ($spamDetected) {
            $errors['content'] = 'El contenido contiene patrones sospechosos de spam';
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
        $content = $subject . ' ' . $body;
        
        foreach ($this->spamPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        // Detectar exceso de mayúsculas (típico de spam)
        $upperCount = preg_match_all('/[A-Z]/', $content);
        $totalAlpha = preg_match_all('/[A-Za-z]/', $content);
        if ($totalAlpha > 0 && ($upperCount / $totalAlpha) > 0.5) {
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
