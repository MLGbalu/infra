<?php
require_once '../config/.env.php';
require_once '../lib/auth.php';
require_once '../lib/ipqs.php';
require_once '../lib/maxmind.php';
require_once '../lib/scoring.php';

// Start timing
$startTime = microtime(true);

// Initialize components
try {
    $redis = new Redis();
    $redis->connect(REDIS_HOST, REDIS_PORT);
    if (REDIS_PASSWORD) {
        $redis->auth(REDIS_PASSWORD);
    }
    
    $auth = new Auth(HMAC_SECRET);
    $ipqs = new IPQS(IPQS_API_KEY, $redis);
    $maxmind = new MaxMind();
    $scoring = new Scoring('../config/scoring_rules.json');
    
    // Database connection
    $pdo = new PDO(
        "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
} catch (Exception $e) {
    error_log("Initialization error: " . $e->getMessage());
    http_response_code(500);
    header("Location: /white.html");
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

$clientIP = getClientIP();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Collect parameters
$params = [
    'gclid' => $_GET['gclid'] ?? '',
    'clickid' => $_GET['clickid'] ?? '',
    'utm_source' => $_GET['utm_source'] ?? '',
    'utm_medium' => $_GET['utm_medium'] ?? '',
    'utm_campaign' => $_GET['utm_campaign'] ?? '',
    'utm_term' => $_GET['utm_term'] ?? '',
    'utm_content' => $_GET['utm_content'] ?? ''
];

// Quick User Agent check
$botPatterns = [
    '/bot/i', '/crawler/i', '/spider/i', '/curl/i', '/wget/i',
    '/python/i', '/java/i', '/go-http/i', '/ruby/i'
];

foreach ($botPatterns as $pattern) {
    if (preg_match($pattern, $userAgent)) {
        logDecision($pdo, $clientIP, $userAgent, $params, [], [], 100, 'DENY', '/white.html', microtime(true) - $startTime);
        header("Location: /white.html");
        exit;
    }
}

// Rate limiting check
$rateLimitKey = "rl:ip:{$clientIP}";
$currentCount = $redis->incr($rateLimitKey);
if ($currentCount === 1) {
    $redis->expire($rateLimitKey, 60); // 1 minute TTL
}

$rateLimitExceeded = false;
if ($currentCount > RATE_LIMIT_PER_MIN) {
    $rateLimitExceeded = true;
}

// Get IPQS data
$ipqsData = $ipqs->getIPQualityScore($clientIP);

// Get MaxMind data
$geoData = $maxmind->getGeoData($clientIP);

// Prepare scoring data
$scoringData = [
    'ip' => $clientIP,
    'user_agent' => $userAgent,
    'country_code' => $geoData['country_code'],
    'asn' => $geoData['asn'],
    'ipqs' => $ipqsData,
    'rate_limit_exceeded' => $rateLimitExceeded
];

// Calculate risk score
$riskScore = $scoring->calculateScore($scoringData);

// Make decision
$decision = $scoring->makeDecision($riskScore);

// Handle rate limit override
if ($rateLimitExceeded) {
    $decision = 'DENY';
    $riskScore = 100;
}

// Process decision
$redirectUrl = '';
$processingTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

switch ($decision) {
    case 'DENY':
        $redirectUrl = '/white.html';
        logDecision($pdo, $clientIP, $userAgent, $params, $ipqsData, $geoData, $riskScore, $decision, $redirectUrl, $processingTime);
        header("Location: {$redirectUrl}");
        break;
        
    case 'CHALLENGE':
        $token = $auth->generateToken($clientIP, $params);
        $redirectUrl = "/js_challenge.html?token=" . urlencode($token);
        logDecision($pdo, $clientIP, $userAgent, $params, $ipqsData, $geoData, $riskScore, $decision, $redirectUrl, $processingTime);
        header("Location: {$redirectUrl}");
        break;
        
    case 'ALLOW':
        $redirectUrl = buildKeitaroUrl($params);
        logDecision($pdo, $clientIP, $userAgent, $params, $ipqsData, $geoData, $riskScore, $decision, $redirectUrl, $processingTime);
        header("Location: {$redirectUrl}");
        break;
        
    default:
        $redirectUrl = '/white.html';
        logDecision($pdo, $clientIP, $userAgent, $params, $ipqsData, $geoData, $riskScore, 'ERROR', $redirectUrl, $processingTime);
        header("Location: {$redirectUrl}");
}

exit;

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

/**
 * Log decision to database and file
 */
function logDecision($pdo, $ip, $userAgent, $params, $ipqsData, $geoData, $score, $decision, $redirectUrl, $processingTime) {
    $logData = [
        'timestamp' => date('c'),
        'ip_address' => $ip,
        'user_agent' => $userAgent,
        'gclid' => $params['gclid'],
        'clickid' => $params['clickid'],
        'utm_source' => $params['utm_source'],
        'utm_medium' => $params['utm_medium'],
        'utm_campaign' => $params['utm_campaign'],
        'utm_term' => $params['utm_term'],
        'utm_content' => $params['utm_content'],
        'country' => $geoData['country_code'] ?? 'XX',
        'asn' => $geoData['asn'] ?? 0,
        'ipqs_score' => $ipqsData['fraud_score'] ?? 0,
        'risk_score' => $score,
        'decision' => $decision,
        'redirect_url' => $redirectUrl,
        'processing_time_ms' => round($processingTime, 2)
    ];
    
    // Log to file
    $logLine = json_encode($logData) . "\n";
    file_put_contents('../logs/decisions.log', $logLine, FILE_APPEND | LOCK_EX);
    
    // Log to database (handle errors gracefully)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO clicks (
                timestamp, ip_address, user_agent, gclid, clickid,
                utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                country, asn, ipqs_score, decision, redirect_url, processing_time_ms
            ) VALUES (
                NOW(), :ip, :ua, :gclid, :clickid,
                :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                :country, :asn, :ipqs_score, :decision, :redirect_url, :processing_time
            )
        ");
        
        $stmt->execute([
            'ip' => $ip,
            'ua' => $userAgent,
            'gclid' => $params['gclid'],
            'clickid' => $params['clickid'],
            'utm_source' => $params['utm_source'],
            'utm_medium' => $params['utm_medium'],
            'utm_campaign' => $params['utm_campaign'],
            'utm_term' => $params['utm_term'],
            'utm_content' => $params['utm_content'],
            'country' => $geoData['country_code'] ?? 'XX',
            'asn' => $geoData['asn'] ?? 0,
            'ipqs_score' => $ipqsData['fraud_score'] ?? 0,
            'decision' => $decision,
            'redirect_url' => $redirectUrl,
            'processing_time' => round($processingTime, 2)
        ]);
    } catch (Exception $e) {
        error_log("Database logging error: " . $e->getMessage());
    }
}
?>
