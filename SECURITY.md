# Security Audit Report - Stimma Platform

**Date:** 2026-01-17
**Auditor:** Automated Security Analysis
**Scope:** Full codebase and infrastructure review
**Classification:** CONFIDENTIAL

---

## Executive Summary

This comprehensive security audit of the Stimma nanolearning platform identified **4 Critical**, **7 High**, **10 Medium**, and **5 Low** severity vulnerabilities. The most urgent issues involve exposed credentials in configuration files with world-readable permissions, containers running as root, and missing security headers.

### Risk Assessment Overview

| Severity | Count | Immediate Action Required |
|----------|-------|---------------------------|
| **CRITICAL** | 4 | Within 24 hours |
| **HIGH** | 7 | Within 72 hours |
| **MEDIUM** | 10 | Within 2 weeks |
| **LOW** | 5 | Within 1 month |

### Positive Findings

The codebase demonstrates several security best practices:
- Excellent use of PDO with prepared statements (SQL injection protection)
- Proper CSRF token implementation with timing-safe comparison
- Strong session management with HttpOnly, Secure, and SameSite flags
- Secure file upload validation with getimagesize() verification
- Token-based authentication with cryptographic random generation

---

## Critical Severity Findings

### CRIT-001: Exposed API Credentials in .env File

**Severity:** CRITICAL
**CVSS Score:** 9.8
**OWASP Category:** A02:2021 - Cryptographic Failures

**Location:** `/opt/app/stimma/.env` (Lines 9, 25)

**Description:**
Production API keys and database credentials are stored in plaintext in the .env file with world-readable permissions (666).

**Exposed Credentials:**
```
AI_API_KEY=sk-proj-O6HmrMzp... (OpenAI API Key)
DB_PASSWORD=StimmaSecure2025!
```

**Impact:**
- Unauthorized access to OpenAI API resulting in financial costs
- Complete database compromise
- Data breach of all user information

**Remediation:**
```bash
# Immediate: Fix permissions
chmod 600 /opt/app/stimma/.env

# Rotate all credentials immediately
# 1. Revoke OpenAI API key at https://platform.openai.com/api-keys
# 2. Change database password via MySQL
# 3. Update .env with new credentials
```

**Priority:** P0 - Immediate action required

---

### CRIT-002: Database Credentials in Docker Compose

**Severity:** CRITICAL
**CVSS Score:** 9.1
**OWASP Category:** A02:2021 - Cryptographic Failures

**Location:** `/opt/app/stimma/docker-compose.yml` (Lines 31-34)

**Description:**
Database root and user passwords are hardcoded in docker-compose.yml:

```yaml
environment:
  MYSQL_ROOT_PASSWORD: StimmaRoot2025!
  MYSQL_PASSWORD: StimmaSecure2025!
```

**Impact:**
- Credentials exposed in version control history
- Any developer with repo access has database credentials
- Potential for credential reuse attacks

**Remediation:**
```yaml
# Use environment variables or Docker secrets
environment:
  MYSQL_ROOT_PASSWORD_FILE: /run/secrets/db_root_password
  MYSQL_PASSWORD_FILE: /run/secrets/db_password

secrets:
  db_root_password:
    file: ./secrets/db_root_password.txt
  db_password:
    file: ./secrets/db_password.txt
```

**Priority:** P0 - Immediate action required

---

### CRIT-003: PHP/Apache Running as Root

**Severity:** CRITICAL
**CVSS Score:** 9.0
**OWASP Category:** A05:2021 - Security Misconfiguration

**Location:** `stimma-web-1` Docker container

**Description:**
The PHP/Apache processes run with root privileges inside the container:

```bash
$ docker exec stimma-web-1 whoami
root
```

**Impact:**
- Any code execution vulnerability leads to complete system compromise
- File upload vulnerabilities can write anywhere in the container
- Container escape possibilities increase significantly

**Remediation:**
Create or modify Dockerfile:
```dockerfile
FROM php:8.2-apache

# Create non-root user
RUN useradd -r -u 1001 -g www-data appuser

# Set ownership
RUN chown -R appuser:www-data /var/www/html

# Switch to non-root user
USER appuser
```

**Priority:** P0 - Immediate action required

---

### CRIT-004: World-Readable Sensitive Configuration

**Severity:** CRITICAL
**CVSS Score:** 8.6
**OWASP Category:** A01:2021 - Broken Access Control

