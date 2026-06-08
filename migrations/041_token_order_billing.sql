-- Migration 041: Debiteringsstatus på tokenbeställningar
-- Skapad: 2026-06-08
--
-- Bakgrund: Tokenbeställningar (token_orders) aktiverades direkt i systemet
-- medan själva faktureringen sköts manuellt av Sambruk utanför systemet
-- (se migration 040). Det saknades ett sätt för superadmin att se alla
-- inkomna beställningar och hålla reda på vilka som faktiskt har debiterats
-- kund.
--
-- Vi lägger till två kolumner som tillsammans utgör debiteringsstatusen:
--   billed_at  — tidpunkt då beställningen markerades som debiterad.
--                NULL = ej debiterad (default). Sätts/nollas av superadmin.
--   billed_by  — e-post på den superadmin som markerade den debiterad.
--
-- En enkel "växel" (markera debiterad / ej debiterad) räcker; vi sparar
-- tidsstämpel och vem för spårbarhet. Ingen separat fakturareferens lagras.

ALTER TABLE token_orders
    ADD COLUMN billed_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'När beställningen markerades debiterad (NULL = ej debiterad)',
    ADD COLUMN billed_by VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'E-post på superadmin som markerade beställningen debiterad';

-- Index för att snabbt filtrera ej debiterade beställningar i översikten.
ALTER TABLE token_orders
    ADD INDEX idx_billed_at (billed_at);
