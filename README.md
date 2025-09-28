# Advertising Campaign Infrastructure

This repository contains Ansible automation for deploying a complete advertising campaign website template infrastructure with decision engine, fraud detection, and traffic filtering capabilities.

## Architecture Overview

The infrastructure consists of three servers:
- **PostgreSQL Master** (192.168.0.207) - Primary database with replication
- **PostgreSQL Slave** (192.168.0.208) - Read replica for high availability  
- **Web Server** (192.168.0.209) - Nginx, PHP-FPM, Redis, and application

## Features

- **Traffic Filtering**: Bot detection and human verification
- **Decision Engine**: Risk scoring based on IP, geolocation, and behavior
- **JS Challenge**: Browser fingerprinting and bot detection
- **Rate Limiting**: Redis-based per-IP rate limiting
- **Fraud Detection**: IPQS and MaxMind integration (optional)
- **High Availability**: PostgreSQL master-slave replication
- **Security**: UFW firewall, fail2ban, HMAC token validation
- **Monitoring**: Health checks, logging, and alerting

## Quick Start

### Prerequisites

- Ansible 2.13+ installed on control machine
- SSH access to target servers (192.168.0.207, 192.168.0.208, 192.168.0.209)
- Ubuntu 22.04 LTS on all target servers

### 1. Configure Secrets

Create and encrypt the vault file:

```bash
# Create vault password file (keep this secure!)
echo "your_vault_password" > .vault_pass
chmod 600 .vault_pass

# Encrypt the secrets file
ansible-vault encrypt vault/secrets.yml

# Edit secrets (update all placeholder values)
ansible-vault edit vault/secrets.yml
```

Required secrets in `vault/secrets.yml`:
```yaml
vault_db_user: "clicks_user"
vault_db_password: "secure_db_password_change_me"
vault_db_replication_user: "replicator"
vault_db_replication_password: "secure_repl_password_change_me"
vault_hmac_secret: "your_very_secure_hmac_secret_key_change_me"
vault_keitaro_url: "https://your-tracker.com/click.php"
vault_ipqs_api_key: ""  # Optional: IPQualityScore API key
vault_maxmind_license_key: ""  # Optional: MaxMind license key
```

### 2. Update Configuration

Edit `group_vars/all.yml` to match your environment:
```yaml
domain: "your-domain.com"  # Update this
rate_limit_per_min: 60
scoring_deny_threshold: 80
scoring_challenge_threshold: 40
```

### 3. Deploy Infrastructure

```bash
# Test connectivity
ansible all -m ping

# Deploy everything
ansible-playbook playbook.yml

# Deploy specific roles only
ansible-playbook playbook.yml --tags "common,nginx_php"
```

### 4. Verify Deployment

```bash
# Run automated tests
chmod +x tests/curl_tests.sh
./tests/curl_tests.sh

# Manual verification
curl -I http://your-domain.com/
curl -I -A "curl/7.68" "http://your-domain.com/route"
curl -I -A "Mozilla/5.0" "http://your-domain.com/route"
```

## Application Flow

### 1. Traffic Routing (`/route`)

1. **Bot Detection**: User-Agent pattern matching
2. **Rate Limiting**: Redis-based per-IP limiting (60 req/min default)
3. **Risk Scoring**: IPQS + MaxMind + custom rules
4. **Decision Making**:
   - Score ≥ 80: DENY → `/white.html`
   - Score 40-79: CHALLENGE → `/js_challenge.html?token=...`
   - Score < 40: ALLOW → Redirect to Keitaro tracker

### 2. Challenge Flow (`/js_challenge.html`)

1. **Browser Signals**: Collect webdriver, screen size, mouse movement, etc.
2. **HMAC Token**: Validate token from `/route` endpoint
3. **POST to `/verify`**: Send signals for additional scoring
4. **Final Decision**: Allow (redirect to tracker) or Deny (white page)