**Location:** `/opt/app/stimma/.env`

**Description:**
The .env file has permissions `666 (-rw-rw-rw-)`, making it readable by any user on the system.

```bash
$ ls -la /opt/app/stimma/.env
-rw-rw-rw- 1 root root 1316 Jan 17 09:59 .env
```

**Impact:**
- Any process on the host can read credentials
- Malicious scripts can harvest API keys
- Cross-container credential theft possible

**Remediation:**
```bash
# Fix permissions immediately
chmod 600 /opt/app/stimma/.env
chown root:root /opt/app/stimma/.env

# Add to .gitignore
echo ".env" >> .gitignore

# Create .env.example for documentation
cp .env .env.example
# Remove actual credentials from .env.example
```

**Priority:** P0 - Immediate action required

---

## High Severity Findings

### HIGH-001: Missing Security Headers for Stimma Application

**Severity:** HIGH
**CVSS Score:** 7.5
**OWASP Category:** A05:2021 - Security Misconfiguration

**Location:** `/opt/elestio/nginx/conf.d/vibecoder-sambruk-u917.vm.elestio.app.conf` (Lines 103-128)

**Description:**
The Stimma location block lacks essential security headers that other applications in the same configuration have.

**Missing Headers:**
- X-Frame-Options (Clickjacking protection)
- X-Content-Type-Options (MIME sniffing protection)
- Strict-Transport-Security (HTTPS enforcement)
- Content-Security-Policy (XSS mitigation)
- X-XSS-Protection (Legacy XSS filter)
- Referrer-Policy (Information leakage prevention)

**Impact:**
- Vulnerable to clickjacking attacks
- MIME-type confusion attacks possible
- XSS attacks more likely to succeed

**Remediation:**
Add to nginx location block for `/stimma/`:
```nginx
location /stimma/ {
    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;

    # Existing proxy configuration...
}
```

**Priority:** P1 - Fix within 72 hours

---

### HIGH-002: Weak CORS Configuration

**Severity:** HIGH
**CVSS Score:** 7.4
**OWASP Category:** A05:2021 - Security Misconfiguration

**Location:** `/opt/elestio/nginx/conf.d/vibecoder-sambruk-u917.vm.elestio.app.conf` (Lines 28-40)

**Description:**
The upload server (port 9443) allows requests from any origin:

```nginx
add_header 'Access-Control-Allow-Origin' '*';
add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS';
```

**Impact:**
- Any website can make requests to the upload endpoint
- Potential for file upload attacks from malicious sites
- CSRF attacks from external origins

**Remediation:**
```nginx
# Restrict to specific origins
add_header 'Access-Control-Allow-Origin' 'https://stimma.sambruk.se' always;
add_header 'Access-Control-Allow-Credentials' 'true' always;
```

**Priority:** P1 - Fix within 72 hours

---

### HIGH-003: Email Header Injection Vulnerability

**Severity:** HIGH
**CVSS Score:** 7.2
**OWASP Category:** A03:2021 - Injection

**Location:** `/opt/app/stimma/include/mail.php` (Lines 183-189)

**Description:**
Email headers are constructed without sufficient sanitization:

```php
$headers = "From: $fromName <$from>\r\n";
$headers .= "To: $to\r\n";
```

**Impact:**
- CRLF injection can add arbitrary headers
- Attackers can add CC/BCC recipients
- Email spoofing possible

**Remediation:**
```php
// Sanitize email addresses
function sanitizeEmail($email) {
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    // Remove any newlines
    return str_replace(["\r", "\n"], '', $email);
}

function sanitizeHeaderValue($value) {
    return str_replace(["\r", "\n"], '', $value);
}

$from = sanitizeEmail($from);
$to = sanitizeEmail($to);
$fromName = sanitizeHeaderValue($fromName);
```

**Priority:** P1 - Fix within 72 hours

---

### HIGH-004: Git Directory Exposed with Credentials History

**Severity:** HIGH
**CVSS Score:** 7.0
**OWASP Category:** A01:2021 - Broken Access Control

**Location:** `/opt/app/stimma/.git/`

**Description:**
The .git directory contains version history that may include previously committed credentials, even if they were later removed.

**Impact:**
- Historical credentials can be recovered
- Code changes and security fixes visible
- Potential for targeted attacks based on known vulnerabilities

