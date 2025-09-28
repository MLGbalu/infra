<?php

class Scoring {
    private $rules;
    
    public function __construct($rulesPath) {
        $this->loadRules($rulesPath);
    }
    
    /**
     * Load scoring rules from JSON file
     */
    private function loadRules($rulesPath) {
        if (!file_exists($rulesPath)) {
            throw new Exception("Scoring rules file not found: {$rulesPath}");
        }
        
        $rulesJson = file_get_contents($rulesPath);
        $this->rules = json_decode($rulesJson, true);
        
        if (!$this->rules) {
            throw new Exception("Invalid scoring rules JSON");
        }
    }
    
    /**
     * Calculate risk score based on collected data
     */
    public function calculateScore($data) {
        $score = 0;
        
        // Country-based scoring
        if (isset($data['country_code']) && isset($this->rules['country_blocklist'][$data['country_code']])) {
            $score += $this->rules['country_blocklist'][$data['country_code']];
        }
        
        // IPQS-based scoring
        if (isset($data['ipqs'])) {
            $ipqs = $data['ipqs'];
            
            // VPN/Proxy detection
            if ($ipqs['vpn'] && isset($this->rules['ipqs_vpn_weight'])) {
                $score += $this->rules['ipqs_vpn_weight'];
            }
            
            if ($ipqs['proxy'] && isset($this->rules['ipqs_proxy_weight'])) {
                $score += $this->rules['ipqs_proxy_weight'];
            }
            
            // TOR detection
            if ($ipqs['tor'] && isset($this->rules['ipqs_tor_weight'])) {
                $score += $this->rules['ipqs_tor_weight'];
            }
            
            // Bot detection
            if ($ipqs['bot_status'] && isset($this->rules['ipqs_bot_weight'])) {
                $score += $this->rules['ipqs_bot_weight'];
            }
            
            // Recent abuse
            if ($ipqs['recent_abuse'] && isset($this->rules['ipqs_abuse_weight'])) {
                $score += $this->rules['ipqs_abuse_weight'];
            }
            
            // Fraud score scaling
            if (isset($ipqs['fraud_score']) && isset($this->rules['ipqs_fraud_multiplier'])) {
                $score += ($ipqs['fraud_score'] * $this->rules['ipqs_fraud_multiplier']);
            }
        }
        
        // ASN-based scoring
        if (isset($data['asn']) && isset($this->rules['asn_suspicious'])) {
            $asnKey = 'AS' . $data['asn'];
            if (isset($this->rules['asn_suspicious'][$asnKey])) {
                $score += $this->rules['asn_suspicious'][$asnKey];
            }
        }
        
        // User Agent-based scoring
        if (isset($data['user_agent']) && isset($this->rules['ua_bot_patterns'])) {
            foreach ($this->rules['ua_bot_patterns'] as $pattern => $weight) {
                if (preg_match('/' . preg_quote($pattern, '/') . '/i', $data['user_agent'])) {
                    $score += $weight;
                    break; // Only apply first matching pattern
                }
            }
        }
        
        // Time-based scoring (unusual hours)
        if (isset($this->rules['time_based_scoring']) && $this->rules['time_based_scoring']['enabled']) {
            $hour = (int)date('H');
            $suspiciousHours = $this->rules['time_based_scoring']['suspicious_hours'] ?? [];
            if (in_array($hour, $suspiciousHours)) {
                $score += $this->rules['time_based_scoring']['weight'] ?? 10;
            }
        }
        
        // Rate limiting penalty
        if (isset($data['rate_limit_exceeded']) && $data['rate_limit_exceeded']) {
            $score += $this->rules['rate_limit_penalty'] ?? 50;
        }
        
        // Ensure score is within bounds
        return max(0, min(100, $score));
    }
    
    /**
     * Calculate challenge bonus score based on browser signals
     */
    public function calculateChallengeScore($signals) {
        $score = 0;
        
        if (!isset($this->rules['challenge_rules'])) {
            return $this->rules['challenge_bonus'] ?? 20; // Default bonus
        }
        
        $challengeRules = $this->rules['challenge_rules'];
        
        // WebDriver detection
        if (isset($signals['webdriver']) && $signals['webdriver'] === true) {
            $score += $challengeRules['webdriver_penalty'] ?? 30;
        }
        
        // Screen size analysis
        if (isset($signals['screen_width']) && isset($signals['screen_height'])) {
            $width = $signals['screen_width'];
            $height = $signals['screen_height'];
            
            // Common bot screen sizes
            $botSizes = $challengeRules['bot_screen_sizes'] ?? [
                '1024x768', '800x600', '1280x1024'
            ];
            
            $screenSize = $width . 'x' . $height;
            if (in_array($screenSize, $botSizes)) {
                $score += $challengeRules['bot_screen_penalty'] ?? 20;
            }
        }
        
        // Mouse movement detection
        if (isset($signals['mouse_movements']) && $signals['mouse_movements'] < 1) {
            $score += $challengeRules['no_mouse_penalty'] ?? 25;
        }
        
        // Touch support mismatch
        if (isset($signals['touch_support']) && isset($signals['user_agent'])) {
            $isMobileUA = preg_match('/(Mobile|Android|iPhone|iPad)/i', $signals['user_agent']);
            if ($isMobileUA && !$signals['touch_support']) {
                $score += $challengeRules['touch_mismatch_penalty'] ?? 15;
            }
        }
        
        // Timezone mismatch
        if (isset($signals['timezone_offset']) && isset($this->rules['expected_timezones'])) {
            $expectedOffsets = $this->rules['expected_timezones'];
            if (!in_array($signals['timezone_offset'], $expectedOffsets)) {
                $score += $challengeRules['timezone_penalty'] ?? 10;
            }
        }
        
        // Cookies disabled
        if (isset($signals['cookies_enabled']) && !$signals['cookies_enabled']) {
            $score += $challengeRules['no_cookies_penalty'] ?? 15;
        }
        
        return max(0, min(100, $score));
    }
    
    /**
     * Make final decision based on score
     */
    public function makeDecision($score) {
        $thresholds = $this->rules['decision_thresholds'] ?? [
            'deny' => 80,
            'challenge' => 40
        ];
        
        if ($score >= $thresholds['deny']) {
            return 'DENY';
        } elseif ($score >= $thresholds['challenge']) {
            return 'CHALLENGE';
        } else {
            return 'ALLOW';
        }
    }
}
