-- =============================================================================
-- Vacancy Watch — MySQL Relational Schema (Module 1.1)
-- =============================================================================
-- Normalized 5-table design for Montgomery County Open Data:
--   properties  →  vacant_registrations
--                →  code_violations
--                →  construction_permits
--                →  surplus_properties
--
-- Requirements enforced:
--   • Coordinates stored as DECIMAL(10,8) / DECIMAL(11,8), never VARCHAR.
--   • POINT geometry column (SPATIAL INDEX omitted for MariaDB compat).
--   • Foreign keys on every child table referencing properties(id).
--   • created_at / updated_at timestamps on every table.
--   • source_id for idempotent API upserts.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS vacancy_watch
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE vacancy_watch;

-- =============================================================================
-- 1. PROPERTIES  (Master dimension table — single source of truth for locations)
-- =============================================================================
CREATE TABLE properties (
  id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  parcel_id      VARCHAR(50)     NOT NULL,
  street_address VARCHAR(255)    NULL
    COMMENT 'Physical address; NULL when only parcel ID and coordinates are available',
  city           VARCHAR(100)    NOT NULL DEFAULT 'Montgomery',
  zip_code       VARCHAR(10)     NOT NULL,

  -- Ownership classification — answers "who owns this property?"
  ownership_type ENUM('Private', 'Bank-Owned', 'City-Owned', 'County-Owned', 'State-Owned', 'Federal', 'Unknown')
                                 NOT NULL DEFAULT 'Unknown'
    COMMENT 'Distinguishes private vacancies from government surplus assets',

  -- Decimal coordinates for arithmetic accuracy
  latitude       DECIMAL(10, 8)  NOT NULL,
  longitude      DECIMAL(11, 8)  NOT NULL,

  -- Auto-computed geometry — MySQL derives this from lat/lng on every INSERT/UPDATE.
  -- Eliminates human error; geom_location stays in perfect sync automatically.
  -- NOTE: Uses ST_GeomFromText + CONCAT instead of ST_SRID(POINT(), 4326)
  --        for compatibility with MySQL 5.7 / MariaDB 10.x (XAMPP default).
  geom_location  POINT
    GENERATED ALWAYS AS (ST_GeomFromText(CONCAT('POINT(', longitude, ' ', latitude, ')'), 4326)) STORED
    COMMENT 'Generated column; never set manually',

  created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints & Indexes
  PRIMARY KEY (id),
  UNIQUE  KEY uk_parcel_id          (parcel_id),
  INDEX   idx_address_zip           (street_address, zip_code),
  INDEX   idx_ownership_type        (ownership_type)
  -- NOTE: SPATIAL INDEX removed — MariaDB requires NOT NULL for SPATIAL INDEX
  --       but disallows NOT NULL on STORED generated columns. All anomaly queries
  --       JOIN on property_id, not spatial lookup. Re-add if migrating to MySQL 8.0+.
) ENGINE=InnoDB;

