# Résumé database design

11 Oct 2026. Status: draft for Rob's review.

## Goal

One database holds every fact Rob has ever put on a résumé, plus every
wording of it. A renderer (out of scope here) asks the database for
"the résumé as of date X, for audience Y" and gets back plain text,
which the template turns into a PDF. The database holds data only. It
holds no layout, fonts, column alignment, or page breaks.

## Public code, private data

This repo is public. Résumé data is not. Past versions, seed data
and database dumps hold a street address, phone numbers and private
notes, so they live in a separate repo that is never published (on
Rob's machine at `/home/thunderrabbit/work/rob/jobhunt/resume_private`). Nothing personal is committed
here: no seed SQL, no exports, no sample PDFs built from real data.
Tests and examples use invented data.

## Sources surveyed

Eight past versions, now in the private repo: two journal versions
(2005, 2007), four Word drafts from Sep 2022, and two exports of the
current Google Doc (11 Oct 2026). Every finding below comes from
comparing them.

## What the sources showed

1. **Section types:** contact, objective/summary, experience (tech and
   teaching), leadership, volunteer, speaking, education (with
   sub-items such as extracurriculars and coursework languages),
   certifications, languages, and travel/other (road trips, stage hand,
   painting shown in Harajuku). Publications and projects are coming.
2. **The same job is worded many ways.** One long-running role appears
   under four different titles across versions. Wording changes over
   time and by audience, so wording has to be stored as dated, taggable
   variants and not as a column.
3. **The dates of the facts themselves conflict.** One start date
   differs by a year between versions, and another by a month. These
   are data errors, settled once from primary sources (old journal
   entries), not variants.
4. **Contact details change over time:** three phone numbers over two
   decades, and a full street address that later became just a city.
5. **Technologies repeat across jobs** and get duplicated by hand (the
   "Java, … Java" bug). They belong in a skills table joined to
   entries.
6. **Some true facts are private,** such as why a project was dropped
   or why a group was founded. These need a place in the database that
   never renders.
7. **Jobs nest and overlap.** A client account was handled inside one
   employer, then taken over directly when that employer closed. A side
   company went quiet for years and later became active again without
   ever ending.

## The two time axes ("any when")

- **Happened time:** `entries.started_on` / `ended_on`, i.e. when the
  job or talk actually happened.
- **Written time:** `entry_texts.written_on` / `retired_on`, i.e. when
  a given wording was in use.

A render takes two dates:

- `as_of`: include entries that had started by this date. An entry
  still running on that date shows as "Present".
- `wording_as_of`: for each entry, use the wording that was in use on
  this date.

How the two knobs combine:

| `as_of` | `wording_as_of` | Result |
|---|---|---|
| today | today | Current résumé |
| 2007-06-14 | 2007-06-14 | Rebuild of the 2007 résumé, in today's template |
| 2015-01-01 | today | "Me in 2015", in today's best wording |

The exact original documents stay in `history/`. The database
reproduces their *content*, not their layout.

## Audiences and markets

`tags` covers both, e.g. `programming`, `ai`, `speaking`,
`leadership`, `publications`, `teaching`, `market-jp`, `market-au`.

- **Entry selection:** with no tags requested, render entries that have
  `include_by_default = 1`. With tags requested, render entries that
  carry at least one of them.
- **Wording selection:** among the texts that are live at
  `wording_as_of` for an (entry, field), prefer the one matching the
  most requested tags, then untagged texts, then the higher `priority`,
  then the newest `written_on`.
- **Contact points** are filtered the same way. For example,
  `market-au` selects the WhatsApp number and the relocation line.

## Schema (MySQL, InnoDB, utf8mb4)

Every primary key name is unique across the project.

```sql
CREATE TABLE sources (
  source_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  captured_on    DATE NOT NULL,          -- when that version was written
  source_title   VARCHAR(255) NOT NULL,
  history_path   VARCHAR(255) NULL,      -- path in the private repo
  origin         VARCHAR(255) NULL       -- git sha, URL, "Rob in chat"
);

CREATE TABLE sections (
  section_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_slug   VARCHAR(40) NOT NULL UNIQUE,   -- experience, leadership ...
  heading        VARCHAR(100) NOT NULL,
  default_order  SMALLINT NOT NULL
);

CREATE TABLE organizations (
  organization_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_slug       VARCHAR(60) NOT NULL UNIQUE,   -- fsd, telecheck, mkp-japan
  city           VARCHAR(100) NULL,
  country        VARCHAR(100) NULL,
  org_url        VARCHAR(255) NULL
  -- the org's display name is an entry_texts row (field org_name),
  -- because "FSD Data Services" vs "FSD Services" is a wording choice
);

CREATE TABLE entries (
  entry_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id      INT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NULL,
  parent_entry_id INT UNSIGNED NULL,     -- UH -> Recycling Chair etc.
  entry_slug      VARCHAR(80) NOT NULL UNIQUE,
  started_on      DATE NULL,             -- NULL for undated (summary, language)
  started_precision ENUM('day','month','season','year') NULL,
  ended_on        DATE NULL,             -- NULL = ongoing
  ended_precision ENUM('day','month','season','year') NULL,
  location_text   VARCHAR(150) NULL,     -- "49th and 52nd global voyages"
  include_by_default TINYINT(1) NOT NULL DEFAULT 1,
  sort_hint       SMALLINT NOT NULL DEFAULT 0,
  private_note    TEXT NULL,             -- never rendered
  source_id       INT UNSIGNED NULL,
  FOREIGN KEY (section_id) REFERENCES sections (section_id),
  FOREIGN KEY (organization_id) REFERENCES organizations (organization_id),
  FOREIGN KEY (parent_entry_id) REFERENCES entries (entry_id),
  FOREIGN KEY (source_id) REFERENCES sources (source_id)
);

CREATE TABLE entry_texts (
  entry_text_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id       INT UNSIGNED NOT NULL,
  field          ENUM('title','org_name','summary','bullet','url_label') NOT NULL,
  body           TEXT NOT NULL,
  written_on     DATE NOT NULL,
  retired_on     DATE NULL,              -- NULL = still in use
  priority       SMALLINT NOT NULL DEFAULT 0,
  sort_order     SMALLINT NOT NULL DEFAULT 0,  -- bullets within an entry
  private_note   TEXT NULL,
  source_id      INT UNSIGNED NULL,
  FOREIGN KEY (entry_id) REFERENCES entries (entry_id),
  FOREIGN KEY (source_id) REFERENCES sources (source_id)
);

CREATE TABLE entry_links (
  entry_link_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id       INT UNSIGNED NOT NULL,
  url            VARCHAR(500) NOT NULL,  -- TEDx video, PechaKucha page, ISBN page
  link_kind      VARCHAR(40) NULL,       -- video, slides, book, repo
  FOREIGN KEY (entry_id) REFERENCES entries (entry_id)
);

CREATE TABLE skills (
  skill_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  skill_name     VARCHAR(100) NOT NULL UNIQUE,   -- "Java" exists once
  skill_category VARCHAR(40) NULL                -- language, ai, infra, data
);

CREATE TABLE entry_skills (
  entry_id       INT UNSIGNED NOT NULL,
  skill_id       INT UNSIGNED NOT NULL,
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (entry_id, skill_id),
  FOREIGN KEY (entry_id) REFERENCES entries (entry_id),
  FOREIGN KEY (skill_id) REFERENCES skills (skill_id)
);

CREATE TABLE contact_points (
  contact_point_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_kind   ENUM('name','location','phone','whatsapp','email','url','relocation') NOT NULL,
  contact_value  VARCHAR(255) NOT NULL,
  valid_from     DATE NULL,
  valid_until    DATE NULL,
  priority       SMALLINT NOT NULL DEFAULT 0,
  is_private     TINYINT(1) NOT NULL DEFAULT 0   -- the 2022 street address
);

CREATE TABLE tags (
  tag_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tag_slug       VARCHAR(40) NOT NULL UNIQUE,
  tag_kind       ENUM('audience','market') NOT NULL
);

CREATE TABLE entry_tags (
  entry_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (entry_id, tag_id),
  FOREIGN KEY (entry_id) REFERENCES entries (entry_id),
  FOREIGN KEY (tag_id) REFERENCES tags (tag_id)
);
CREATE TABLE entry_text_tags (
  entry_text_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (entry_text_id, tag_id),
  FOREIGN KEY (entry_text_id) REFERENCES entry_texts (entry_text_id),
  FOREIGN KEY (tag_id) REFERENCES tags (tag_id)
);
CREATE TABLE contact_point_tags (
  contact_point_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (contact_point_id, tag_id),
  FOREIGN KEY (contact_point_id) REFERENCES contact_points (contact_point_id),
  FOREIGN KEY (tag_id) REFERENCES tags (tag_id)
);

-- Saved click-clack presets: the public default plus Rob's tailored ones
CREATE TABLE profiles (
  profile_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_slug   VARCHAR(60) NOT NULL UNIQUE,
  profile_title  VARCHAR(150) NOT NULL,
  as_of          DATE NULL,              -- NULL = today
  wording_as_of  DATE NULL,              -- NULL = today
  is_public      TINYINT(1) NOT NULL DEFAULT 0   -- exactly one row serves the public PDF
);
CREATE TABLE profile_tags (
  profile_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (profile_id, tag_id),
  FOREIGN KEY (profile_id) REFERENCES profiles (profile_id),
  FOREIGN KEY (tag_id) REFERENCES tags (tag_id)
);
CREATE TABLE profile_sections (
  profile_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  section_order SMALLINT NOT NULL,
  PRIMARY KEY (profile_id, section_id),
  FOREIGN KEY (profile_id) REFERENCES profiles (profile_id),
  FOREIGN KEY (section_id) REFERENCES sections (section_id)
);
```

The real migration files will follow the template's conventions:
`db_schemas/NN_name/*.sql`, `ENGINE=InnoDB`, named FK constraints,
and no semicolons inside comments.

## Deliberately left out

- **Layout:** fonts, columns and the date-dash style belong to the
  template.
- **Per-version section headings:** "Tech Experience" vs "Professional
  Experience". `sections.heading` holds one heading. Add variants only
  if a rebuild ever needs the old heading.
- **A Japanese 履歴書:** different structure. A later project if wanted.

## Modelling patterns the data needs

- **A client handled inside an employer, then taken over directly:**
  one child entry (`parent_entry_id` = the employer) for the years the
  work ran through the employer, plus one top-level entry from the
  takeover onward. Renders read "took over the account when X closed".
- **A dormant but never-ended role:** one entry with `ended_on` NULL.
  The quiet years and the revival are told by dated `entry_texts`, so
  an old `wording_as_of` gets the old wording and today gets the new.
  Known limit: a rebuild of a version that printed an end date for
  such a role will show "Present", because the database stores what is
  true now, not what an old version claimed.
- **Something that changes over time, like the street address:** add
  `contact_points` rows with `valid_from`/`valid_until`, and mark any
  row that should never print with `is_private`. Whether a given PDF
  shows the address is then a profile choice, not a schema change.
