-- Migration: 019_add_run_participant_left_at.sql
-- Description: Track when a participant leaves a run (basic time participation)

ALTER TABLE run_participants
ADD COLUMN left_at DATETIME NULL AFTER joined_at;

INSERT INTO migrations (version, description)
VALUES (19, 'Add left_at to run_participants');

