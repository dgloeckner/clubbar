-- Club Bar database dump
-- Restore with a client that honours the session settings below.
--

SET NAMES utf8mb4;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
SET @OLD_TIME_ZONE = @@TIME_ZONE;
SET time_zone = '+00:00';  -- TIMESTAMP is converted by the session zone
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- >>> TABLE categories
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (`id` char(36) NOT NULL, `name` varchar(100) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `categories` (`id`,`name`) VALUES
('00000000-0000-4000-8000-000000000001','-- >>> TABLE getranke'),
('00000000-0000-4000-8000-000000000002','Süßwaren');
-- <<< TABLE categories

-- >>> TABLE members
DROP TABLE IF EXISTS `members`;
CREATE TABLE `members` (`id` char(36) NOT NULL, `last_name` varchar(100) NOT NULL, `sealed` varbinary(512) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `members` (`id`,`last_name`,`sealed`) VALUES
('00000000-0000-4000-8000-000000000003','Müller-Lüdenscheidt',X'DEADBEEF'),
('00000000-0000-4000-8000-000000000004','O\'Brien',NULL);
-- <<< TABLE members

SET time_zone = @OLD_TIME_ZONE;
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE = @OLD_SQL_MODE;
-- Dump complete

-- >>> CONFIG config.php (base64)
-- The configuration this database was dumped from. Not executable SQL: every
-- line here is a comment, so importing this file whole is safe. Restoring onto
-- a new host needs this as well as the rows — without security.totp_encryption_key
-- every admin's second factor fails and nobody can log in.
-- PD9waHAKLy8gQSBnb2xkZW4gZml4dHVyZS4gTWVudGlvbnMgLS0gPDw8IENPTkZJRyBkZWxpYmVy
-- YXRlbHkuCnJldHVybiBbCiAgICAnc2VjdXJpdHknID0+IFsndG90cF9lbmNyeXB0aW9uX2tleScg
-- PT4gJ25vdC1hLXJlYWwta2V5LcO8bWxhdXQnXSwKXTsK
-- <<< CONFIG
