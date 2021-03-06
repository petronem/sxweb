 -- SXWeb Version 0.4 

DROP TABLE IF EXISTS `shared`;
CREATE TABLE `shared` (
  `file_id` varchar(255) NOT NULL, 
  `user_auth_token` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  `expire_at` datetime NOT NULL,
  `file_password` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `ticket_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned DEFAULT NULL,
  `ip_addr` varchar(45) DEFAULT NULL,
  `ticket_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `login` VARCHAR(255) NOT NULL UNIQUE,
  `email` varchar(50) NOT NULL,
  `secret_key` varchar(255) NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT '0',
  `preferences` text NOT NULL,
  `user_role` enum('guest','registered','admin') NOT NULL DEFAULT 'registered'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `user_reset_password`;
CREATE TABLE `user_reset_password` (
  `hash` varchar(255) NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `counter` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `login` VARCHAR(255) NOT NULL UNIQUE,
  `email` VARCHAR(255) NOT NULL,
  KEY `hash` (`hash`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `sxweb_config`;
CREATE TABLE `sxweb_config` (
  `item` VARCHAR(64) PRIMARY KEY,
  `value` TEXT 
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `sxweb_config` (`item`, `value`) VALUES ('db_version', '0.4.0'), ('db_initial_version', '0.4.0'), ('db_created', NOW()),('db_modified', NOW());
