ALTER TABLE players ADD COLUMN club_name TEXT NOT NULL DEFAULT 'PALMEIRAS';

-- garante que o legado fica correto
UPDATE players SET club_name='PALMEIRAS' WHERE club_name IS NULL OR club_name='';