### 3. Logging and Monitoring

- **File Logs**: `/var/www/domain.com/logs/decisions.log` (JSON format)
- **Database**: PostgreSQL `clicks` table with full request details
- **Health Checks**: Automated service monitoring every 5 minutes

## Configuration

### Scoring Rules

Edit `templates/scoring_rules.json.j2` to customize risk scoring:

```json
{
  "country_blocklist": {"RU": 30, "CN": 50, "NG": 40},
  "ipqs_vpn_weight": 50,
  "ipqs_proxy_weight": 40,
  "ua_bot_patterns": {"bot": 100, "crawler": 100},
  "decision_thresholds": {"deny": 80, "challenge": 40}
}
```

### Rate Limiting

Adjust in `group_vars/all.yml`:
```yaml
rate_limit_per_min: 60  # Requests per minute per IP
```

### External APIs

**IPQualityScore** (optional):
- Set `vault_ipqs_api_key` in vault
- Provides VPN/proxy detection and fraud scoring
- Falls back to stub data if not configured

**MaxMind GeoIP** (optional):
- Set `vault_maxmind_license_key` in vault
- Provides accurate geolocation and ASN data
- Falls back to basic IP detection if not configured

## Management

### Vault Operations

```bash
# View encrypted secrets
ansible-vault view vault/secrets.yml

# Edit secrets
ansible-vault edit vault/secrets.yml

# Change vault password
ansible-vault rekey vault/secrets.yml

# Decrypt for backup (be careful!)
ansible-vault decrypt vault/secrets.yml
```

### Database Operations

```bash
# Connect to master
psql -h 192.168.0.207 -U clicks_user -d clicks_db

# Check replication status
SELECT * FROM pg_stat_replication;

# Manual backup
pg_dump -h 192.168.0.207 -U postgres clicks_db > backup.sql

# View recent clicks
SELECT ip_address, decision, timestamp FROM clicks ORDER BY timestamp DESC LIMIT 10;
```

### Log Management

```bash
# View decision logs
tail -f /var/www/domain.com/logs/decisions.log

# Analyze decisions by type
grep '"decision":"DENY"' /var/www/domain.com/logs/decisions.log | wc -l
grep '"decision":"ALLOW"' /var/www/domain.com/logs/decisions.log | wc -l

# Check log rotation
ls -la /var/www/domain.com/logs/
```

### Service Management

```bash
# Restart services
sudo systemctl restart nginx php8.1-fpm redis-server

# Check service status
sudo systemctl status nginx php8.1-fpm redis-server postgresql

# View service logs
sudo journalctl -u nginx -f
sudo journalctl -u php8.1-fpm -f
```

## Monitoring and Alerts

### Health Checks

The system includes automated health monitoring:
- **Service Status**: Nginx, PHP-FPM, Redis, PostgreSQL
- **Web Response**: HTTP status codes and response times  
- **Disk Space**: Alert when > 80% full
- **Log Files**: Monitor log file sizes

View health check logs:
```bash
tail -f /var/log/app_health_check.log
```

### Key Metrics to Monitor

- **Request Volume**: Requests per minute/hour
- **Decision Distribution**: ALLOW/DENY/CHALLENGE ratios
- **Response Times**: Average processing time
- **Error Rates**: 5xx errors, PHP errors
- **Database Performance**: Query times, replication lag
- **Redis Performance**: Memory usage, hit rates

## Troubleshooting

### Common Issues

**502 Bad Gateway**:
```bash
# Check PHP-FPM
sudo systemctl status php8.1-fpm
sudo tail /var/log/php8.1-fpm.log

# Check socket permissions
ls -la /var/run/php/
```

**Database Connection Errors**:
```bash
# Test connection
psql -h 192.168.0.207 -U clicks_user -d clicks_db

# Check PostgreSQL logs
sudo tail /var/log/postgresql/postgresql-14-main.log

# Verify pg_hba.conf
sudo cat /etc/postgresql/14/main/pg_hba.conf
```

