
CREATE DATABASE IF NOT EXISTS college_events
CHARACTER SET utf8mb4
COLLATE utf8mb4_general_ci;

USE college_events;
/* ==========================================================
   Department & User Management System
   MySQL 5.7+ Compatible
   ========================================================== */

SET SQL_MODE = "STRICT_ALL_TABLES";
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE departments (
    id CHAR(36) PRIMARY KEY,

    name VARCHAR(100) NOT NULL UNIQUE,
    header_image VARCHAR(255) DEFAULT NULL,
    header_public_id VARCHAR(255) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE default_header (
    id CHAR(36) PRIMARY KEY,
    image VARCHAR(255) NOT NULL,
    public_id VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE users (
    id CHAR(36) PRIMARY KEY,

    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    recovery_email VARCHAR(100) NOT NULL UNIQUE,

    password VARCHAR(255) NOT NULL,
    contact_number VARCHAR(15) NOT NULL UNIQUE,

    role ENUM('principal','hod','coordinator') NOT NULL,

    department_id CHAR(36) DEFAULT NULL,

    profile_image VARCHAR(255) DEFAULT NULL,
    profile_public_id VARCHAR(255),
    sign_image VARCHAR(255) DEFAULT NULL,
    sign_public_id VARCHAR(255),

    reset_otp VARCHAR(255) DEFAULT NULL,
    reset_otp_expiry DATETIME DEFAULT NULL,
    reset_otp_attempts INT DEFAULT 0,
    reset_otp_last_sent DATETIME DEFAULT NULL,
    reset_otp_locked_until DATETIME DEFAULT NULL,

    reset_token VARCHAR(255) DEFAULT NULL,
    reset_token_expiry DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_users_role (role),

    CONSTRAINT fk_users_department
        FOREIGN KEY (department_id)
        REFERENCES departments(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE events (
    id CHAR(36) PRIMARY KEY,

    event_name VARCHAR(255) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    image_path VARCHAR(500) NOT NULL,

    created_by CHAR(36) NOT NULL,
    department_id CHAR(36) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_events_user
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_events_department
        FOREIGN KEY (department_id)
        REFERENCES departments(id)
        ON DELETE CASCADE,

    INDEX idx_start_date (start_date),
    INDEX idx_department (department_id),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE events 
ADD COLUMN image_public_id VARCHAR(255) NULL AFTER image_path;

CREATE OR REPLACE VIEW user_department_view AS
SELECT 
    u.id,
    u.username,
    u.email,
    u.role,
    COALESCE(d.name, 'No Department') AS department_name,
    u.created_at,
    u.updated_at
FROM users u
LEFT JOIN departments d
ON u.department_id = d.id;

CREATE OR REPLACE VIEW department_stats_view AS
SELECT 
    d.id,
    d.name AS department_name,
    COUNT(u.id) AS total_users,
    SUM(u.role='hod') AS hod_count,
    SUM(u.role='coordinator') AS coordinator_count,
    d.created_at,
    d.updated_at
FROM departments d
LEFT JOIN users u
ON d.id = u.department_id
GROUP BY d.id, d.name, d.created_at, d.updated_at;

DELIMITER $$

CREATE TRIGGER trg_department_before_insert
BEFORE INSERT ON departments
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM departments
        WHERE LOWER(name) = LOWER(NEW.name)
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Department already exists';
    END IF;
END$$


DELIMITER $$

CREATE TRIGGER trg_department_before_update
BEFORE UPDATE ON departments
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM departments
        WHERE LOWER(name) = LOWER(NEW.name)
          AND id <> NEW.id
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Department already exists';
    END IF;
END$$

DELIMITER $$

CREATE TRIGGER trg_users_before_insert
BEFORE INSERT ON users
FOR EACH ROW
BEGIN

    IF NEW.recovery_email IS NOT NULL
       AND NEW.recovery_email NOT LIKE '%_@__%.__%' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid recovery email format';
    END IF;

    IF NEW.recovery_email IS NOT NULL
       AND NEW.recovery_email = NEW.email THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Recovery email cannot be same as email';
    END IF;

    IF NEW.contact_number IS NOT NULL
       AND NEW.contact_number NOT REGEXP '^(\\+91)?[6-9][0-9]{9}$' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid contact number';
    END IF;

END$$

DELIMITER $$

CREATE TRIGGER trg_users_before_update
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN

    IF NEW.recovery_email IS NOT NULL
       AND NEW.recovery_email NOT LIKE '%_@__%.__%' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid recovery email format';
    END IF;

    IF NEW.recovery_email IS NOT NULL
       AND NEW.recovery_email = NEW.email THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Recovery email cannot be same as email';
    END IF;

    IF NEW.contact_number IS NOT NULL
       AND NEW.contact_number NOT REGEXP '^(\\+91)?[6-9][0-9]{9}$' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid contact number';
    END IF;

END$$

DELIMITER ;
















/* ==========================================================
   Checklists Table
   ========================================================== */
CREATE TABLE IF NOT EXISTS checklists (
    id CHAR(36) PRIMARY KEY,

    programme_name VARCHAR(255) NOT NULL,
    programme_date DATE,
    multi_day TINYINT(1),
    programme_start_date DATE,
    programme_end_date DATE,

    -- Store current user's department ID
    userdept_id CHAR(36)  DEFAULT NULL,

    coordinator VARCHAR(255) NOT NULL,
    department JSON,
    invitation JSON,

    communication TINYINT(1),
    communication_details TEXT,

    transportation TINYINT(1),
    transportation_details TEXT,

    invitation_letter TINYINT(1),
    invitation_letter_details TEXT,

    welcome_banner TINYINT(1),
    welcome_banner_details TEXT,

    gifts TINYINT(1),
    gifts_details TEXT,

    bouquets TINYINT(1),
    bouquets_details TEXT,

    shawls TINYINT(1),
    shawls_details TEXT,

    cleanliness TINYINT(1),
    cleanliness_details TEXT,

    water_bottles TINYINT(1),
    water_bottles_details TEXT,

    snacks TINYINT(1),
    snacks_details TEXT,

    tea_coffee TINYINT(1),
    tea_coffee_details TEXT,

    itinerary TINYINT(1),
    itinerary_details TEXT,

    white_board_welcome TINYINT(1),
    white_board_welcome_details TEXT,

    cleanliness_seminar_hall TINYINT(1),
    cleanliness_seminar_hall_details TEXT,

    mike_speaker TINYINT(1),
    mike_speaker_details TEXT,

    decoration TINYINT(1),
    decoration_details TEXT,

    projector TINYINT(1),
    projector_details TEXT,

    genset TINYINT(1),
    genset_details TEXT,

    candle_oil_garland_flowers TINYINT(1),
    candle_oil_garland_flowers_details TEXT,

    saraswati_pooja TINYINT(1),
    saraswati_pooja_details TEXT,

    saraswati_geet TINYINT(1),
    saraswati_geet_details TEXT,

    name_plates TINYINT(1),
    name_plates_details TEXT,

    note_pad TINYINT(1),
    note_pad_details TEXT,

    pen TINYINT(1),
    pen_details TEXT,

    water_bottle_on_dias TINYINT(1),
    water_bottle_on_dias_details TEXT,

    itinerary_dias TINYINT(1),
    itinerary_dias_details TEXT,

    photo_frame TINYINT(1),
    photo_frame_details TEXT,

    video_shooting TINYINT(1),
    video_shooting_details TEXT,

    photo_shooting TINYINT(1),
    photo_shooting_details TEXT,

    social_media TINYINT(1),
    social_media_details TEXT,

    impression_book TINYINT(1),
    impression_book_details TEXT,

    post_communication TINYINT(1),
    post_communication_details TEXT,

    college_database TINYINT(1),
    college_database_details TEXT,

    thanks_letter TINYINT(1),
    thanks_letter_details TEXT,

    others TINYINT(1),
    others_details TEXT,
    application_letter VARCHAR(255),

    created_by CHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT fk_checklists_userdept
        FOREIGN KEY (userdept_id)
        REFERENCES departments(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_checklists_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE checklists
ADD COLUMN application_letter_public_id VARCHAR(255) DEFAULT NULL
AFTER application_letter;

CREATE INDEX idx_checklists_programme_date ON checklists(programme_date);
CREATE INDEX idx_checklists_programme_start_date ON checklists(programme_start_date);
CREATE INDEX idx_events_date_range ON events(start_date, end_date);
/* ==========================================================
   Checklist Guests Table
   ========================================================== */
CREATE TABLE IF NOT EXISTS checklist_guests (
    id CHAR(36) PRIMARY KEY,

    checklist_id CHAR(36) NOT NULL,

    guest_name VARCHAR(255),
    company_name VARCHAR(255),
    designation VARCHAR(255),
    bio_image VARCHAR(255),

    contact_no VARCHAR(50),
    guest_email VARCHAR(255) NULL,

    UNIQUE (guest_email),

    FOREIGN KEY (checklist_id)
        REFERENCES checklists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE checklist_guests
ADD COLUMN bio_public_id VARCHAR(255) DEFAULT NULL
AFTER bio_image;


/* ==========================================================
   Program Incharge Table
   ========================================================== */
CREATE TABLE IF NOT EXISTS program_incharge (
    id CHAR(36) PRIMARY KEY,

    checklist_id CHAR(36) NOT NULL,

    incharge_name VARCHAR(255),
    task TEXT,

    FOREIGN KEY (checklist_id)
        REFERENCES checklists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_checklists_userdept ON checklists(userdept_id);
CREATE INDEX idx_checklists_created_by ON checklists(created_by);
CREATE INDEX idx_checklist_guests_checklist ON checklist_guests(checklist_id);

CREATE TABLE IF NOT EXISTS invite (
    id CHAR(36) PRIMARY KEY,

    -- Links to checklist
    checklist_id CHAR(36) NOT NULL,

    -- Links to checklist_guests
    guest_id CHAR(36) NOT NULL,

    invite_date DATE NOT NULL,

    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    respected VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Foreign Keys
    CONSTRAINT fk_invite_checklist
        FOREIGN KEY (checklist_id)
        REFERENCES checklists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_invite_guest
        FOREIGN KEY (guest_id)
        REFERENCES checklist_guests(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_invite_checklist ON invite(checklist_id);
CREATE INDEX idx_invite_guest ON invite(guest_id);

DROP TABLE IF EXISTS notice;

CREATE TABLE notice (
    id CHAR(36) PRIMARY KEY,

    checklist_id CHAR(36) NOT NULL,

    notice_date DATE NOT NULL,
    dear TEXT NOT NULL,
    event_highlights TEXT NOT NULL,
    event_time TIME NOT NULL,
    event_venue VARCHAR(255) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notice_checklists
        FOREIGN KEY (checklist_id)
        REFERENCES checklists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_notice_checklist ON notice(checklist_id);

CREATE TABLE IF NOT EXISTS appreciation (
    id CHAR(36) PRIMARY KEY,

    checklist_id CHAR(36) NOT NULL,
    guest_id CHAR(36) NOT NULL,

    appreciation_date DATE NOT NULL,

    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    respected VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_appreciation_checklist
        FOREIGN KEY (checklist_id)
        REFERENCES checklists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_appreciation_guest
        FOREIGN KEY (guest_id)
        REFERENCES checklist_guests(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_appreciation_checklist ON appreciation(checklist_id);
CREATE INDEX idx_appreciation_guest ON appreciation(guest_id);

CREATE TABLE IF NOT EXISTS event_report (
    id CHAR(36) PRIMARY KEY,
    
    checklist_id CHAR(36) NOT NULL,

    description TEXT NOT NULL,
    activities TEXT NOT NULL,
    significance TEXT NOT NULL,
    conclusion TEXT NOT NULL,
    faculties_participation TEXT NOT NULL,

    photos JSON DEFAULT NULL,
    photos_public_ids TEXT DEFAULT NULL,
    captions JSON DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_event_report_checklist
        FOREIGN KEY (checklist_id)
        REFERENCES checklists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_event_report_checklist 
ON event_report(checklist_id);

-- ==========================================================
-- Security Infrastructure Tables (XAMPP Compatible)
-- MariaDB 10.4+ (No Triggers, No Stored Functions)
-- ==========================================================

-- ==========================================================
-- Audit Logs Table
-- ==========================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    
    -- User Information
    user_id CHAR(36) DEFAULT NULL,
    username VARCHAR(50) NOT NULL,
    user_role ENUM('principal','hod','coordinator') NOT NULL,
    department_id CHAR(36) DEFAULT NULL,
    
    -- Operation Details
    operation VARCHAR(50) NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    resource_id VARCHAR(255) DEFAULT NULL,
    
    -- Request Details
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    url TEXT NOT NULL,
    request_method VARCHAR(10) NOT NULL,
    
    -- Timestamp
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_audit_user_id (user_id),
    INDEX idx_audit_username (username),
    INDEX idx_audit_operation (operation),
    INDEX idx_audit_resource_type (resource_type),
    INDEX idx_audit_resource_id (resource_id),
    INDEX idx_audit_ip_address (ip_address),
    INDEX idx_audit_created_at (created_at),
    
    -- Foreign Keys
    CONSTRAINT fk_audit_user_id
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
        
    CONSTRAINT fk_audit_department_id
        FOREIGN KEY (department_id)
        REFERENCES departments(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ==========================================================
-- Rate Limits Table
-- ==========================================================

CREATE TABLE IF NOT EXISTS rate_limits (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    
    rate_key VARCHAR(255) NOT NULL,
    
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    url TEXT NOT NULL,
    request_method VARCHAR(10) NOT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_rate_limits_key (rate_key),
    INDEX idx_rate_limits_ip (ip_address),
    INDEX idx_rate_limits_created_at (created_at),
    INDEX idx_rate_limits_key_created (rate_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ==========================================================
-- Security Violations Table
-- ==========================================================

CREATE TABLE IF NOT EXISTS security_violations (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    
    violation_type VARCHAR(100) NOT NULL,
    violation_message TEXT,
    
    user_id CHAR(36) DEFAULT NULL,
    username VARCHAR(50) DEFAULT NULL,
    user_role ENUM('principal','hod','coordinator') DEFAULT NULL,
    department_id CHAR(36) DEFAULT NULL,
    
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    url TEXT NOT NULL,
    request_method VARCHAR(10) NOT NULL,
    
    resource_type VARCHAR(50) DEFAULT NULL,
    resource_id VARCHAR(255) DEFAULT NULL,
    additional_data JSON DEFAULT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_security_violations_type (violation_type),
    INDEX idx_security_violations_user_id (user_id),
    INDEX idx_security_violations_ip (ip_address),
    INDEX idx_security_violations_created_at (created_at),
    
    CONSTRAINT fk_security_violations_user_id
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
        
    CONSTRAINT fk_security_violations_department_id
        FOREIGN KEY (department_id)
        REFERENCES departments(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ==========================================================
-- Session Security Table (Fixed logout_time issue)
-- ==========================================================

CREATE TABLE IF NOT EXISTS session_security (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    
    session_id VARCHAR(255) NOT NULL,
    user_id CHAR(36) NOT NULL,
    username VARCHAR(50) NOT NULL,
    
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    csrf_token VARCHAR(255) NOT NULL,
    
    is_active BOOLEAN DEFAULT TRUE,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    logout_time DATETIME DEFAULT NULL,
    
    is_compromised BOOLEAN DEFAULT FALSE,
    compromise_reason VARCHAR(255) DEFAULT NULL,
    
    INDEX idx_session_security_session_id (session_id),
    INDEX idx_session_security_user_id (user_id),
    INDEX idx_session_security_ip (ip_address),
    INDEX idx_session_security_active (is_active),
    INDEX idx_session_security_last_activity (last_activity),
    
    CONSTRAINT fk_session_security_user_id
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ==========================================================
-- Views (Fully Compatible)
-- ==========================================================

CREATE OR REPLACE VIEW recent_security_violations AS
SELECT 
    sv.id,
    sv.violation_type,
    sv.violation_message,
    sv.username,
    sv.user_role,
    sv.ip_address,
    sv.url,
    sv.created_at,
    COUNT(*) OVER (PARTITION BY sv.ip_address) as violations_from_ip
FROM security_violations sv
WHERE sv.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);


CREATE OR REPLACE VIEW audit_log_summary AS
SELECT 
    DATE(created_at) as date,
    user_role,
    operation,
    resource_type,
    COUNT(*) as operation_count,
    COUNT(DISTINCT ip_address) as unique_ips
FROM audit_logs
GROUP BY DATE(created_at), user_role, operation, resource_type;


CREATE OR REPLACE VIEW rate_limit_violations AS
SELECT 
    rl.rate_key,
    rl.ip_address,
    rl.url,
    rl.request_method,
    COUNT(*) as request_count,
    MIN(rl.created_at) as first_request,
    MAX(rl.created_at) as last_request
FROM rate_limits rl
WHERE rl.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY rl.rate_key, rl.ip_address, rl.url, rl.request_method
HAVING request_count > 60;
