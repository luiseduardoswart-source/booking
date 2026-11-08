-- Runs once, on the first start of an empty container (see compose.yaml).
--
-- Besides the database for the local site, this creates a second one for the
-- test suite on MariaDB (phpunit.mysql.xml). That one is emptied on every test
-- run, so it must be separate from the database that holds your local data.
--
-- The names are fixed, as in compose.yaml; an SQL file cannot read variables.
CREATE DATABASE IF NOT EXISTS `airbnb_test`;
GRANT ALL PRIVILEGES ON `airbnb_test`.* TO 'airbnb'@'%';
FLUSH PRIVILEGES;