**Challenge Not Working**:
- Check browser console for JavaScript errors
- Verify HMAC secret configuration
- Test token generation manually
- Check `/verify` endpoint logs

**Rate Limiting Issues**:
```bash
# Check Redis
redis-cli ping
redis-cli KEYS "rl:ip:*"

# Check rate limit counters
redis-cli GET "rl:ip:YOUR_IP"
```

### Log Locations

- **Nginx**: `/var/log/nginx/domain.com_*.log`
- **PHP-FPM**: `/var/log/php8.1-fpm.log`
- **Application**: `/var/www/domain.com/logs/`
- **PostgreSQL**: `/var/log/postgresql/`
- **Redis**: `/var/log/redis/redis-server.log`
- **System**: `/var/log/syslog`

## Security Considerations

### Network Security
- UFW firewall blocks all ports except 22 (SSH) and 80 (HTTP)
- PostgreSQL port 5432 restricted to database servers only
- fail2ban protects against brute force attacks

### Application Security
- HMAC token validation prevents token tampering
- CSRF protection via referer validation
- PHP disable_functions prevents code execution
- File permissions restrict access to sensitive files
- Nginx blocks direct access to config/logs directories

### Data Security
- Database passwords encrypted with Ansible Vault
- API keys stored in encrypted vault
- Log files contain no sensitive authentication data
- Regular security updates via common role

## Performance Optimization

### Database
- Indexes on frequently queried columns (ip_address, timestamp, decision)
- Connection pooling via PHP-FPM
- Read queries can use slave server

### Caching
- Redis caches IPQS API responses (6 hours TTL)
- Rate limiting counters in Redis (60 seconds TTL)
- Static files served directly by Nginx

### PHP-FPM
- Dynamic process management
- Optimized for concurrent requests
- Separate pool per domain

## Backup and Recovery

### Automated Backups
- PostgreSQL: Daily pg_dump backups (2 AM, keep 7 days)
- Configuration: All files in Git repository
- Logs: Rotated daily, kept for 14 days

### Manual Backup
```bash
# Database backup
pg_dump -h 192.168.0.207 -U postgres clicks_db | gzip > backup_$(date +%Y%m%d).sql.gz

# Configuration backup
tar -czf config_backup_$(date +%Y%m%d).tar.gz /var/www/domain.com/config/

# Full application backup
tar -czf app_backup_$(date +%Y%m%d).tar.gz /var/www/domain.com/
```

### Disaster Recovery
1. Deploy infrastructure on new servers using this playbook
2. Restore database from latest backup
3. Update DNS to point to new servers
4. Verify all services and run tests

## Development and Testing

### Local Testing
```bash
# Syntax check
ansible-playbook playbook.yml --syntax-check

# Dry run
ansible-playbook playbook.yml --check

# Run on staging environment
ansible-playbook -i inventory/staging.ini playbook.yml
```

### Adding New Features
1. Create/modify roles in `roles/` directory
2. Update templates and variables as needed
3. Test on staging environment
4. Update documentation
5. Deploy to production

## Support and Maintenance

### Regular Maintenance Tasks
- **Weekly**: Review logs and performance metrics
- **Monthly**: Update system packages and security patches
- **Quarterly**: Review and optimize scoring rules
- **Annually**: Security audit and penetration testing

### Scaling Considerations
- **Horizontal**: Add more web servers behind load balancer
- **Vertical**: Increase server resources (CPU, RAM, disk)
- **Database**: Consider PostgreSQL clustering for high load
- **Caching**: Add dedicated Redis cluster for large scale

## License and Support

This infrastructure template is designed for advertising campaign traffic filtering and fraud detection. Customize the scoring rules and thresholds based on your specific campaign requirements.

For support and customization, refer to the troubleshooting section or contact your infrastructure team.
