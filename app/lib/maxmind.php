<?php

class MaxMind {
    private $dbPath;
    private $reader;
    
    public function __construct($dbPath = '/usr/share/geoip/GeoLite2-City.mmdb') {
        $this->dbPath = $dbPath;
        $this->initReader();
    }
    
    /**
     * Initialize MaxMind reader
     */
    private function initReader() {
        if (file_exists($this->dbPath) && class_exists('GeoIp2\Database\Reader')) {
            try {
                $this->reader = new GeoIp2\Database\Reader($this->dbPath);
            } catch (Exception $e) {
                error_log("MaxMind DB error: " . $e->getMessage());
                $this->reader = null;
            }
        } else {
            $this->reader = null;
        }
    }
    
    /**
     * Get country and ASN data for IP
     */
    public function getGeoData($ip) {
        if (!$this->reader) {
            return $this->getStubGeoData($ip);
        }
        
        try {
            $record = $this->reader->city($ip);
            
            return [
                'country_code' => $record->country->isoCode ?? 'XX',
                'country_name' => $record->country->name ?? 'Unknown',
                'region' => $record->mostSpecificSubdivision->name ?? '',
                'city' => $record->city->name ?? '',
                'latitude' => $record->location->latitude ?? 0,
                'longitude' => $record->location->longitude ?? 0,
                'timezone' => $record->location->timeZone ?? 'UTC',
                'asn' => $this->getASN($ip),
                'organization' => $this->getOrganization($ip)
            ];
            
        } catch (Exception $e) {
            error_log("MaxMind lookup error for IP {$ip}: " . $e->getMessage());
            return $this->getStubGeoData($ip);
        }
    }
    
    /**
     * Get ASN information (requires ASN database)
     */
    private function getASN($ip) {
        $asnDbPath = '/usr/share/geoip/GeoLite2-ASN.mmdb';
        
        if (!file_exists($asnDbPath)) {
            return 0;
        }
        
        try {
            $asnReader = new GeoIp2\Database\Reader($asnDbPath);
            $record = $asnReader->asn($ip);
            return $record->autonomousSystemNumber ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get organization information
     */
    private function getOrganization($ip) {
        $asnDbPath = '/usr/share/geoip/GeoLite2-ASN.mmdb';
        
        if (!file_exists($asnDbPath)) {
            return 'Unknown';
        }
        
        try {
            $asnReader = new GeoIp2\Database\Reader($asnDbPath);
            $record = $asnReader->asn($ip);
            return $record->autonomousSystemOrganization ?? 'Unknown';
        } catch (Exception $e) {
            return 'Unknown';
        }
    }
    
    /**
     * Return stub geo data when MaxMind is not available
     */
    private function getStubGeoData($ip) {
        // Basic IP range detection for common cases
        $country = 'XX';
        
        // Simple heuristics for common IP ranges
        if (strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            $country = 'XX'; // Private IP
        } elseif (preg_match('/^(8\.8\.|1\.1\.)/', $ip)) {
            $country = 'US'; // Common DNS servers
        }
        
        return [
            'country_code' => $country,
            'country_name' => 'Unknown',
            'region' => 'Unknown',
            'city' => 'Unknown',
            'latitude' => 0,
            'longitude' => 0,
            'timezone' => 'UTC',
            'asn' => 0,
            'organization' => 'Unknown'
        ];
    }
    
    /**
     * Check if MaxMind database is available
     */
    public function isAvailable() {
        return $this->reader !== null;
    }
}
