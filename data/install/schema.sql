CREATE TABLE contribute_token (
    id INT AUTO_INCREMENT NOT NULL,
    resource_id INT NOT NULL,
    token VARCHAR(40) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    expire DATETIME DEFAULT NULL,
    created DATETIME NOT NULL,
    accessed DATETIME DEFAULT NULL,
    INDEX IDX_FB07DAEE89329D25 (resource_id),
    INDEX contribute_token_idx (token),
    INDEX contribute_expire_idx (expire),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE contribute (
    id INT AUTO_INCREMENT NOT NULL,
    resource_id INT NOT NULL,
    token_id INT DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    reviewed TINYINT(1) NOT NULL,
    proposal LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)',
    created DATETIME NOT NULL,
    modified DATETIME DEFAULT NULL,
    INDEX IDX_A29DA1B889329D25 (resource_id),
    UNIQUE INDEX UNIQ_A29DA1B841DEE7B9 (token_id),
    INDEX contribute_email_idx (email),
    INDEX contribute_modified_idx (modified),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE contribute_token ADD CONSTRAINT FK_FB07DAEE89329D25 FOREIGN KEY (resource_id) REFERENCES resource (id) ON DELETE CASCADE;
ALTER TABLE contribute ADD CONSTRAINT FK_A29DA1B889329D25 FOREIGN KEY (resource_id) REFERENCES resource (id) ON DELETE CASCADE;
ALTER TABLE contribute ADD CONSTRAINT FK_A29DA1B841DEE7B9 FOREIGN KEY (token_id) REFERENCES contribute_token (id) ON DELETE SET NULL;
