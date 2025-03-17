CREATE TABLE `resource_template_data` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `resource_template_id` INT NOT NULL,
    `data` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    UNIQUE INDEX `uniq_resource_template_id` (`resource_template_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE `resource_template_property_data` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `resource_template_id` INT NOT NULL,
    `resource_template_property_id` INT NOT NULL,
    `data` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    INDEX `idx_resource_template_id` (`resource_template_id`),
    INDEX `idx_resource_template_property_id` (`resource_template_property_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE `resource_template_data` ADD CONSTRAINT `FK_31D1FFC816131EA` FOREIGN KEY (`resource_template_id`) REFERENCES `resource_template` (`id`) ON DELETE CASCADE;
ALTER TABLE `resource_template_property_data` ADD CONSTRAINT `FK_B133BBAA16131EA` FOREIGN KEY (`resource_template_id`) REFERENCES `resource_template` (`id`) ON DELETE CASCADE;
ALTER TABLE `resource_template_property_data` ADD CONSTRAINT `FK_B133BBAA2A6B767B` FOREIGN KEY (`resource_template_property_id`) REFERENCES `resource_template_property` (`id`) ON DELETE CASCADE;
