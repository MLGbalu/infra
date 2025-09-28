# Acceptance Checklist

## Project Overview
**Project:** Advertising Campaign Website Template Infrastructure  
**Deployment Date:** _____________  
**Deployed By:** _____________  
**Reviewed By:** _____________  

## Infrastructure Components

### ✅ Server Setup
- [ ] **PostgreSQL Master (192.168.0.207)**
  - [ ] PostgreSQL 14+ installed and running
  - [ ] Database `clicks_db` created
  - [ ] Application user `clicks_user` configured
  - [ ] Replication user configured
  - [ ] `clicks` table created with proper schema
  - [ ] Backup script configured and tested
  
- [ ] **PostgreSQL Slave (192.168.0.208)**
  - [ ] PostgreSQL 14+ installed and running
  - [ ] Streaming replication configured and active
  - [ ] Slave is in recovery mode
  - [ ] Data replication verified (insert on master appears on slave)
  
- [ ] **Web Server (192.168.0.209)**
  - [ ] Nginx installed and configured
  - [ ] PHP 8.1+ with required extensions installed
  - [ ] Redis installed and running
  - [ ] Application deployed and configured

### ✅ Security Configuration
- [ ] UFW firewall enabled on all servers
- [ ] Only ports 22 and 80 open to public
- [ ] Port 5432 restricted to database servers only
- [ ] fail2ban configured for SSH and Nginx
- [ ] Application files have correct permissions
- [ ] Sensitive directories blocked by Nginx
- [ ] HMAC secrets configured and secure

### ✅ Application Functionality
- [ ] **Basic Access**
  - [ ] `http://domain.com/` loads white page (HTTP 200)
  - [ ] White page displays correctly in browser
  
- [ ] **Bot Detection**
  - [ ] Bot User-Agents redirect to `/white.html` (HTTP 302)
  - [ ] Common bot patterns detected: curl, python, wget, etc.
  - [ ] Crawler User-Agents properly blocked
  
- [ ] **Human User Detection**
  - [ ] Human User-Agents redirect to `/js_challenge.html?token=...` (HTTP 302)
  - [ ] Challenge page loads and displays properly
  - [ ] JavaScript challenge completes successfully
  
- [ ] **Parameter Handling**
  - [ ] UTM parameters preserved through redirects
  - [ ] Google Click ID (gclid) handled correctly
  - [ ] Custom clickid parameter supported

### ✅ Decision Engine
- [ ] **Scoring System**
  - [ ] Scoring rules loaded from JSON configuration
  - [ ] Country-based scoring works
  - [ ] IPQS integration functional (or stub mode)
  - [ ] MaxMind GeoIP integration functional (or stub mode)
  - [ ] User-Agent pattern matching works
  
- [ ] **Decision Thresholds**
  - [ ] Score ≥ 80 → DENY → `/white.html`
  - [ ] Score 40-79 → CHALLENGE → `/js_challenge.html`
  - [ ] Score < 40 → ALLOW → Keitaro redirect
  
- [ ] **Challenge Verification**
  - [ ] Browser signals collected correctly
  - [ ] HMAC token validation works
  - [ ] Challenge scoring applied
  - [ ] Final redirect to tracker URL

### ✅ Rate Limiting
- [ ] Redis-based rate limiting functional
- [ ] Rate limit per IP enforced (default: 60/minute)
- [ ] Rate limit exceeded → automatic DENY
- [ ] Rate limit keys expire correctly (60 seconds)

### ✅ Logging and Monitoring
- [ ] **File Logging**
  - [ ] Decision log writes valid JSON entries
  - [ ] Log file location: `/var/www/domain.com/logs/decisions.log`
  - [ ] Log rotation configured (daily, keep 14 days)
  - [ ] PHP error logging configured
  
- [ ] **Database Logging**
  - [ ] Clicks inserted into PostgreSQL master
  - [ ] All required fields populated correctly
  - [ ] Database errors handled gracefully
  - [ ] Slave receives replicated data
  
- [ ] **Health Monitoring**
  - [ ] Health check script runs every 5 minutes
  - [ ] Service restart on failure
  - [ ] Disk space monitoring
  - [ ] Log file size monitoring

### ✅ Performance
- [ ] Response time < 1 second for most requests
- [ ] Redis caching reduces API calls
- [ ] Database queries optimized
- [ ] Static files served efficiently by Nginx

### ✅ Integration Tests
- [ ] **End-to-End Flow**
  - [ ] Complete user journey works: route → challenge → verify → redirect
  - [ ] Token generation and validation secure
  - [ ] Final redirect to Keitaro URL with parameters
  
