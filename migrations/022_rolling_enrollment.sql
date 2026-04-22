-- Migration 022: Stöd för löpande registrering (rolling enrollment) i stegvisa kurser
-- Tillåter att användare skrivs in individuellt med olika startdatum

ALTER TABLE courses
  ADD COLUMN enrollment_type ENUM('bulk_start', 'rolling') NOT NULL DEFAULT 'bulk_start'
  AFTER sequential_status;
