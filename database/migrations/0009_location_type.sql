-- Type de lieu d'un rendez-vous : sur place, visioconférence, téléphone ou autre
ALTER TABLE appointments ADD COLUMN location_type VARCHAR(10) NOT NULL DEFAULT 'onsite' AFTER location;
