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
-- >>> TABLE members
DROP TABLE IF EXISTS `members`;
CREATE TABLE `members` (`id` char(36) NOT NULL, `last_name` varchar(100) NOT NULL, `sealed` varbinary(512) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `members` (`id`,`last_name`,`sealed`) VALUES
('00000000-0000-4000-8000-000000000003','Müller-Lüdenscheidt',X'DEADBEEF'),
('00000000-0000-4000-8000-000000000004','O\'Brien',NULL);
-- <<< TABLE members
