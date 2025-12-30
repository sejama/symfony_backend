<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Servicio para limitar el envío de emails y prevenir spam/abuso
 * Implementa rate limiting basado en IP con persistencia en archivos
 */
class EmailRateLimiter
{
    private string $storageDir;
    private int $maxEmailsPerHour;
    private int $maxEmailsPerDay;
    private int $maxEmailsPerMinute;
    
    public function __construct(
        string $cacheDir = null,
        int $maxEmailsPerMinute = 1,
        int $maxEmailsPerHour = 3,
        int $maxEmailsPerDay = 5
    ) {
        $this->storageDir = $cacheDir ?? sys_get_temp_dir() . '/email_rate_limiter';
        $this->maxEmailsPerMinute = $maxEmailsPerMinute;
        $this->maxEmailsPerHour = $maxEmailsPerHour;
        $this->maxEmailsPerDay = $maxEmailsPerDay;
        
        // Crear directorio si no existe
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }
    
    /**
     * Verifica si se permite enviar un email desde esta IP
     * 
     * @param Request $request
     * @return array ['allowed' => bool, 'reason' => string|null, 'retryAfter' => int|null]
     */
    public function isAllowed(Request $request): array
    {
        $ip = $this->getClientIp($request);
        $now = time();
        
        // Limpiar registros antiguos
        $this->cleanOldRecords($ip);
        
        // Obtener historial de envíos
        $history = $this->getHistory($ip);
        
        // Verificar límite por minuto
        $lastMinute = array_filter($history, fn($timestamp) => $timestamp > ($now - 60));
        if (count($lastMinute) >= $this->maxEmailsPerMinute) {
            return [
                'allowed' => false,
                'reason' => "Límite de {$this->maxEmailsPerMinute} emails por minuto excedido",
                'retryAfter' => 60
            ];
        }
        
        // Verificar límite por hora
        $lastHour = array_filter($history, fn($timestamp) => $timestamp > ($now - 3600));
        if (count($lastHour) >= $this->maxEmailsPerHour) {
            return [
                'allowed' => false,
                'reason' => "Límite de {$this->maxEmailsPerHour} emails por hora excedido",
                'retryAfter' => 3600
            ];
        }
        
        // Verificar límite por día
        $lastDay = array_filter($history, fn($timestamp) => $timestamp > ($now - 86400));
        if (count($lastDay) >= $this->maxEmailsPerDay) {
            return [
                'allowed' => false,
                'reason' => "Límite de {$this->maxEmailsPerDay} emails por día excedido",
                'retryAfter' => 86400
            ];
        }
        
        return ['allowed' => true, 'reason' => null, 'retryAfter' => null];
    }
    
    /**
     * Registra un envío de email exitoso
     */
    public function recordSent(Request $request): void
    {
        $ip = $this->getClientIp($request);
        $history = $this->getHistory($ip);
        $history[] = time();
        $this->saveHistory($ip, $history);
    }
    
    /**
     * Obtiene la IP del cliente considerando proxies
     */
    private function getClientIp(Request $request): string
    {
        // Intentar obtener IP real detrás de proxies/load balancers
        $ip = $request->headers->get('X-Forwarded-For');
        if ($ip) {
            // X-Forwarded-For puede contener múltiples IPs, usar la primera
            $ips = explode(',', $ip);
            return trim($ips[0]);
        }
        
        return $request->getClientIp() ?? '0.0.0.0';
    }
    
    /**
     * Obtiene el historial de envíos de una IP
     */
    private function getHistory(string $ip): array
    {
        $file = $this->getStorageFile($ip);
        if (!file_exists($file)) {
            return [];
        }
        
        $content = file_get_contents($file);
        $history = json_decode($content, true);
        
        return is_array($history) ? $history : [];
    }
    
    /**
     * Guarda el historial de envíos
     */
    private function saveHistory(string $ip, array $history): void
    {
        $file = $this->getStorageFile($ip);
        file_put_contents($file, json_encode($history));
    }
    
    /**
     * Limpia registros antiguos (más de 24 horas)
     */
    private function cleanOldRecords(string $ip): void
    {
        $history = $this->getHistory($ip);
        $now = time();
        $cleaned = array_filter($history, fn($timestamp) => $timestamp > ($now - 86400));
        
        if (count($cleaned) !== count($history)) {
            $this->saveHistory($ip, $cleaned);
        }
    }
    
    /**
     * Obtiene la ruta del archivo de almacenamiento para una IP
     */
    private function getStorageFile(string $ip): string
    {
        $hash = md5($ip);
        return $this->storageDir . '/' . $hash . '.json';
    }
    
    /**
     * Obtiene estadísticas de uso para una IP
     */
    public function getStats(Request $request): array
    {
        $ip = $this->getClientIp($request);
        $history = $this->getHistory($ip);
        $now = time();
        
        $lastMinute = array_filter($history, fn($t) => $t > ($now - 60));
        $lastHour = array_filter($history, fn($t) => $t > ($now - 3600));
        $lastDay = array_filter($history, fn($t) => $t > ($now - 86400));
        
        return [
            'ip' => $ip,
            'sentLastMinute' => count($lastMinute),
            'sentLastHour' => count($lastHour),
            'sentLastDay' => count($lastDay),
            'remainingMinute' => max(0, $this->maxEmailsPerMinute - count($lastMinute)),
            'remainingHour' => max(0, $this->maxEmailsPerHour - count($lastHour)),
            'remainingDay' => max(0, $this->maxEmailsPerDay - count($lastDay)),
        ];
    }
}