-- =============================================================================
-- 2. VACANT_REGISTRATIONS  (Properties flagged as vacant)
-- =============================================================================
CREATE TABLE vacant_registrations (
  id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  property_id       INT UNSIGNED    NOT NULL,
  source_id         VARCHAR(100)    NULL
    COMMENT 'Original record ID from Montgomery API for idempotent upserts',

  registration_date DATE            NULL,
  status            VARCHAR(50)     NOT NULL DEFAULT 'Open'
    COMMENT 'e.g. Open, Closed, Under Review',

  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints & Indexes
  PRIMARY KEY (id),
  UNIQUE  KEY uk_source_id        (source_id),
  INDEX   idx_property_id        (property_id),
  INDEX   idx_status             (status),

  CONSTRAINT fk_vacant_property
    FOREIGN KEY (property_id) REFERENCES properties (id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =============================================================================
-- 3. CODE_VIOLATIONS  (Housing code enforcement cases — weekly dataset, 2013+)
-- =============================================================================
CREATE TABLE code_violations (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  property_id     INT UNSIGNED    NOT NULL,
  source_id       VARCHAR(100)    NULL
    COMMENT 'Original record ID from Montgomery API for idempotent upserts',

  case_number     VARCHAR(50)     NULL,
  violation_id    VARCHAR(50)     NULL,
  date_filed      DATE            NULL,
  date_closed     DATE            NULL,
  disposition     VARCHAR(100)    NULL
    COMMENT 'e.g. Resolved, Unresolved, Pending',
  code_reference  VARCHAR(100)    NULL
    COMMENT 'Specific code section violated',
  condition_text  TEXT            NULL
    COMMENT 'Description of the condition observed',
  action_taken    VARCHAR(255)    NULL
    COMMENT 'Enforcement action taken',

  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints & Indexes
  PRIMARY KEY (id),
  UNIQUE  KEY uk_source_id        (source_id),
  INDEX   idx_property_id        (property_id),
  INDEX   idx_case_number        (case_number),
  INDEX   idx_date_filed         (date_filed),

  CONSTRAINT fk_violation_property
    FOREIGN KEY (property_id) REFERENCES properties (id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =============================================================================
-- 4. CONSTRUCTION_PERMITS  (Residential & commercial building permits)
-- =============================================================================
CREATE TABLE construction_permits (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  property_id      INT UNSIGNED    NOT NULL,
  source_id        VARCHAR(100)    NULL
    COMMENT 'Original record ID from Montgomery API for idempotent upserts',

  permit_number    VARCHAR(50)     NULL,
  permit_type      VARCHAR(50)     NULL
    COMMENT 'e.g. Residential, Commercial',
  application_date DATE            NULL,
  issue_date       DATE            NULL,
  status           VARCHAR(50)     NOT NULL DEFAULT 'Pending'
    COMMENT 'e.g. Issued, Pending, Expired, Revoked',
  description      TEXT            NULL
    COMMENT 'Scope of permitted work',

  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints & Indexes
  PRIMARY KEY (id),
  UNIQUE  KEY uk_source_id          (source_id),
  INDEX   idx_property_id          (property_id),
  INDEX   idx_permit_number        (permit_number),
  INDEX   idx_status               (status),

  CONSTRAINT fk_permit_property
    FOREIGN KEY (property_id) REFERENCES properties (id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =============================================================================
-- 5. SURPLUS_PROPERTIES  (Government-owned assets available for redevelopment)
-- =============================================================================
CREATE TABLE surplus_properties (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  property_id      INT UNSIGNED    NOT NULL,
  source_id        VARCHAR(100)    NULL
    COMMENT 'Original record ID from Montgomery API for idempotent upserts',

  managing_agency  VARCHAR(150)    NULL
    COMMENT 'e.g. City of Montgomery, Montgomery County DHCA',
  listing_date     DATE            NULL,
  appraised_value  DECIMAL(12, 2)  NULL
    COMMENT 'Government-appraised value in USD',
  lot_size_sqft    DECIMAL(10, 2)  NULL,
  zoning           VARCHAR(50)     NULL
    COMMENT 'e.g. Residential, Commercial, Mixed-Use',
  status           VARCHAR(50)     NOT NULL DEFAULT 'Available'
    COMMENT 'e.g. Available, Under Review, Sold, Pending Sale',
  notes            TEXT            NULL
    COMMENT 'Additional remarks about the surplus asset',

  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

  -- Constraints & Indexes
  PRIMARY KEY (id),
  UNIQUE  KEY uk_source_id          (source_id),
  INDEX   idx_property_id          (property_id),
  INDEX   idx_status               (status),
  INDEX   idx_managing_agency      (managing_agency),

  CONSTRAINT fk_surplus_property
    FOREIGN KEY (property_id) REFERENCES properties (id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB;