**Remediation:**
```bash
# Option 1: Remove git history and start fresh
cd /opt/app/stimma
rm -rf .git
git init
git add .
git commit -m "Fresh start after security audit"

# Option 2: Use git-filter-repo to remove sensitive files
pip install git-filter-repo
git filter-repo --path .env --invert-paths
```

**Priority:** P1 - Fix within 72 hours

---

### HIGH-005: SSRF Vulnerability in File Fetch Operations

**Severity:** HIGH
**CVSS Score:** 6.9
**OWASP Category:** A10:2021 - Server-Side Request Forgery

**Location:**
- `/opt/app/stimma/admin/ajax/generate_course_image.php`
- `/opt/app/stimma/admin/cron/process_ai_jobs.php`

**Description:**
External URL fetching lacks proper validation:

```php
$response = file_get_contents($externalUrl);
```

**Impact:**
- Attackers can scan internal network
- Access to internal services (metadata APIs, databases)
- Potential for reading local files

**Remediation:**
```php
function fetchExternalUrl($url) {
    // Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid URL');
    }

    // Parse and validate host
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';

    // Block internal/private IPs
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        throw new Exception('Internal URLs not allowed');
    }

    // Set timeout and size limits
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'max_redirects' => 3
        ]
    ]);

    return file_get_contents($url, false, $context);
}
```

**Priority:** P1 - Fix within 72 hours

---

### HIGH-006: Inconsistent XSS Output Encoding

**Severity:** HIGH
**CVSS Score:** 6.8
**OWASP Category:** A03:2021 - Injection

**Location:** Multiple files

**Vulnerable Code Examples:**

```php
// /opt/app/stimma/index.php (Line 158)
<?= $systemDescription ?>  // NO ESCAPING

// /opt/app/stimma/admin/users.php (Line 71)
<strong>{$newDomain}</strong>  // Validated but not escaped
```

**Impact:**
- Stored XSS attacks possible
- Session hijacking
- Credential theft

**Remediation:**
```php
// Always escape output
<?= htmlspecialchars($systemDescription, ENT_QUOTES, 'UTF-8') ?>

// Or use a helper function consistently
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
<?= e($systemDescription) ?>
```

**Priority:** P1 - Fix within 72 hours

---

### HIGH-007: World-Readable Documentation Files

**Severity:** HIGH
**CVSS Score:** 5.3
**OWASP Category:** A01:2021 - Broken Access Control

**Location:** `/opt/app/stimma/docs/`

**Description:**
Documentation files have overly permissive permissions (666):
- PUB_AVTAL.md
- PUB_BILAGA_1_INSTRUKTION.md
- SYSTEM_DOCUMENTATION.md
- USER_GUIDE.md

**Impact:**
- System architecture exposed to any user
- Attack surface information disclosed
- Potential compliance issues (PUB documents)

**Remediation:**
```bash
chmod 640 /opt/app/stimma/docs/*.md
chmod 640 /opt/app/stimma/docs/pdf/*.docx
chown root:www-data /opt/app/stimma/docs/*
```

**Priority:** P1 - Fix within 72 hours

---

## Medium Severity Findings

### MED-001: Session-Based Rate Limiting Bypass

**Severity:** MEDIUM
**CVSS Score:** 5.9
**OWASP Category:** A07:2021 - Identification and Authentication Failures

**Location:** `/opt/app/stimma/index.php` (Lines 38-65)

**Description:**
Rate limiting is implemented using session storage, which can be bypassed by clearing cookies.

**Remediation:**
Implement IP-based rate limiting with database or Redis storage:
```php
function checkRateLimit($ip, $action, $maxAttempts = 5, $window = 900) {
    $key = "rate_limit:{$action}:{$ip}";
    $attempts = getFromCache($key) ?? 0;

    if ($attempts >= $maxAttempts) {
        return false;
    }

    incrementInCache($key, 1, $window);
    return true;
}
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-002: File Lock Race Condition

**Severity:** MEDIUM
**CVSS Score:** 5.5
**OWASP Category:** A04:2021 - Insecure Design

**Location:** `/opt/app/stimma/admin/cron/process_ai_jobs.php` (Lines 33-37)

**Description:**
PID-based file locking can cause race conditions:

```php
file_put_contents($lockFile, getmypid());
```

**Remediation:**
```php
$lockFile = '/tmp/ai_jobs.lock';
$fp = fopen($lockFile, 'c');

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    exit("Another instance is running\n");
}

