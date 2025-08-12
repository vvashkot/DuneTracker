-- Migration: 023_add_circuit_number.sql (public copy for Hostinger deploy)
-- Description: Add circuit_number (1-8) to circuit_roster and unique week+slot index

ALTER TABLE circuit_roster
  ADD COLUMN circuit_number TINYINT NULL AFTER week_start,
  ADD UNIQUE KEY uniq_week_circuit (week_start, circuit_number);

INSERT INTO migrations (version, description)
VALUES (23, 'Add circuit_number to circuit_roster with unique week+slot');


