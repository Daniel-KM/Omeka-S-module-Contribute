CREATE TABLE `contribution` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `resource_id` INT DEFAULT NULL,
    `owner_id` INT DEFAULT NULL,
    `token_id` INT DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `patch` TINYINT(1) NOT NULL,
    `submitted` TINYINT(1) NOT NULL,
    `reviewed` TINYINT(1) NOT NULL,
    `proposal` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    `created` DATETIME NOT NULL,
    `modified` DATETIME DEFAULT NULL,
    INDEX IDX_EA351E1589329D25 (`resource_id`),
    INDEX IDX_EA351E157E3C61F9 (`owner_id`),
    UNIQUE INDEX UNIQ_EA351E1541DEE7B9 (`token_id`),
    INDEX `contribute_email_idx` (`email`),
    INDEX `contribute_modified_idx` (`modified`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE `contribution_token` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `resource_id` INT NOT NULL,
    `token` VARCHAR(40) NOT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `expire` DATETIME DEFAULT NULL,
    `created` DATETIME NOT NULL,
    `accessed` DATETIME DEFAULT NULL,
    INDEX IDX_3A44AA8989329D25 (`resource_id`),
    INDEX `contribution_token_idx` (`token`),
    INDEX `contribution_expire_idx` (`expire`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE `contribution` ADD CONSTRAINT FK_EA351E1589329D25 FOREIGN KEY (`resource_id`) REFERENCES `resource` (`id`) ON DELETE SET NULL;
ALTER TABLE `contribution` ADD CONSTRAINT FK_EA351E157E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
ALTER TABLE `contribution` ADD CONSTRAINT FK_EA351E1541DEE7B9 FOREIGN KEY (`token_id`) REFERENCES `contribution_token` (`id`) ON DELETE SET NULL;
ALTER TABLE `contribution_token` ADD CONSTRAINT FK_3A44AA8989329D25 FOREIGN KEY (`resource_id`) REFERENCES `resource` (`id`) ON DELETE CASCADE;