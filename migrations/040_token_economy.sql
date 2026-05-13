-- Migration 040: Token economy — saldo per organisation, beställningar och paket
-- Skapad: 2026-05-11
--
-- Bakgrund: AI-användning har hittills styrts av en månadskvot per organisation
-- (ai_quotas.monthly_token_quota). Den modellen begränsar förbrukning men
-- erbjuder inget sätt för en organisation att köpa till mer tokens när kvoten
-- tar slut. Vi inför nu ett saldo-baserat system: varje organisation har ett
-- "token-konto" som fylls på dels av en månatlig gratisbas (= den befintliga
-- monthly_token_quota), dels av beställningar som adminanvändare lägger via
-- adminpanelen.
--
-- Designval:
--   * Saldo ersätter månadskvoten: AI-anrop drar från balance och blockeras
--     när balance når 0 (behavior=block i ai_quotas styr fortfarande).
--   * Gratisbasen läggs in den 1:a varje månad (cron) som en token_transactions-
--     rad med type='monthly_refill'.
--   * Beställningar aktiveras direkt — saldo fylls på vid POST. Fakturering
--     görs manuellt av Sambruk utanför systemet.
--   * Recurring orders fyller på paketstorlek den 1:a varje månad, med tak
--     på 3× paketstorlek för att förhindra obegränsad ackumulering.
--   * token_transactions är en append-only ledger för spårbarhet.

-- ---------------------------------------------------------------------------
-- 1. Paketkatalog
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS token_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL COMMENT 'Internt kortnamn: mini, s, m, l, xl, xxl',
    name VARCHAR(100) NOT NULL COMMENT 'Visningsnamn',
    tokens BIGINT NOT NULL COMMENT 'Antal tokens i paketet',
    price_sek_cents INT NOT NULL COMMENT 'Pris i öre SEK ex moms',
    estimated_lessons INT NOT NULL COMMENT 'Ungefärligt antal AI-genererade lektioner',
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seeda 6 paket. Pris baserat på viktad mix (80% gpt-4o-mini, 15% gpt-4o,
-- 5% bilder) ≈ $1.73/1M tokens från OpenAI, × 1.5 marginal ≈ $2.60/1M tokens,
-- omräknat med 10.5 SEK/USD ≈ 27 kr/1M tokens. Avrundat för läsbarhet.
INSERT INTO token_packages
    (code, name, tokens, price_sek_cents, estimated_lessons, description, sort_order)
VALUES
    ('mini', 'Mini',    250000,     9000,    25, 'För enstaka kurser och tester', 10),
    ('s',    'Liten',  1000000,    35000,   100, 'Lagom för en aktiv editor',     20),
    ('m',    'Medium', 2500000,    85000,   250, 'Hela arbetslag',                 30),
    ('l',    'Stor',   5000000,   160000,   500, 'Stor avdelning',                 40),
    ('xl',   'XL',    10000000,   300000,  1000, 'Hela förvaltningen',             50),
    ('xxl',  'XXL',   25000000,   700000,  2500, 'Storkund / koncern',             60)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    tokens = VALUES(tokens),
    price_sek_cents = VALUES(price_sek_cents),
    estimated_lessons = VALUES(estimated_lessons),
    description = VALUES(description),
    sort_order = VALUES(sort_order);


-- ---------------------------------------------------------------------------
-- 2. Saldo per organisation
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS organization_token_balance (
    organization_id INT NOT NULL PRIMARY KEY,
    balance BIGINT NOT NULL DEFAULT 0 COMMENT 'Aktuellt saldo (tokens)',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_otbalance_org FOREIGN KEY (organization_id)
        REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 3. Faktureringsuppgifter per organisation
-- ---------------------------------------------------------------------------
-- Snapshotas också på varje order, men senaste uppgifterna sparas här så att
-- formuläret kan föranifyllas vid nästa beställning.
CREATE TABLE IF NOT EXISTS organization_billing (
    organization_id INT NOT NULL PRIMARY KEY,
    contact_name VARCHAR(255) NOT NULL,
    invoice_address TEXT NOT NULL COMMENT 'Hela fakturaadressen som fritext',
    gln VARCHAR(32) NULL COMMENT 'Global Location Number (13 siffror)',
    peppol VARCHAR(64) NULL COMMENT 'PEPPOL-ID (t.ex. 0007:5560000000)',
    contact_email VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orgbilling_org FOREIGN KEY (organization_id)
        REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 4. Beställningar
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS token_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    package_id INT NOT NULL,
    tokens BIGINT NOT NULL COMMENT 'Snapshotad paketstorlek',
    price_sek_cents INT NOT NULL COMMENT 'Snapshotat pris',
    -- Faktureringsuppgifter snapshotas så att senare ändringar i
    -- organization_billing inte påverkar historiska fakturor.
    billing_contact_name VARCHAR(255) NOT NULL,
    billing_address TEXT NOT NULL,
    billing_gln VARCHAR(32) NULL,
    billing_peppol VARCHAR(64) NULL,
    billing_email VARCHAR(255) NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fyll på den 1:a varje månad',
    recurring_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 om recurring fortfarande är aktiv (kan avslutas)',
    last_refilled_at TIMESTAMP NULL COMMENT 'Senaste gången recurring körde påfyllning',
    created_by VARCHAR(255) NULL COMMENT 'E-post på admin som beställde',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org (organization_id),
    INDEX idx_recurring (recurring_active, is_recurring),
    CONSTRAINT fk_tokenorder_org FOREIGN KEY (organization_id)
        REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_tokenorder_pkg FOREIGN KEY (package_id)
        REFERENCES token_packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 5. Transaktionsledger
-- ---------------------------------------------------------------------------
-- Append-only logg av alla saldoförändringar för spårbarhet.
-- type:
--   monthly_refill — gratisbas från ai_quotas.monthly_token_quota
--   purchase       — beställning av paket
--   recurring      — månatlig auto-påfyllning av aktiv prenumeration
--   consume        — förbrukning från AI-anrop (negativ delta)
--   adjustment     — manuell korrigering av superadmin
CREATE TABLE IF NOT EXISTS token_transactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    type ENUM('monthly_refill','purchase','recurring','consume','adjustment') NOT NULL,
    tokens_delta BIGINT NOT NULL COMMENT 'Positivt = påfyllning, negativt = förbrukning',
    balance_after BIGINT NOT NULL COMMENT 'Saldo efter denna transaktion',
    related_order_id INT NULL,
    related_usage_log_id BIGINT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_created (organization_id, created_at),
    INDEX idx_type (type),
    CONSTRAINT fk_toktx_org FOREIGN KEY (organization_id)
        REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_toktx_order FOREIGN KEY (related_order_id)
        REFERENCES token_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
