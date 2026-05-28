-- SQLite Database Schema for Traffic Violation Management System
-- This file contains all the table definitions and sample data

-- Enable foreign keys
PRAGMA foreign_keys = ON;

-- Users table for role-based accounts
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    full_name TEXT NOT NULL,
    contact_info TEXT DEFAULT '',
    role TEXT NOT NULL CHECK (role IN ('enforcer', 'supervisor', 'treasurer', 'motorist', 'pnp_officer', 'admin')),
    status TEXT DEFAULT 'active' CHECK (status IN ('pending', 'active', 'rejected')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Motorists table
CREATE TABLE IF NOT EXISTS motorists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    license_number TEXT UNIQUE NOT NULL,
    full_name TEXT NOT NULL,
    address TEXT,
    contact_number TEXT,
    plate TEXT UNIQUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Violations table
CREATE TABLE IF NOT EXISTS violations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  motorist_id INTEGER,
  enforcer_id INTEGER,
  penalty_id INTEGER,
  location TEXT,
  fine_amount REAL,
  top_number TEXT,
  status TEXT DEFAULT 'validated',
  violation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  confiscated_items TEXT DEFAULT 'None',
  violation_details TEXT,
  FOREIGN KEY (motorist_id) REFERENCES motorists(id),
  FOREIGN KEY (enforcer_id) REFERENCES users(id),
  FOREIGN KEY (penalty_id) REFERENCES penalties(id)
);

-- Motorist offense counts table
CREATE TABLE IF NOT EXISTS motorist_offense_counts (
  motorist_id INTEGER PRIMARY KEY,
  offense_count INTEGER DEFAULT 0,
  last_violation_at DATETIME,
  FOREIGN KEY (motorist_id) REFERENCES motorists(id)
);

-- Penalties table (violation types and their standard fines)
CREATE TABLE IF NOT EXISTS penalties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    violation_name TEXT NOT NULL,
    description TEXT,
    fine_amount REAL NOT NULL
);

-- Evidence table
CREATE TABLE IF NOT EXISTS evidence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    violation_id INTEGER NOT NULL,
    file_path TEXT NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (violation_id) REFERENCES violations(id) ON DELETE CASCADE
);

-- Payments table
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    violation_id INTEGER NOT NULL,
    treasurer_id INTEGER NOT NULL,
    receipt_number TEXT UNIQUE NOT NULL,
    payment_amount REAL NOT NULL,
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (violation_id) REFERENCES violations(id),
    FOREIGN KEY (treasurer_id) REFERENCES users(id)
);

-- Articles/Tutorials table
CREATE TABLE IF NOT EXISTS articles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  slug TEXT NOT NULL,
  content TEXT NOT NULL,
  published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  link_url TEXT NOT NULL DEFAULT '',
  attachment_path TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_articles_slug ON articles(slug);

-- Welcome page announcements
CREATE TABLE IF NOT EXISTS announcements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  content TEXT NOT NULL,
  image_path TEXT DEFAULT '',
  posted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  is_active INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Welcome page tutorial videos
CREATE TABLE IF NOT EXISTS tutorial_videos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  url TEXT,
  file_path TEXT DEFAULT '',
  description TEXT,
  sort_order INTEGER DEFAULT 0,
  is_active INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Audit Trail table for tracking changes
CREATE TABLE IF NOT EXISTS audit_trail (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    table_name TEXT NOT NULL,
    record_id INTEGER,
    details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Motorist feedback/complaints table
CREATE TABLE IF NOT EXISTS feedback_concerns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    motorist_user_id INTEGER NOT NULL,
    full_name TEXT NOT NULL,
    reference_number TEXT NOT NULL,
    violation_id INTEGER,
    contact_info TEXT NOT NULL,
    concern_type TEXT NOT NULL CHECK (concern_type IN ('Dispute', 'Inquiry', 'Complaint')),
    message TEXT NOT NULL,
    status TEXT DEFAULT 'Pending' CHECK (status IN ('Pending', 'Reviewed', 'Resolved')),
    supervisor_response TEXT,
    supervisor_id INTEGER,
    reviewed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (motorist_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (violation_id) REFERENCES violations(id) ON DELETE SET NULL,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert updated violations penalties (all 100 except Open muffler 1000)
INSERT OR IGNORE INTO penalties (violation_name, description, fine_amount) VALUES
('Unregistered MV', 'Vehicle not registered with LTO', 100.00),
('Unlicensed driver', 'Driver without valid DL', 100.00),
('Colorum/unfranchised operation', 'Unfranchised public utility vehicle', 100.00),
('Invalid or suspended/revoked/expired CR', 'Invalid or expired Certificate of Registration', 100.00),
('Invalid or suspended/revoked/expired DL', 'Invalid or expired Driver\\'s License', 100.00),
('Out of line', 'Public utility vehicle out of authorized route', 100.00),
('Student driver not accompanied by LD', 'Student permit driver without licensed companion', 100.00),
('Discorteous driver/conduct', 'Discourteous driving behavior', 100.00),
('CR/OR not carried', 'CR/OR not in driver\\'s possession', 100.00),
('CPC/PA/Permit not carried', "Conductor's permit or other docs not carried", 100.00),
('Unauthorized improvised plates', 'Fake or improvised license plates', 100.00),
('No required MV part/acc', 'Missing required motor vehicle parts/accessories', 100.00),
('No early warning device', 'No early warning devices for breakdowns', 100.00),
('No capacity marking', 'No passenger capacity markings', 100.00),
('No body (plate) number', 'Missing body or chassis number', 100.00),
('For hire MV', 'Private vehicle used for hire without franchise', 100.00),
('No tailgate/not for hire sign', 'Missing required signage on tailgate', 100.00),
('No front panel route', 'No route display on front panel', 100.00),
('Unauthorized wearing slippers/shirt', 'Driver wearing improper attire (slippers/sleeveless)', 100.00),
('Allowing passenger on top of MV', 'Passengers riding on roof/top of vehicle', 100.00),
('Reckless driving', 'Reckless imprudent driving endangering life/property', 100.00),
('Obstruction', 'Causing unnecessary obstruction to traffic', 100.00),
('No Helmet', 'Driving without wearing a proper safety helmet', 100.00),
('Open muffler', 'Vehicle with open or defective muffler', 1000.00);

-- Insert sample users (password: password123)
-- The password hash is: $2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m
INSERT OR IGNORE INTO users (username, password, full_name, role, status) VALUES
('enforcer1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Enforcer One', 'enforcer', 'active'),
('enforcer2', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Enforcer Two', 'enforcer', 'active'),
('supervisor1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Supervisor One', 'supervisor', 'active'),
('supervisor2', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Supervisor Two', 'supervisor', 'active'),
('treasurer1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Treasurer One', 'treasurer', 'active'),
('treasurer2', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Treasurer Two', 'treasurer', 'active'),
('motorist1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Motorist One', 'motorist', 'active'),
('motorist2', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'Motorist Two', 'motorist', 'active'),
('pnp1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'PNP Officer One', 'pnp_officer', 'active'),
('admin1', '$2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m', 'System Administrator', 'admin', 'active');

-- To initialize this database, run in SQLite:
-- sqlite3 traffic_management.sqlite < init_sqlite.sql

-- Or use PHP:
-- php -r "$pdo = new PDO('sqlite:traffic_management.sqlite'); $pdo->exec(file_get_contents('init_sqlite.sql'));"
