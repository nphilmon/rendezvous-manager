INSERT INTO roles (id, code, label) VALUES
  (1, 'admin', 'Administrateur'),
  (2, 'manager', 'Responsable'),
  (3, 'user', 'Utilisateur'),
  (4, 'readonly', 'Lecture seule');

INSERT INTO permissions (code, label) VALUES
  ('appointments.read', 'Consulter les rendez-vous'),
  ('appointments.write', 'Créer et modifier les rendez-vous'),
  ('appointments.delete', 'Supprimer les rendez-vous'),
  ('calendars.create', 'Créer des calendriers'),
  ('calendars.manage_all', 'Gérer tous les calendriers'),
  ('contacts.read', 'Consulter les contacts'),
  ('contacts.write', 'Gérer les contacts'),
  ('categories.manage', 'Gérer les catégories'),
  ('reports.view', 'Consulter les rapports'),
  ('data.export', 'Exporter les données'),
  ('data.import', 'Importer des données'),
  ('users.manage', 'Gérer les utilisateurs'),
  ('settings.admin', 'Administrer les paramètres'),
  ('audit.view', 'Consulter le journal d''audit'),
  ('backups.manage', 'Gérer les sauvegardes');

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 1, id FROM permissions;

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 2, id FROM permissions
  WHERE code IN ('appointments.read','appointments.write','appointments.delete','calendars.create','calendars.manage_all',
                 'contacts.read','contacts.write','categories.manage','reports.view','data.export','data.import','audit.view');

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 3, id FROM permissions
  WHERE code IN ('appointments.read','appointments.write','appointments.delete','calendars.create',
                 'contacts.read','contacts.write','data.export','data.import');

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 4, id FROM permissions
  WHERE code IN ('appointments.read','contacts.read');
