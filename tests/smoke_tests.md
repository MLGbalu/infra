# Smoke Tests and Acceptance Checklist

This document provides a comprehensive checklist for verifying that the advertising campaign infrastructure is working correctly after deployment.

## Prerequisites

- All Ansible roles have been deployed successfully
- Services are running on all three servers
- DNS/hosts file configured to point domain to the web server (192.168.0.209)

## Infrastructure Tests

### 1. Service Status Checks

**PostgreSQL Master (192.168.0.207)**
- [ ] PostgreSQL service is running: `systemctl status postgresql`
- [ ] Database `clicks_db` exists and is accessible
- [ ] User `clicks_user` can connect to the database
- [ ] `clicks` table exists with proper schema
- [ ] Replication user is configured

**PostgreSQL Slave (192.168.0.208)**
- [ ] PostgreSQL service is running
- [ ] Slave is in recovery mode: `SELECT pg_is_in_recovery();`
- [ ] Replication is active and streaming
- [ ] Data inserted on master appears on slave

**Web Server (192.168.0.209)**
- [ ] Nginx service is running: `systemctl status nginx`
- [ ] PHP-FPM service is running: `systemctl status php8.1-fpm`
- [ ] Redis service is running: `systemctl status redis-server`
- [ ] All required PHP extensions are loaded

### 2. Network and Security Tests

- [ ] UFW firewall is enabled and configured
- [ ] Only ports 22 and 80 are open to public
- [ ] Port 5432 is accessible between database servers
- [ ] fail2ban is running and configured
- [ ] SSH access works with keys

## Application Functionality Tests

### 3. Basic Web Access

```bash
# Test white page loads
curl -I http://domain.com/
# Expected: HTTP/1.1 200 OK

# Test white page content
curl -s http://domain.com/ | grep -i "welcome"
# Expected: Should contain welcome message
```

### 4. Bot Detection Tests

```bash
# Bot User-Agent should redirect to white page
curl -I -A "curl/7.68.0" "http://domain.com/route"
# Expected: HTTP/1.1 302 Found, Location: /white.html

curl -I -A "python-requests/2.28.1" "http://domain.com/route"
# Expected: HTTP/1.1 302 Found, Location: /white.html

curl -I -A "Googlebot/2.1" "http://domain.com/route"
# Expected: HTTP/1.1 302 Found, Location: /white.html
```

### 5. Human User-Agent Tests

```bash
# Human User-Agent should redirect to challenge
curl -I -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" "http://domain.com/route"
# Expected: HTTP/1.1 302 Found, Location: /js_challenge.html?token=...

curl -I -A "Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)" "http://domain.com/route"
# Expected: HTTP/1.1 302 Found, Location: /js_challenge.html?token=...
```

### 6. Parameter Handling Tests

```bash
# Test with UTM parameters
curl -I -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)" "http://domain.com/route?utm_source=test&utm_campaign=demo&gclid=abc123"
# Expected: HTTP/1.1 302 Found, Location: /js_challenge.html?token=...

# Parameters should be preserved in token for later redirect
```

### 7. Challenge Flow Tests

**Manual Browser Test:**
1. [ ] Open `http://domain.com/route` in a real browser
2. [ ] Should redirect to challenge page with loading animation
3. [ ] After 3-8 seconds, should redirect to Keitaro URL (or white page if configured to deny)
4. [ ] Check browser console for any JavaScript errors

**API Test:**
```bash
# Verify endpoint should reject GET requests
curl -I "http://domain.com/verify"
# Expected: HTTP/1.1 405 Method Not Allowed

# Verify endpoint should reject POST without data
curl -I -X POST "http://domain.com/verify"
# Expected: HTTP/1.1 400 Bad Request
```

### 8. Rate Limiting Tests

```bash
# Send multiple requests rapidly (should trigger rate limiting)
for i in {1..20}; do
  curl -s -I -A "curl/7.68.0" "http://domain.com/route" | head -n 1
  sleep 0.1
done
# Expected: After RATE_LIMIT_PER_MIN requests, should get rate limited
```

## Data and Logging Tests

### 9. Database Logging

```sql
-- Connect to PostgreSQL master
psql -h 192.168.0.207 -U clicks_user -d clicks_db

-- Check if clicks are being logged
SELECT COUNT(*) FROM clicks WHERE timestamp > NOW() - INTERVAL '1 hour';

-- Check recent decisions
SELECT ip_address, decision, timestamp FROM clicks ORDER BY timestamp DESC LIMIT 10;

-- Verify data types and constraints
\d clicks
```

### 10. File Logging

