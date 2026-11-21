-- Premier usage : chaque compte pouvant écrire reçoit un calendrier personnel (« Mon calendrier ») s'il n'en possède aucun.
-- Sans cela, « Nouveau rendez-vous » répond « Vous n'avez accès en création à aucun calendrier » après une installation neuve.
INSERT INTO calendars (owner_id, name, kind, visibility, timezone)
SELECT u.id, 'Mon calendrier', 'personal', 'private', u.timezone
FROM users u
JOIN roles r ON r.id = u.role_id
WHERE r.code <> 'readonly'
  AND NOT EXISTS (SELECT 1 FROM calendars c WHERE c.owner_id = u.id AND c.deleted_at IS NULL);
