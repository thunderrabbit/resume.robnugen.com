<?php

declare(strict_types=1);

namespace Resume;

/**
 * The résumé tables for an in-memory SQLite: the Unit suite's database and
 * `bin/import_resume.php --check`. Same table and column names as
 * db_schemas/03_resume/create_resume_tables.sql, which ResumeSchemaFilesTest
 * keeps honest. SQLite has no ENUM, so the importer validates those values.
 */
final class SqliteSchema
{
    private const STATEMENTS = [
        'CREATE TABLE sources (
            source_id INTEGER PRIMARY KEY AUTOINCREMENT, captured_on TEXT NOT NULL,
            source_title TEXT NOT NULL, history_path TEXT, origin TEXT)',
        'CREATE TABLE sections (
            section_id INTEGER PRIMARY KEY AUTOINCREMENT, section_slug TEXT NOT NULL UNIQUE,
            heading TEXT NOT NULL, default_order INTEGER NOT NULL)',
        'CREATE TABLE organizations (
            organization_id INTEGER PRIMARY KEY AUTOINCREMENT, org_slug TEXT NOT NULL UNIQUE,
            city TEXT, country TEXT, org_url TEXT)',
        'CREATE TABLE tags (
            tag_id INTEGER PRIMARY KEY AUTOINCREMENT, tag_slug TEXT NOT NULL UNIQUE,
            tag_kind TEXT NOT NULL)',
        'CREATE TABLE skills (
            skill_id INTEGER PRIMARY KEY AUTOINCREMENT, skill_name TEXT NOT NULL UNIQUE,
            skill_category TEXT)',
        'CREATE TABLE entries (
            entry_id INTEGER PRIMARY KEY AUTOINCREMENT, section_id INTEGER NOT NULL,
            organization_id INTEGER, parent_entry_id INTEGER, entry_slug TEXT NOT NULL UNIQUE,
            started_on TEXT, started_precision TEXT, ended_on TEXT, ended_precision TEXT,
            location_text TEXT, include_by_default INTEGER NOT NULL DEFAULT 1,
            sort_hint INTEGER NOT NULL DEFAULT 0, private_note TEXT, source_id INTEGER)',
        'CREATE TABLE entry_texts (
            entry_text_id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER NOT NULL,
            field TEXT NOT NULL, body TEXT NOT NULL, written_on TEXT NOT NULL, retired_on TEXT,
            priority INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0,
            private_note TEXT, source_id INTEGER)',
        'CREATE TABLE entry_links (
            entry_link_id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER NOT NULL,
            url TEXT NOT NULL, link_kind TEXT)',
        'CREATE TABLE entry_skills (
            entry_id INTEGER NOT NULL, skill_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (entry_id, skill_id))',
        'CREATE TABLE entry_tags (
            entry_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (entry_id, tag_id))',
        'CREATE TABLE entry_text_tags (
            entry_text_id INTEGER NOT NULL, tag_id INTEGER NOT NULL,
            PRIMARY KEY (entry_text_id, tag_id))',
        'CREATE TABLE contact_points (
            contact_point_id INTEGER PRIMARY KEY AUTOINCREMENT, contact_kind TEXT NOT NULL,
            contact_value TEXT NOT NULL, valid_from TEXT, valid_until TEXT,
            priority INTEGER NOT NULL DEFAULT 0, is_private INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE contact_point_tags (
            contact_point_id INTEGER NOT NULL, tag_id INTEGER NOT NULL,
            PRIMARY KEY (contact_point_id, tag_id))',
        'CREATE TABLE profiles (
            profile_id INTEGER PRIMARY KEY AUTOINCREMENT, profile_slug TEXT NOT NULL UNIQUE,
            profile_title TEXT NOT NULL, as_of TEXT, wording_as_of TEXT,
            is_public INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE profile_tags (
            profile_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (profile_id, tag_id))',
        'CREATE TABLE profile_sections (
            profile_id INTEGER NOT NULL, section_id INTEGER NOT NULL,
            section_order INTEGER NOT NULL, PRIMARY KEY (profile_id, section_id))',
    ];

    public static function create(\PDO $pdo): void
    {
        foreach (self::STATEMENTS as $sql) {
            $pdo->exec($sql);
        }
    }
}
