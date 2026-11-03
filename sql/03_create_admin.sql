-- psql variables admin_user and admin_hash must be supplied.
-- Prefer tools/create_admin.php, which accepts a PHP password_hash via stdin.
INSERT INTO users(username,password_hash) VALUES (:'admin_user',:'admin_hash')
ON CONFLICT (lower(username)) DO NOTHING;