// Process jobs...

flock($fp, LOCK_UN);
fclose($fp);
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-003: Weak MIME Type Validation for Uploads

**Severity:** MEDIUM
**CVSS Score:** 5.3
**OWASP Category:** A04:2021 - Insecure Design

**Location:** `/opt/app/stimma/admin/upload_image.php` (Line 40)

**Description:**
MIME type is checked but can be spoofed. While getimagesize() provides additional validation, magic number verification would strengthen security.

**Remediation:**
```php
function validateImageMagicBytes($filepath) {
    $magicBytes = [
        'jpeg' => ["\xFF\xD8\xFF"],
        'png'  => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
        'gif'  => ["GIF87a", "GIF89a"]
    ];

    $handle = fopen($filepath, 'rb');
    $bytes = fread($handle, 8);
    fclose($handle);

    foreach ($magicBytes as $type => $signatures) {
        foreach ($signatures as $sig) {
            if (strpos($bytes, $sig) === 0) {
                return $type;
            }
        }
    }
    return false;
}
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-004: Debug Mode Not Explicitly Disabled

**Severity:** MEDIUM
**CVSS Score:** 5.0
**OWASP Category:** A05:2021 - Security Misconfiguration

**Location:** `/opt/app/stimma/include/config.php`

**Description:**
No explicit error reporting configuration found. PHP defaults may expose sensitive information.

**Remediation:**
Add to config.php:
```php
// Production error handling
if (getenv('APP_ENV') !== 'development') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', '/var/log/php/error.log');
}
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-005: Permissive Nginx Rate Limiting

**Severity:** MEDIUM
**CVSS Score:** 4.8
**OWASP Category:** A07:2021 - Identification and Authentication Failures

**Location:** `/opt/elestio/nginx/conf.d/vibecoder-sambruk-u917.vm.elestio.app.conf` (Line 7)

**Description:**
Rate limit of 500 requests/minute is quite permissive for authentication endpoints.

```nginx
limit_req_zone $binary_remote_addr$http_x_forwarded_for zone=iprl:16m rate=500r/m;
```

**Remediation:**
```nginx
# Stricter limits for auth endpoints
limit_req_zone $binary_remote_addr zone=auth:10m rate=10r/m;

location /stimma/ {
    # Apply stricter limit to login
    location ~ ^/stimma/(index\.php|verify\.php) {
        limit_req zone=auth burst=5 nodelay;
        # ... proxy config
    }
}
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-006: Path Traversal Potential in File Operations

**Severity:** MEDIUM
**CVSS Score:** 4.7
**OWASP Category:** A01:2021 - Broken Access Control

**Location:**
- `/opt/app/stimma/admin/domains.php` (Line 30)
- `/opt/app/stimma/admin/ai_settings.php` (Line 83)

**Description:**
File path construction without strict validation:

```php
$domainsFile = __DIR__ . '/../allowed_domains.txt';
$envFile = __DIR__ . '/../.env';
```

**Remediation:**
```php
function getSecurePath($relativePath) {
    $basePath = realpath(__DIR__ . '/..');
    $fullPath = realpath($basePath . '/' . $relativePath);

    // Ensure path is within allowed directory
    if ($fullPath === false || strpos($fullPath, $basePath) !== 0) {
        throw new Exception('Invalid file path');
    }

    return $fullPath;
}
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-007: Legacy Database Connection File

**Severity:** MEDIUM
**CVSS Score:** 4.5
**OWASP Category:** A06:2021 - Vulnerable and Outdated Components

**Location:** `/opt/app/stimma/include/connect.php` (Line 37)

**Description:**
Legacy file using deprecated mysqli_connect() instead of PDO:

```php
$conn = mysqli_connect($db_host, $db_user, $db_password, $db_name);
```

**Impact:**
- Inconsistent database access patterns
- Potential for SQL injection if used
- Maintenance burden

**Remediation:**
```bash
# Remove unused file
rm /opt/app/stimma/include/connect.php

# Audit for any references
grep -r "connect.php" /opt/app/stimma/
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-008: Executable PHP in Upload Directory

**Severity:** MEDIUM
**CVSS Score:** 4.3
**OWASP Category:** A05:2021 - Security Misconfiguration

**Location:** `/opt/app/stimma/upload/index.php`

**Description:**
An empty index.php exists in the upload directory. While .htaccess blocks execution, this violates defense-in-depth principles.

**Remediation:**
```bash
# Remove the file
rm /opt/app/stimma/upload/index.php

