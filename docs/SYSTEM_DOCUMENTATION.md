# Stimma - Systemdokumentation

Teknisk dokumentation för installation, konfiguration och underhåll av Stimma e-learning plattform.

---

## Innehållsförteckning

1. [Systemöversikt](#systemöversikt)
2. [Arkitektur](#arkitektur)
3. [Installation](#installation)
4. [Konfiguration](#konfiguration)
5. [Databasstruktur](#databasstruktur)
6. [API-endpoints](#api-endpoints)
7. [Säkerhet](#säkerhet)
8. [AI-integration](#ai-integration)
9. [Felsökning](#felsökning)
10. [Underhåll](#underhåll)

---

## Systemöversikt

Stimma är en PHP-baserad e-learning plattform för mikroutbildning. Systemet är byggt med fokus på:

- **Multi-tenant arkitektur** - Organisationsseparation baserad på e-postdomän
- **Lösenordsfri autentisering** - E-postbaserade inloggningslänkar
- **AI-integration** - Kursgenerering och bildgenerering med OpenAI
- **Rollbaserad åtkomstkontroll** - Student, Redaktör, Admin, Superadmin

### Teknisk stack

| Komponent | Teknologi |
|-----------|-----------|
| Backend | PHP 8.x |
| Databas | MySQL/MariaDB |
| Webbserver | Apache |
| Container | Docker |
| AI | OpenAI GPT-4, DALL-E 3 |
| E-post | SMTP |

---

## Arkitektur

### Filstruktur

```
stimma/
├── admin/                    # Administrationsgränssnitt
│   ├── ajax/                 # AJAX-endpoints
│   │   ├── ai_generate_course.php
│   │   ├── generate_course_image.php
│   │   ├── generate_lesson_image.php
│   │   ├── add_course_editor.php
│   │   └── ...
│   ├── courses.php           # Kurshantering
│   ├── edit_course.php       # Redigera kurs
│   ├── lessons.php           # Lektionslistning
│   ├── edit_lesson.php       # Redigera lektion
│   ├── users.php             # Användarhantering
│   ├── statistics.php        # Statistik
│   ├── logs.php              # Aktivitetsloggar
│   ├── tags.php              # Tagghantering
│   └── ai_settings.php       # AI-inställningar
├── includes/                 # Delade komponenter
│   ├── config.php            # Konfiguration
│   ├── database.php          # Databasanslutning
│   ├── auth.php              # Autentisering
│   ├── functions.php         # Hjälpfunktioner
│   └── header.php            # Gemensam header
├── upload/                   # Uppladdade filer
├── logs/                     # Systemloggar
├── docs/                     # Dokumentation
├── index.php                 # Startsida
├── login.php                 # Inloggning
├── verify.php                # E-postverifiering
├── logout.php                # Utloggning
├── lesson.php                # Lektionsvisning
├── ai_chat.php               # AI-tutor endpoint
├── init.sql                  # Databasschema
├── docker-compose.yml        # Docker-konfiguration
└── .env                      # Miljövariabler
```

### Multi-tenant modell

Stimma använder e-postdomän för organisationsseparation:

- Användare `anna@kommun.se` tillhör organisation `kommun.se`
- Kurser har `organization_domain`-fält
- Taggar är organisationsspecifika
- Admin/Redaktörer ser endast sin organisations data

---

## Installation

### Förutsättningar

- Docker och Docker Compose
- MySQL/MariaDB databas
- OpenAI API-nyckel (för AI-funktioner)
- SMTP-server (för e-postinloggning)

### Steg-för-steg

1. **Klona repositoryt**
```bash
git clone https://github.com/Sambruk/stimma.git
cd stimma
```

2. **Skapa miljövariabler**
```bash
cp env.example .env
```

3. **Konfigurera .env**
```bash
# Databas
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=stimma
DB_USERNAME=stimma
DB_PASSWORD=ditt_lösenord

# AI
AI_API_KEY=sk-din-openai-nyckel
AI_MODEL=gpt-4

# E-post
SMTP_HOST=smtp.din-server.se
SMTP_PORT=587
MAIL_FROM_ADDRESS=no-reply@din-domän.se
```

4. **Starta med Docker**
```bash
docker-compose up -d
```

5. **Importera databasschema**
```bash
mysql -u root -p stimma < init.sql
```

6. **Sätt filrättigheter**
```bash
chmod 755 upload/
chown www-data:www-data upload/
```

---

## Konfiguration

### Miljövariabler

#### Databas
| Variabel | Beskrivning | Standard |
|----------|-------------|----------|
| `DB_HOST` | Databasserver | localhost |
| `DB_PORT` | Databasport | 3306 |
| `DB_DATABASE` | Databasnamn | stimma |
| `DB_USERNAME` | Databasanvändare | - |
| `DB_PASSWORD` | Databaslösenord | - |

#### System
| Variabel | Beskrivning | Standard |
|----------|-------------|----------|
| `SYSTEM_NAME` | Systemets visningsnamn | Stimma |
| `SYSTEM_URL` | Systemets URL | - |
| `SESSION_LIFETIME_HOURS` | Sessionens livslängd | 4 |
| `SESSION_REGENERATE_MINUTES` | Session-ID regenerering | 30 |
| `AUTH_TOKEN_EXPIRY_MINUTES` | Inloggningslänkens giltighet | 15 |

#### E-post
| Variabel | Beskrivning | Standard |
|----------|-------------|----------|
| `SMTP_HOST` | SMTP-server | 172.17.0.1 |
| `SMTP_PORT` | SMTP-port | 25 |
| `MAIL_FROM_ADDRESS` | Avsändaradress | - |
| `MAIL_FROM_NAME` | Avsändarnamn | Stimma |

#### AI
| Variabel | Beskrivning | Standard |
|----------|-------------|----------|
| `AI_API_KEY` | OpenAI API-nyckel | - |
| `AI_SERVER` | API-server | api.openai.com |
| `AI_MODEL` | AI-modell | gpt-4 |
| `AI_TEMPERATURE` | Temperatur (kreativitet) | 0.7 |
| `AI_TOP_P` | Top-p sampling | 0.9 |
| `AI_MAX_MESSAGE_LENGTH` | Max meddelandelängd | 500 |
| `AI_MAX_COMPLETION_TOKENS` | Max svarstokens | 4096 |

---

## Databasstruktur

### Tabellöversikt

#### users
Användarkonton och roller.

```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255),
    verification_token VARCHAR(64),
    verified_at DATETIME,
    last_login_at DATETIME,
    is_admin TINYINT(1) DEFAULT 0,
    is_editor TINYINT(1) DEFAULT 0,
    role ENUM('student', 'teacher', 'admin', 'super_admin') DEFAULT 'student',
    preferences JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);
```

#### courses
Kursdefinitioner.

```sql
CREATE TABLE courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    difficulty_level ENUM('beginner', 'intermediate', 'advanced'),
    duration_minutes INT,
    prerequisites TEXT,
    tags JSON,
    image_url VARCHAR(500),
    status ENUM('active', 'inactive') DEFAULT 'inactive',
    featured TINYINT(1) DEFAULT 0,
    author_id INT,
    category_id INT,
    sort_order INT DEFAULT 0,
    organization_domain VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
);
```

#### lessons
Lektionsinnehåll.

```sql
CREATE TABLE lessons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    estimated_duration INT,
    image_url VARCHAR(500),
    video_url VARCHAR(500),
    resource_links JSON,
    tags JSON,
    status ENUM('active', 'inactive') DEFAULT 'active',
    sort_order INT DEFAULT 0,
    ai_instruction TEXT,
    ai_prompt TEXT,
    quiz_question TEXT,
    quiz_answer1 VARCHAR(500),
    quiz_answer2 VARCHAR(500),
    quiz_answer3 VARCHAR(500),
    quiz_correct_answer TINYINT,
    author_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id)
);
```

#### progress
Användarframsteg per lektion.

```sql
CREATE TABLE progress (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    lesson_id INT NOT NULL,
    status ENUM('not_started', 'in_progress', 'completed') DEFAULT 'not_started',
    started_at DATETIME,
    completion_time INT,
    attempts INT DEFAULT 0,
    score INT,
    last_accessed DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    UNIQUE KEY unique_progress (user_id, lesson_id)
);
```

#### course_editors
Tilldelning av kursredaktörer.

```sql
CREATE TABLE course_editors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_by VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_editor (course_id, email)
);
```

#### tags
Organisationsspecifika taggar.

```sql
CREATE TABLE tags (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    organization_domain VARCHAR(255),
    created_by VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tag (name, organization_domain)
);
```

#### course_tags
Koppling mellan kurser och taggar.

```sql
CREATE TABLE course_tags (
    course_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (course_id, tag_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);
```

#### ai_course_jobs
AI-kursgenerering i bakgrunden.

```sql
CREATE TABLE ai_course_jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    organization_domain VARCHAR(255),
    course_name VARCHAR(255),
    course_description TEXT,
    lesson_count INT,
    include_quiz TINYINT(1) DEFAULT 0,
    include_video_links TINYINT(1) DEFAULT 0,
    image_option ENUM('none', 'course', 'lessons', 'both') DEFAULT 'none',
    difficulty_level ENUM('beginner', 'intermediate', 'advanced'),
    include_ai_tutor TINYINT(1) DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    progress_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

#### ai_settings
AI-konfiguration och guardrails.

```sql
CREATE TABLE ai_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE,
    setting_value TEXT,
    description TEXT,
    guardrails_enabled TINYINT(1) DEFAULT 1,
    system_prompt_prefix TEXT,
    blocked_topics TEXT,
    response_guidelines TEXT,
    topic_restrictions TEXT,
    custom_instructions TEXT,
    updated_by VARCHAR(255),
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);
```

#### activity_log
Revisionslogg för användaråtgärder.

```sql
CREATE TABLE activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_email VARCHAR(255),
    action VARCHAR(100),
    details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## API-endpoints

### Publika endpoints

| Endpoint | Metod | Beskrivning |
|----------|-------|-------------|
| `/login.php` | POST | Skicka inloggningslänk |
| `/verify.php` | GET | Verifiera inloggningstoken |
| `/logout.php` | GET | Logga ut |
| `/lesson.php` | GET/POST | Visa lektion, svara på quiz |
| `/ai_chat.php` | POST | AI-tutor chattmeddelande |

### Admin AJAX-endpoints

| Endpoint | Metod | Beskrivning | Behörighet |
|----------|-------|-------------|------------|
| `/admin/ajax/ai_generate_course.php` | POST | Starta AI-kursgenerering | Redaktör+ |
| `/admin/ajax/generate_course_image.php` | POST | Generera kursbild med DALL-E | Redaktör+ |
| `/admin/ajax/generate_lesson_image.php` | POST | Generera lektionsbild med DALL-E | Redaktör+ |
| `/admin/ajax/add_course_editor.php` | POST | Lägg till kursredaktör | Admin+ |
| `/admin/ajax/remove_course_editor.php` | POST | Ta bort kursredaktör | Admin+ |
| `/admin/ajax/search_users.php` | GET | Sök användare | Admin+ |
| `/admin/update_course_order.php` | POST | Uppdatera kursordning | Redaktör+ |
| `/admin/update_lesson_order.php` | POST | Uppdatera lektionsordning | Redaktör+ |

### Autentisering

Alla admin-endpoints kräver:
1. Giltig session
2. CSRF-token i header (`X-CSRF-Token`) eller POST-data
3. Rätt rollbehörighet

---

## Säkerhet

### Implementerade säkerhetsåtgärder

#### SQL Injection-skydd
- PDO med prepared statements
- Parameterbindning för alla frågor

```php
// Exempel på säker fråga
$result = query("SELECT * FROM users WHERE email = ?", [$email]);
```

#### XSS-skydd
- `htmlspecialchars()` för utdata
- Endast HTML tillåtet i lektionsinnehåll

#### CSRF-skydd
- Token genereras per session
- Valideras på alla POST-förfrågningar

```php
// Generera token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Validera token
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('CSRF-fel');
}
```

#### Filuppladdningssäkerhet
- MIME-typvalidering
- Filändelsewhitelist (jpg, jpeg, png, gif)
- Filstorleksgräns (5MB)
- Bilddimensionsvalidering (max 1920x1080)
- `getimagesize()` validering
- Slumpmässiga filnamn

#### Sessionshantering
- Session-ID regenerering vid inloggning
- Periodisk ID-regenerering (var 30:e minut)
- Inaktivitetstimeout (4 timmar)
- HTTPOnly och Secure flaggor
- SameSite=Lax policy

#### Autentisering
- Tokenbaserad inloggning utan lösenord
- Engångstokens
- Tokengiltighetstid (15 minuter)
- Rate limiting (5 försök per 15 minuter)

### Behörighetskontroll

```php
// Funktioner för behörighetskontroll
function isLoggedIn() { ... }
function isEditor() { ... }
function isAdmin() { ... }
function isSuperAdmin() { ... }

// Sidnivåkontroll
requireLogin();     // Kräver inloggning
requireEditor();    // Kräver redaktörsbehörighet
requireAdmin();     // Kräver adminbehörighet
```

---

## AI-integration

### OpenAI API

Stimma integrerar med OpenAI för:
- **GPT-4**: Kursgenerering, AI-tutor
- **DALL-E 3**: Bildgenerering

### AI-kursgenerering

Processen:
1. Användare startar generering via admin
2. Jobb skapas i `ai_course_jobs`
3. Bakgrundsprocess (`process_ai_jobs.php`) hämtar jobb
4. API-anrop till OpenAI för kursstruktur
5. Separata anrop för varje lektion
6. Valfri bildgenerering
7. Status uppdateras kontinuerligt

### AI-bildgenerering

```php
// Exempel på DALL-E 3-anrop
$response = callOpenAI([
    'model' => 'dall-e-3',
    'prompt' => 'Educational illustration for: ' . $description,
    'n' => 1,
    'size' => '1024x1024'
]);
```

### AI-tutor

AI-tutorn ger kontextmedveten hjälp per lektion:

1. Användare skickar fråga
2. System bygger prompt med:
   - Lektionsinnehåll
   - AI-instruktioner (om definierade)
   - Guardrails (om aktiverade)
3. Svar från GPT-4 visas i chatt

### Guardrails

Superadmin kan konfigurera:
- Blockerade ämnen
- Svarsriktlinjer
- Ämnesbegränsningar
- Anpassade instruktioner

---

## Felsökning

### Vanliga problem

#### Inloggningslänk skickas inte
1. Kontrollera SMTP-konfiguration
2. Verifiera att avsändaradressen är konfigurerad
3. Kontrollera loggfiler för e-postfel

```bash
docker logs stimma-web-1 | grep -i mail
```

#### AI-generering misslyckas
1. Verifiera OpenAI API-nyckel
2. Kontrollera API-kvoter
3. Granska felloggar

```bash
tail -f /opt/app/stimma/logs/ai_errors.log
```

#### Bilduppladdning fungerar inte
1. Kontrollera mapprättigheter
2. Verifiera PHP-konfiguration för uppladdningar

```bash
# Kontrollera rättigheter
ls -la /opt/app/stimma/upload/

# Fixa rättigheter
docker exec stimma-web-1 chown www-data:www-data /var/www/html/upload
docker exec stimma-web-1 chmod 755 /var/www/html/upload
```

#### Session-problem
1. Kontrollera PHP session-konfiguration
2. Verifiera att sessions-mappen är skrivbar
3. Rensa gamla sessioner

### Loggar

| Logg | Plats | Innehåll |
|------|-------|----------|
| Apache error | `/var/log/apache2/error.log` | PHP-fel |
| Apache access | `/var/log/apache2/access.log` | HTTP-förfrågningar |
| Aktivitetslogg | Databas `activity_log` | Användaråtgärder |
| Systemlogg | Databas `logs` | Systemhändelser |

---

## Underhåll

### Dagligt underhåll

1. **Kontrollera loggar** för fel
2. **Verifiera backup** av databas
3. **Övervaka diskutrymme** för uppladdningar

### Veckovist underhåll

1. **Granska aktivitetsloggar** för ovanliga mönster
2. **Kontrollera AI-jobb** som fastnat
3. **Rensa gamla sessioner**

```sql
-- Ta bort gamla inloggningstoken
DELETE FROM users WHERE verification_token IS NOT NULL
AND verified_at IS NULL
AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY);

-- Rensa gamla AI-jobb
DELETE FROM ai_course_jobs
WHERE status IN ('completed', 'failed')
AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Backup

#### Databas

```bash
# Daglig backup
mysqldump -u stimma -p stimma > backup_$(date +%Y%m%d).sql

# Återställning
mysql -u stimma -p stimma < backup_20250101.sql
```

#### Filer

```bash
# Backup av uppladdningar
tar -czvf upload_backup_$(date +%Y%m%d).tar.gz /opt/app/stimma/upload/
```

### Uppgradering

1. **Stoppa tjänster**
```bash
docker-compose down
```

2. **Backup databas och filer**

3. **Hämta ny version**
```bash
git pull origin main
```

4. **Kör databasmigrering** (om tillämpligt)
```bash
mysql -u stimma -p stimma < migrations/v1.1.sql
```

5. **Starta tjänster**
```bash
docker-compose up -d
```

6. **Verifiera funktion**

---

## Kontakt och support

- **Repository**: https://github.com/Sambruk/stimma
- **Utvecklare**: Sambruk / Christian Alfredsson
- **Licens**: GPL v2

---

*Stimma - Lär dig i små steg*