```bash
# Check if decision logs are being written
tail -f /var/www/domain.com/logs/decisions.log

# Make a test request and verify log entry appears
curl -A "test-agent" "http://domain.com/route"

# Check log format (should be valid JSON)
tail -n 1 /var/www/domain.com/logs/decisions.log | python3 -m json.tool
```

### 11. Redis Caching

```bash
# Connect to Redis and check for cached data
redis-cli

# Check for rate limiting keys
KEYS rl:ip:*

# Check for IPQS cache keys (if API key configured)
KEYS ipqs:ip:*

# Test key expiration
TTL rl:ip:127.0.0.1
```

## Performance and Monitoring Tests

### 12. Response Time Tests

```bash
# Measure response times
time curl -s -o /dev/null -A "Mozilla/5.0" "http://domain.com/route"

# Should complete in under 1 second for most requests
```

### 13. Log Rotation

```bash
# Check if logrotate is configured
ls -la /etc/logrotate.d/domain.com

# Test log rotation
logrotate -d /etc/logrotate.d/domain.com
```

### 14. Health Check Script

```bash
# Run the health check script
/usr/local/bin/app_health_check.sh

# Check health check logs
tail /var/log/app_health_check.log
```

## Security Tests

### 15. File Permissions

```bash
# Check application file permissions
ls -la /var/www/domain.com/
ls -la /var/www/domain.com/config/
ls -la /var/www/domain.com/logs/

# Config files should be 640, owned by app_user:www-data
# Log files should be 644, owned by app_user:www-data
```

### 16. Direct File Access

```bash
# These should be blocked by Nginx
curl -I "http://domain.com/config/.env.php"
# Expected: HTTP/1.1 403 Forbidden

curl -I "http://domain.com/logs/decisions.log"
# Expected: HTTP/1.1 403 Forbidden

curl -I "http://domain.com/lib/auth.php"
# Expected: HTTP/1.1 403 Forbidden
```

### 17. HMAC Token Security

**Manual Test:**
1. [ ] Get a challenge token from `/route`
2. [ ] Try to modify the token and use it in `/verify`
3. [ ] Should be rejected with "Invalid or expired token"

## Integration Tests

### 18. End-to-End Flow

**Complete User Journey:**
1. [ ] User visits `/route` with human User-Agent
2. [ ] Gets redirected to `/js_challenge.html?token=...`
3. [ ] JavaScript collects browser signals
4. [ ] POST to `/verify` with token and signals
5. [ ] Gets response with `action: "allow"` and redirect URL
6. [ ] Final redirect goes to Keitaro tracker URL

### 19. Failover Tests

```bash
# Test with PostgreSQL master down
sudo systemctl stop postgresql
curl -A "Mozilla/5.0" "http://domain.com/route"
# Should still work (log to file only)

# Test with Redis down
sudo systemctl stop redis-server
curl -A "Mozilla/5.0" "http://domain.com/route"
# Should still work (no caching/rate limiting)
```

## Acceptance Criteria

The system is considered ready for production when:

- [ ] All service status checks pass
- [ ] Bot detection works correctly (redirects to white page)
- [ ] Human detection works correctly (redirects to challenge)
- [ ] Challenge flow completes successfully
- [ ] Database logging works on both master and slave
- [ ] File logging produces valid JSON entries
- [ ] Rate limiting prevents abuse
- [ ] Security tests pass (no direct file access)
- [ ] Performance is acceptable (< 1s response time)
- [ ] All automated tests in `curl_tests.sh` pass

## Troubleshooting

### Common Issues

**502 Bad Gateway:**
- Check PHP-FPM status: `systemctl status php8.1-fpm`
- Check PHP-FPM logs: `tail /var/log/php8.1-fpm.log`
- Verify socket permissions: `ls -la /var/run/php/`

**Database Connection Errors:**
- Check PostgreSQL status on master
- Verify network connectivity between servers
- Check pg_hba.conf configuration
- Test manual connection: `psql -h 192.168.0.207 -U clicks_user -d clicks_db`

**Redis Connection Errors:**
- Check Redis status: `systemctl status redis-server`
- Test connection: `redis-cli ping`
- Check Redis logs: `tail /var/log/redis/redis-server.log`

**Challenge Not Working:**
- Check browser console for JavaScript errors
- Verify HMAC secret is configured correctly
- Check `/verify` endpoint logs
- Test token generation and validation manually

### Log Locations

- Nginx access/error: `/var/log/nginx/`
- PHP-FPM: `/var/log/php8.1-fpm.log`
- Application decisions: `/var/www/domain.com/logs/decisions.log`
- Application PHP errors: `/var/www/domain.com/logs/php_errors.log`
- PostgreSQL: `/var/log/postgresql/`
- Redis: `/var/log/redis/`
- System: `/var/log/syslog`
