<?php
require_once '../config/.env.php';
require_once '../lib/auth.php';
require_once '../lib/scoring.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get client IP
function getClientIP() {
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

try {
    // Initialize components
    $auth = new Auth(HMAC_SECRET);
    $scoring = new Scoring('../config/scoring_rules.json');
    
    // Get request data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    $token = $data['token'] ?? '';
    $signals = $data['signals'] ?? [];
    
    if (empty($token)) {
        throw new Exception('Token is required');
    }
    
    // Verify referer for CSRF protection
    $domain = $_SERVER['HTTP_HOST'] ?? DOMAIN;
    if (!$auth->verifyReferer($domain)) {
        throw new Exception('Invalid referer');
    }
    
    // Verify token
    $tokenData = $auth->verifyToken($token);
    if (!$tokenData) {
        throw new Exception('Invalid or expired token');
    }
    
    // Verify IP matches token
    $clientIP = getClientIP();
    if ($tokenData['ip'] !== $clientIP) {
        throw new Exception('IP mismatch');
    }
    
    // Calculate challenge score based on browser signals
    $challengeScore = $scoring->calculateChallengeScore($signals);
    
    // Make decision based on challenge score
    $decision = $scoring->makeDecision($challengeScore);
    
    // Log the challenge result
    $logData = [
        'timestamp' => date('c'),
        'ip_address' => $clientIP,
        'token_valid' => true,
        'challenge_score' => $challengeScore,
        'decision' => $decision,
        'signals' => $signals
    ];
    
    $logLine = json_encode($logData) . "\n";
    file_put_contents('../logs/decisions.log', $logLine, FILE_APPEND | LOCK_EX);
    
    // Prepare response
    if ($decision === 'ALLOW') {
        // Build redirect URL with original parameters
        $redirectParams = $tokenData['redirect_params'] ?? [];
        $redirectUrl = buildKeitaroUrl($redirectParams);
        
        echo json_encode([
            'action' => 'allow',
            'redirect' => $redirectUrl
        ]);
    } else {
        echo json_encode([
            'action' => 'deny'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Verify endpoint error: " . $e->getMessage());
    
    // Log the error
    $logData = [
        'timestamp' => date('c'),
        'ip_address' => getClientIP(),
        'error' => $e->getMessage(),
        'token_valid' => false
    ];
    
    $logLine = json_encode($logData) . "\n";
    file_put_contents('../logs/decisions.log', $logLine, FILE_APPEND | LOCK_EX);
    
    http_response_code(400);
    echo json_encode([
        'action' => 'deny',
        'error' => 'Verification failed'
    ]);
}

/**
 * Build Keitaro tracker URL
 */
function buildKeitaroUrl($params) {
    $baseUrl = KEITARO_URL;
    $queryParams = array_filter($params); // Remove empty values
    
    if (!empty($queryParams)) {
        $baseUrl .= (strpos($baseUrl, '?') !== false ? '&' : '?') . http_build_query($queryParams);
    }
    
    return $baseUrl;
}
?>
