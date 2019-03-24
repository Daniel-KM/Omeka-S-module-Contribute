CREATE TABLE correction_token (
    id INT AUTO_INCREMENT NOT NULL,
    resource_id INT NOT NULL,
    token VARCHAR(40) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    expire DATETIME DEFAULT NULL,
    created DATETIME NOT NULL,
    accessed DATETIME DEFAULT NULL,
    INDEX IDX_FB07DAEE89329D25 (resource_id),
    INDEX token_idx (token),
    INDEX expire_idx (expire),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE correction_token ADD CONSTRAINT FK_FB07DAEE89329D25 FOREIGN KEY (resource_id) REFERENCES resource (id) ON DELETE CASCADE;
