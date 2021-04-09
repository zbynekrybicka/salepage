-- Adminer 4.8.0 MySQL 5.5.5-10.3.28-MariaDB-log dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `email`;
CREATE TABLE `email` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sequence` char(25) CHARACTER SET utf8 NOT NULL,
  `email` char(255) CHARACTER SET utf8 NOT NULL,
  `active` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `verified` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sequence_email` (`sequence`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS `email_message`;
CREATE TABLE `email_message` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email_id` int(10) unsigned NOT NULL,
  `sequence_time` char(25) NOT NULL,
  `send_at` datetime NOT NULL,
  `subject` char(255) CHARACTER SET utf8 NOT NULL,
  `content` text CHARACTER SET utf8 NOT NULL,
  `sender` char(255) CHARACTER SET utf8 NOT NULL,
  `is_sent` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_id_sequence_time` (`email_id`,`sequence_time`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign` char(255) CHARACTER SET utf8 NOT NULL,
  `name` char(255) CHARACTER SET utf8 NOT NULL,
  `b_street` char(255) CHARACTER SET utf8 NOT NULL,
  `b_city` char(255) CHARACTER SET utf8 NOT NULL,
  `b_zip` char(255) CHARACTER SET utf8 NOT NULL,
  `email` char(255) CHARACTER SET utf8 NOT NULL,
  `phone` char(255) CHARACTER SET utf8 NOT NULL,
  `p_street` char(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `p_city` char(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `p_zip` char(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `paid` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


-- 2021-04-05 10:31:19