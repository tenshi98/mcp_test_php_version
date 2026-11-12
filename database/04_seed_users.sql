-- 04_seed_users.sql
-- Usuarios de ejemplo. CAMBIE estos correos por los de sus usuarios reales
-- (el correo es la clave con que se vincula la identidad OAuth externa).
INSERT INTO `allowed_users` (`name`, `email`, `role`, `status`) VALUES
  ('Administrador Demo', 'admin@example.com',  'admin',  'active'),
  ('Editor Demo',        'editor@example.com', 'editor', 'active'),
  ('Lector Demo',        'viewer@example.com', 'viewer', 'active'),
  ('Usuario Suspendido', 'suspended@example.com', 'viewer', 'suspended');