- [ ] **API Integration**
  - [ ] IPQS API calls work (if key provided) or stub responses
  - [ ] MaxMind database queries work (if configured) or stub responses
  - [ ] External API failures handled gracefully
  
- [ ] **Failover Scenarios**
  - [ ] System works with PostgreSQL master down (logs to file only)
  - [ ] System works with Redis down (no caching/rate limiting)
  - [ ] Graceful degradation when external APIs fail

## Testing Results

### ✅ Automated Tests
- [ ] All tests in `tests/curl_tests.sh` pass
- [ ] Rate limiting tests pass
- [ ] Database connectivity verified
- [ ] Redis connectivity verified
- [ ] Log file creation verified

### ✅ Manual Browser Tests
- [ ] Challenge page displays correctly in Chrome
- [ ] Challenge page displays correctly in Firefox
- [ ] Challenge page displays correctly in Safari
- [ ] Mobile browser compatibility verified
- [ ] JavaScript execution successful
- [ ] No console errors in browser developer tools

### ✅ Load Testing (Optional)
- [ ] System handles concurrent requests
- [ ] Database performance under load
- [ ] Redis performance under load
- [ ] Memory usage within acceptable limits

## Configuration Verification

### ✅ Environment Variables
- [ ] Database connection parameters correct
- [ ] Redis connection parameters correct
- [ ] HMAC secret configured and secure
- [ ] Keitaro tracker URL configured
- [ ] API keys configured (IPQS, MaxMind) or stub mode confirmed
- [ ] Rate limiting parameters appropriate

### ✅ Scoring Configuration
- [ ] Country blocklist appropriate for campaign
- [ ] IPQS scoring weights configured
- [ ] User-Agent patterns comprehensive
- [ ] Decision thresholds appropriate (80/40 default)
- [ ] Challenge rules configured

## Deployment Verification

### ✅ Ansible Deployment
- [ ] All Ansible roles executed successfully
- [ ] No failed tasks in playbook run
- [ ] Idempotent deployment (can run multiple times safely)
- [ ] Vault secrets properly encrypted
- [ ] Inventory configuration correct

### ✅ Service Management
- [ ] All services start automatically on boot
- [ ] Service dependencies configured correctly
- [ ] Log rotation scheduled
- [ ] Backup jobs scheduled (PostgreSQL)
- [ ] Health checks scheduled

## Documentation

### ✅ Operational Documentation
- [ ] README.md complete with deployment instructions
- [ ] Vault password management documented
- [ ] Backup and restore procedures documented
- [ ] Troubleshooting guide available
- [ ] Monitoring and alerting setup documented

### ✅ Configuration Management
- [ ] All configuration files under version control
- [ ] Sensitive data properly encrypted with Ansible Vault
- [ ] Environment-specific variables documented
- [ ] Rollback procedures documented

## Sign-off

### Technical Acceptance
- [ ] **Infrastructure Team:** _________________ Date: _________
- [ ] **Security Team:** _________________ Date: _________
- [ ] **Application Team:** _________________ Date: _________

### Business Acceptance
- [ ] **Project Manager:** _________________ Date: _________
- [ ] **Campaign Manager:** _________________ Date: _________
- [ ] **Quality Assurance:** _________________ Date: _________

## Post-Deployment Actions

### ✅ Immediate (Day 1)
- [ ] Monitor logs for any errors
- [ ] Verify all services running
- [ ] Check database replication status
- [ ] Confirm backup jobs executed

### ✅ Short-term (Week 1)
- [ ] Review performance metrics
- [ ] Analyze decision patterns
- [ ] Optimize scoring rules if needed
- [ ] Update documentation based on findings

### ✅ Long-term (Month 1)
- [ ] Security audit completed
- [ ] Performance optimization review
- [ ] Capacity planning assessment
- [ ] Disaster recovery testing

## Notes and Comments

**Deployment Notes:**
_________________________________________________
_________________________________________________
_________________________________________________

**Known Issues:**
_________________________________________________
_________________________________________________
_________________________________________________

**Future Improvements:**
_________________________________________________
_________________________________________________
_________________________________________________

---

**Final Status:** ⬜ ACCEPTED ⬜ REJECTED ⬜ CONDITIONAL ACCEPTANCE

**Overall Comments:**
_________________________________________________
_________________________________________________
_________________________________________________

**Approved By:** _________________ **Date:** _________ **Signature:** _________________
