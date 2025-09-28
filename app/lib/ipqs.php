<?php

class IPQS {
    private $apiKey;
    private $redis;
    private $cachePrefix = 'ipqs:ip:';
    private $cacheTTL = 21600; // 6 hours
    
    public function __construct($apiKey, $redis) {
        $this->apiKey = $apiKey;
        $this->redis = $redis;
    }
    
    /**
     * Get IP quality score with caching
     */
    public function getIPQualityScore($ip) {
        // Check cache first
        $cacheKey = $this->cachePrefix . $ip;
        $cached = $this->redis->get($cacheKey);
        
        if ($cached) {
            return json_decode($cached, true);
        }
        
        // If no API key, return stub data
        if (empty($this->apiKey)) {
            $stubData = $this->getStubData($ip);
            $this->redis->setex($cacheKey, $this->cacheTTL, json_encode($stubData));
            return $stubData;
        }
        
        // Call IPQS API
        try {
            $apiData = $this->callIPQSAPI($ip);
            $this->redis->setex($cacheKey, $this->cacheTTL, json_encode($apiData));
            return $apiData;
        } catch (Exception $e) {
            error_log("IPQS API error: " . $e->getMessage());
            $stubData = $this->getStubData($ip);
            $this->redis->setex($cacheKey, 300, json_encode($stubData)); // Short cache on error
            return $stubData;
        }
    }
    
    /**
     * Call IPQS API
     */
    private function callIPQSAPI($ip) {
        $url = "https://ipqualityscore.com/api/json/ip/{$this->apiKey}/{$ip}";
        $params = [
            'strictness' => 1,
            'allow_public_access_points' => true,
            'fast' => true,
            'lighter_penalties' => false,
            'mobile' => true
        ];
        
        $url .= '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'AdCampaign/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            throw new Exception("IPQS API returned HTTP {$httpCode}");
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['success']) || !$data['success']) {
            throw new Exception("IPQS API returned invalid response");
        }
        
        return [
            'fraud_score' => $data['fraud_score'] ?? 0,
            'vpn' => $data['vpn'] ?? false,
            'proxy' => $data['proxy'] ?? false,
            'tor' => $data['tor'] ?? false,
            'bot_status' => $data['bot_status'] ?? false,
            'mobile' => $data['mobile'] ?? false,
            'recent_abuse' => $data['recent_abuse'] ?? false,
            'country_code' => $data['country_code'] ?? 'XX',
            'region' => $data['region'] ?? '',
            'city' => $data['city'] ?? '',
            'ISP' => $data['ISP'] ?? '',
            'ASN' => $data['ASN'] ?? 0,
            'organization' => $data['organization'] ?? '',
            'timezone' => $data['timezone'] ?? '',
            'connection_type' => $data['connection_type'] ?? ''
        ];
    }
    
    /**
     * Return stub data when API is not available
     */
    private function getStubData($ip) {
        return [
            'fraud_score' => 0,
            'vpn' => false,
            'proxy' => false,
            'tor' => false,
            'bot_status' => false,
            'mobile' => false,
            'recent_abuse' => false,
            'country_code' => 'XX',
            'region' => 'Unknown',
            'city' => 'Unknown',
            'ISP' => 'Unknown ISP',
            'ASN' => 0,
            'organization' => 'Unknown',
            'timezone' => 'UTC',
            'connection_type' => 'Unknown'
        ];
    }
}
