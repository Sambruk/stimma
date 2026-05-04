-- Migration 035: AI-kvot per organisation/domän + användningsloggning
-- Skapad: 2026-04-30
--
-- Bakgrund: AI-genererade kurser har visat sig kostsamma. Vi behöver kunna
-- (a) sätta en månadskvot per organisation (eller per domän när org saknas),
-- (b) logga varje AI-anrop med token- och kostnadsdata,
-- (c) varna admin+editor vid 80 % förbrukning och blockera vid 100 %,
-- (d) visa statistik och justera kvoter via superadmin-UI.
--
-- Tre tabeller: ai_pricing (modellpriser), ai_quotas (kvot per scope),
-- ai_usage_log (en rad per AI-anrop).

CREATE TABLE IF NOT EXISTS ai_pricing (
    model VARCHAR(64) NOT NULL PRIMARY KEY,
    input_per_1m_cents INT NOT NULL DEFAULT 0 COMMENT 'USD-cent per 1M input-tokens',
    output_per_1m_cents INT NOT NULL DEFAULT 0 COMMENT 'USD-cent per 1M output-tokens',
    image_per_call_cents INT NOT NULL DEFAULT 0 COMMENT 'USD-cent per bildanrop (0 om ej bildmodell)',
    notes VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_pricing (model, input_per_1m_cents, output_per_1m_cents, notes) VALUES
    ('gpt-4o-mini',    15,   60, 'OpenAI prislista 2026-04'),
    ('gpt-4o',        250, 1000, 'OpenAI prislista 2026-04'),
    ('gpt-4-turbo',  1000, 3000, 'OpenAI prislista 2026-04'),
    ('gpt-4',        3000, 6000, 'OpenAI legacy'),
    ('gpt-3.5-turbo',  50,  150, 'OpenAI legacy')
ON DUPLICATE KEY UPDATE notes = VALUES(notes);

INSERT INTO ai_pricing (model, image_per_call_cents, notes) VALUES
    ('dall-e-3',     4, 'Standard 1024x1024'),
    ('gpt-image-1',  4, 'OpenAI image API')
ON DUPLICATE KEY UPDATE notes = VALUES(notes);


CREATE TABLE IF NOT EXISTS ai_quotas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL COMMENT 'Antingen org_id ELLER domain är satt',
    domain VARCHAR(255) NULL COMMENT 'Används när domänen inte tillhör någon organisation',
    monthly_token_quota BIGINT NOT NULL DEFAULT 50000 COMMENT 'Tokens per kalendermånad',
    alert_threshold_pct TINYINT UNSIGNED NOT NULL DEFAULT 80 COMMENT 'Procent som triggar varningsbanner',
    behavior ENUM('block','warn') NOT NULL DEFAULT 'block' COMMENT 'block = stoppa anrop vid 100 %, warn = bara logga',
    notes TEXT NULL,
    updated_by VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_org (organization_id),
    UNIQUE KEY uk_dom (domain),
    CONSTRAINT fk_aiquota_org FOREIGN KEY (organization_id)
        REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS ai_feature_models (
    feature VARCHAR(64) NOT NULL PRIMARY KEY COMMENT 'course_gen | lesson_gen | chat | image',
    model VARCHAR(64) NOT NULL,
    notes VARCHAR(255) NULL,
    updated_by VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_feature_models (feature, model, notes) VALUES
    ('course_gen', 'gpt-4o',      'Hela kursgenereringen (struktur + lektionsinnehåll i bakgrundsjobbet)'),
    ('lesson_gen', 'gpt-4o',      'Enstaka lektion via "AI-skapa lektion" i admin'),
    ('chat',       'gpt-4o-mini', 'AI-chat per lektion för elever'),
    ('image',      'dall-e-3',    'Bildgenerering — kursomslag, lektionsbilder, diplom')
ON DUPLICATE KEY UPDATE notes = VALUES(notes);


CREATE TABLE IF NOT EXISTS ai_usage_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    domain VARCHAR(255) NULL COMMENT 'Sätts när anropet hör till en domän utan org',
    user_email VARCHAR(255) NULL,
    course_id INT NULL,
    feature VARCHAR(64) NOT NULL COMMENT 'course_gen | lesson_gen | chat | image | other',
    model VARCHAR(64) NOT NULL,
    prompt_tokens INT NOT NULL DEFAULT 0,
    completion_tokens INT NOT NULL DEFAULT 0,
    total_tokens INT NOT NULL DEFAULT 0,
    cost_cents INT NOT NULL DEFAULT 0 COMMENT 'Beräknad kostnad i USD-cent',
    status ENUM('ok','blocked','error') NOT NULL DEFAULT 'ok',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_created (organization_id, created_at),
    INDEX idx_dom_created (domain, created_at),
    INDEX idx_course (course_id),
    INDEX idx_feature_created (feature, created_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