# Use empty .htaccess approach instead
echo "Options -Indexes" > /opt/app/stimma/upload/.htaccess
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-009: Database Directory Permissions

**Severity:** MEDIUM
**CVSS Score:** 4.2
**OWASP Category:** A01:2021 - Broken Access Control

**Location:** `/opt/app/stimma/db/`

**Description:**
Database directory has world-readable permissions (755), potentially exposing MariaDB data files.

**Remediation:**
```bash
chmod 700 /opt/app/stimma/db/
chown 999:999 /opt/app/stimma/db/
```

**Priority:** P2 - Fix within 2 weeks

---

### MED-010: Incomplete .htaccess Protection

**Severity:** MEDIUM
**CVSS Score:** 4.0
**OWASP Category:** A05:2021 - Security Misconfiguration

**Location:** `/opt/app/stimma/upload/.htaccess`

**Description:**
.htaccess blocks PHP execution but doesn't restrict SVG, HTML, or JavaScript files which can contain executable code.

**Remediation:**
```apache
# Block all potentially dangerous files
<FilesMatch "\.(php|phtml|php3|php4|php5|php7|phar|phps|cgi|pl|py|rb|sh|bash|html|htm|svg|js|xml)$">
    Require all denied
</FilesMatch>

# Only allow specific image types
<FilesMatch "\.(jpg|jpeg|png|gif|webp)$">
    Require all granted
</FilesMatch>

Options -Indexes -ExecCGI
```

**Priority:** P2 - Fix within 2 weeks

---

## Low Severity Findings

### LOW-001: Information Disclosure in Error Responses

**Severity:** LOW
**Location:** Various API endpoints
**Description:** Some error messages may reveal internal system details.
**Remediation:** Standardize error responses with generic messages.

### LOW-002: Missing Subresource Integrity

**Severity:** LOW
**Location:** External script/style includes
**Description:** CDN resources loaded without SRI hashes.
**Remediation:** Add integrity attributes to external resources.

### LOW-003: Cookie Without Explicit Expiry

**Severity:** LOW
**Location:** Remember-me functionality
**Description:** Cookie expiry should be explicitly set.
**Remediation:** Set explicit max-age on all cookies.

### LOW-004: Verbose Server Headers

**Severity:** LOW
**Location:** Nginx/Apache responses
**Description:** Server version information exposed in headers.
**Remediation:** Add `server_tokens off;` to nginx config.

### LOW-005: Missing HSTS Preload

**Severity:** LOW
**Location:** Nginx configuration
**Description:** HSTS header present but not configured for preload.
**Remediation:** Add `preload` directive after testing.

---

## Security Best Practices Checklist

### Credentials & Secrets
- [ ] Rotate all exposed API keys immediately
- [ ] Change all database passwords
- [ ] Implement secrets management (Docker secrets, Vault)
- [ ] Remove credentials from version control history
- [ ] Set .env permissions to 600

### Container Security
- [ ] Run PHP/Apache as non-root user
- [ ] Implement read-only container filesystem where possible
- [ ] Enable Docker content trust
- [ ] Scan container images for vulnerabilities

### Network Security
- [ ] Add security headers to nginx configuration
- [ ] Restrict CORS to specific origins
- [ ] Implement stricter rate limiting for auth endpoints
- [ ] Enable HSTS with preload

### Application Security
- [ ] Standardize output escaping across all templates
- [ ] Implement SSRF protection for external requests
- [ ] Add magic number validation for file uploads
- [ ] Use flock() instead of PID files for locking

### Monitoring & Logging
- [ ] Enable comprehensive logging
- [ ] Set up alerting for suspicious activities
- [ ] Implement audit trail for admin actions
- [ ] Monitor for credential usage anomalies

---

## Remediation Timeline

### Phase 1: Critical (Within 24 Hours)
| Issue ID | Description | Owner | Status |
|----------|-------------|-------|--------|
| CRIT-001 | Rotate exposed API credentials | DevOps | Pending |
| CRIT-002 | Implement Docker secrets | DevOps | Pending |
| CRIT-003 | Configure non-root container | DevOps | Pending |
| CRIT-004 | Fix .env file permissions | DevOps | Pending |

