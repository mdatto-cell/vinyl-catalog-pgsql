-- Run once as PostgreSQL administrator, outside a transaction.
-- Replace CHANGE_ME before running. Import schema using vinyl_user.
CREATE ROLE vinyl_user LOGIN PASSWORD 'CHANGE_ME';
CREATE DATABASE vinyl_catalog OWNER vinyl_user ENCODING 'UTF8' TEMPLATE template0;
