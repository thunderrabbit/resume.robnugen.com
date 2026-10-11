-- Resume data. Data only, no layout. See docs/superpowers/specs/2026-10-11-resume-database-design.md
-- Keep semicolons out of these comments. executeMultipleSQL() splits on them.
-- Every REFERENCES target is created above the table that points at it.

CREATE TABLE `sources` (
  `source_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- when that version of the resume was written
  `captured_on` DATE NOT NULL,
  `source_title` VARCHAR(255) NOT NULL,
  -- path inside the private data repo, never this one
  `history_path` VARCHAR(255) DEFAULT NULL,
  `origin` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sections` (
  `section_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `section_slug` VARCHAR(40) NOT NULL,
  `heading` VARCHAR(100) NOT NULL,
  `default_order` SMALLINT NOT NULL,
  UNIQUE KEY `section_slug` (`section_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organizations` (
  `organization_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `org_slug` VARCHAR(60) NOT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `org_url` VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY `org_slug` (`org_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tags` (
  `tag_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tag_slug` VARCHAR(40) NOT NULL,
  `tag_kind` ENUM('audience', 'market') NOT NULL,
  UNIQUE KEY `tag_slug` (`tag_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `skills` (
  `skill_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `skill_name` VARCHAR(100) NOT NULL,
  `skill_category` VARCHAR(40) DEFAULT NULL,
  UNIQUE KEY `skill_name` (`skill_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entries` (
  `entry_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `section_id` INT UNSIGNED NOT NULL,
  `organization_id` INT UNSIGNED DEFAULT NULL,
  `parent_entry_id` INT UNSIGNED DEFAULT NULL,
  `entry_slug` VARCHAR(80) NOT NULL,
  -- NULL for undated entries such as a summary or a language
  `started_on` DATE DEFAULT NULL,
  `started_precision` ENUM('day', 'month', 'season', 'year') DEFAULT NULL,
  -- NULL means ongoing. A one-day event sets this equal to started_on.
  `ended_on` DATE DEFAULT NULL,
  `ended_precision` ENUM('day', 'month', 'season', 'year') DEFAULT NULL,
  `location_text` VARCHAR(150) DEFAULT NULL,
  `include_by_default` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_hint` SMALLINT NOT NULL DEFAULT 0,
  -- never rendered
  `private_note` TEXT DEFAULT NULL,
  `source_id` INT UNSIGNED DEFAULT NULL,
  UNIQUE KEY `entry_slug` (`entry_slug`),
  CONSTRAINT `fk_entries_section_id` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`),
  CONSTRAINT `fk_entries_organization_id`
    FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`organization_id`),
  CONSTRAINT `fk_entries_parent_entry_id` FOREIGN KEY (`parent_entry_id`) REFERENCES `entries` (`entry_id`),
  CONSTRAINT `fk_entries_source_id` FOREIGN KEY (`source_id`) REFERENCES `sources` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_texts` (
  `entry_text_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entry_id` INT UNSIGNED NOT NULL,
  `field` ENUM('title', 'org_name', 'summary', 'bullet', 'url_label') NOT NULL,
  `body` TEXT NOT NULL,
  `written_on` DATE NOT NULL,
  -- NULL means still in use
  `retired_on` DATE DEFAULT NULL,
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  `private_note` TEXT DEFAULT NULL,
  `source_id` INT UNSIGNED DEFAULT NULL,
  CONSTRAINT `fk_entry_texts_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`entry_id`),
  CONSTRAINT `fk_entry_texts_source_id` FOREIGN KEY (`source_id`) REFERENCES `sources` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_links` (
  `entry_link_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entry_id` INT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `link_kind` VARCHAR(40) DEFAULT NULL,
  CONSTRAINT `fk_entry_links_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_skills` (
  `entry_id` INT UNSIGNED NOT NULL,
  `skill_id` INT UNSIGNED NOT NULL,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`entry_id`, `skill_id`),
  CONSTRAINT `fk_entry_skills_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`entry_id`),
  CONSTRAINT `fk_entry_skills_skill_id` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_tags` (
  `entry_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`entry_id`, `tag_id`),
  CONSTRAINT `fk_entry_tags_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`entry_id`),
  CONSTRAINT `fk_entry_tags_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_text_tags` (
  `entry_text_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`entry_text_id`, `tag_id`),
  CONSTRAINT `fk_entry_text_tags_entry_text_id`
    FOREIGN KEY (`entry_text_id`) REFERENCES `entry_texts` (`entry_text_id`),
  CONSTRAINT `fk_entry_text_tags_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_points` (
  `contact_point_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `contact_kind` ENUM('name', 'location', 'phone', 'whatsapp', 'email', 'url', 'relocation') NOT NULL,
  `contact_value` VARCHAR(255) NOT NULL,
  `valid_from` DATE DEFAULT NULL,
  `valid_until` DATE DEFAULT NULL,
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `is_private` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_point_tags` (
  `contact_point_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`contact_point_id`, `tag_id`),
  CONSTRAINT `fk_contact_point_tags_contact_point_id`
    FOREIGN KEY (`contact_point_id`) REFERENCES `contact_points` (`contact_point_id`),
  CONSTRAINT `fk_contact_point_tags_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `profiles` (
  `profile_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `profile_slug` VARCHAR(60) NOT NULL,
  `profile_title` VARCHAR(150) NOT NULL,
  -- NULL means today, for both dates
  `as_of` DATE DEFAULT NULL,
  `wording_as_of` DATE DEFAULT NULL,
  -- at most one row, enforced by the importer, serves the public page
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `profile_slug` (`profile_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `profile_tags` (
  `profile_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`profile_id`, `tag_id`),
  CONSTRAINT `fk_profile_tags_profile_id` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`profile_id`),
  CONSTRAINT `fk_profile_tags_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `profile_sections` (
  `profile_id` INT UNSIGNED NOT NULL,
  `section_id` INT UNSIGNED NOT NULL,
  `section_order` SMALLINT NOT NULL,
  PRIMARY KEY (`profile_id`, `section_id`),
  CONSTRAINT `fk_profile_sections_profile_id` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`profile_id`),
  CONSTRAINT `fk_profile_sections_section_id` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