### Phase 2: High Priority (Within 72 Hours)
| Issue ID | Description | Owner | Status |
|----------|-------------|-------|--------|
| HIGH-001 | Add security headers | DevOps | Pending |
| HIGH-002 | Fix CORS configuration | DevOps | Pending |
| HIGH-003 | Fix email header injection | Backend | Pending |
| HIGH-004 | Clean git history | DevOps | Pending |
| HIGH-005 | Add SSRF protection | Backend | Pending |
| HIGH-006 | Fix XSS output encoding | Frontend | Pending |
| HIGH-007 | Fix documentation permissions | DevOps | Pending |

### Phase 3: Medium Priority (Within 2 Weeks)
| Issue ID | Description | Owner | Status |
|----------|-------------|-------|--------|
| MED-001 | Implement IP-based rate limiting | Backend | Pending |
| MED-002 | Fix file lock race condition | Backend | Pending |
| MED-003 | Add magic byte validation | Backend | Pending |
| MED-004 | Configure error reporting | Backend | Pending |
| MED-005 | Stricter nginx rate limits | DevOps | Pending |
| MED-006 | Add path traversal protection | Backend | Pending |
| MED-007 | Remove legacy connect.php | Backend | Pending |
| MED-008 | Remove upload/index.php | DevOps | Pending |
| MED-009 | Fix database directory permissions | DevOps | Pending |
| MED-010 | Enhance .htaccess protection | DevOps | Pending |

### Phase 4: Low Priority (Within 1 Month)
| Issue ID | Description | Owner | Status |
|----------|-------------|-------|--------|
| LOW-001 | Standardize error responses | Backend | Pending |
| LOW-002 | Add SRI to external resources | Frontend | Pending |
| LOW-003 | Set explicit cookie expiry | Backend | Pending |
| LOW-004 | Hide server version headers | DevOps | Pending |
| LOW-005 | Enable HSTS preload | DevOps | Pending |

---

## Appendix A: OWASP Top 10 (2021) Mapping

| OWASP Category | Findings |
|----------------|----------|
| A01: Broken Access Control | CRIT-004, HIGH-004, HIGH-007, MED-006, MED-009 |
| A02: Cryptographic Failures | CRIT-001, CRIT-002 |
| A03: Injection | HIGH-003, HIGH-006 |
| A04: Insecure Design | MED-002, MED-003 |
| A05: Security Misconfiguration | CRIT-003, HIGH-001, MED-004, MED-008, MED-010 |
| A06: Vulnerable Components | MED-007 |
| A07: Auth Failures | MED-001, MED-005 |
| A08: Software/Data Integrity | - |
| A09: Logging Failures | - |
| A10: SSRF | HIGH-005 |

---

## Appendix B: Files Requiring Immediate Attention

```
/opt/app/stimma/.env                    # CRITICAL - Fix permissions, rotate creds
/opt/app/stimma/docker-compose.yml      # CRITICAL - Use secrets
/opt/app/stimma/include/mail.php        # HIGH - Header injection
/opt/app/stimma/include/functions.php   # MEDIUM - XSS helpers
/opt/app/stimma/admin/upload_image.php  # MEDIUM - Magic bytes
/opt/app/stimma/upload/.htaccess        # MEDIUM - Enhance protection
/opt/elestio/nginx/conf.d/*.conf        # HIGH - Security headers
```

---

## Appendix C: Positive Security Controls

The following security controls are properly implemented:

1. **SQL Injection Protection**
   - PDO with prepared statements throughout
   - Parameter binding for all queries
   - `PDO::ATTR_EMULATE_PREPARES = false`

2. **CSRF Protection**
   - Token generation with `bin2hex(random_bytes(32))`
   - Timing-safe comparison with `hash_equals()`
   - Token validation on all state-changing operations

3. **Session Security**
   - HttpOnly flag enabled
   - Secure flag enabled
   - SameSite=Lax configured
   - Session regeneration on login
   - Session ID not exposed in URLs

4. **Password Security**
   - bcrypt hashing for passwords
   - Secure token generation for auth
   - Password reset with single-use tokens

5. **File Upload Security**
   - MIME type validation
   - Extension whitelist
   - getimagesize() verification
   - Random filename generation
   - Restrictive file permissions (0644)

---

*Report generated: 2026-01-17*
*Next scheduled audit: 2026-04-17*
