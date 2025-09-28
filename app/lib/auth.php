<?php

class Auth {
    private $hmacSecret;
    
    public function __construct($hmacSecret) {
        $this->hmacSecret = $hmacSecret;
    }
    
    /**
     * Generate HMAC token for challenge
     */
    public function generateToken($ip, $redirectParams = [], $ttl = 90) {
        $payload = [
            'ip' => $ip,
            'exp' => time() + $ttl,
            'nonce' => bin2hex(random_bytes(8)),
            'redirect_params' => $redirectParams
        ];
        
        $payloadJson = json_encode($payload);
        $payloadB64 = base64_encode($payloadJson);
        $signature = hash_hmac('sha256', $payloadJson, $this->hmacSecret);
        
        return $payloadB64 . '.' . $signature;
    }
    
    /**
     * Verify HMAC token
     */
    public function verifyToken($token) {
        if (empty($token)) {
            return false;
        }
        
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        
        list($payloadB64, $signature) = $parts;
        
        try {
            $payloadJson = base64_decode($payloadB64);
            $payload = json_decode($payloadJson, true);
            
            if (!$payload) {
                return false;
            }
            
            // Verify signature
            $expectedSignature = hash_hmac('sha256', $payloadJson, $this->hmacSecret);
            if (!hash_equals($expectedSignature, $signature)) {
                return false;
            }
            
            // Check expiration
            if (time() > $payload['exp']) {
                return false;
            }
            
            return $payload;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Verify referer header for CSRF protection
     */
    public function verifyReferer($expectedDomain) {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (empty($referer)) {
            return false;
        }
        
        $refererHost = parse_url($referer, PHP_URL_HOST);
        return $refererHost === $expectedDomain;
    }
}
