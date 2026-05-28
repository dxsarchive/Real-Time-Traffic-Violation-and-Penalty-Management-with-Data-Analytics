-- MySQL Database Schema for Traffic Violation Management System
-- Use this file for MySQL/MariaDB databases (phpMyAdmin)
-- Run: Import this file in phpMyAdmin

-- Create database
CREATE DATABASE IF NOT EXISTS traffic_db;
USE traffic_db;

-- Users table for role-based accounts
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    contact_info VARCHAR(120) DEFAULT '',
    role ENUM('enforcer', 'supervisor', 'treasurer', 'motorist', 'pnp_officer', 'admin') NOT NULL,
    status ENUM('pending', 'active', 'rejected') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Penalties table (violation types and their standard fines)
-- MUST be created BEFORE violations table due to foreign key reference
CREATE TABLE IF NOT EXISTS penalties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    violation_name VARCHAR(100) NOT NULL,
    description TEXT,
    fine_amount DECIMAL(10, 2) NOT NULL
);

-- Motorists table
CREATE TABLE IF NOT EXISTS motorists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    license_number VARCHAR(20) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    address TEXT,
    date_of_birth DATE NULL,
    contact_number VARCHAR(15),
    plate VARCHAR(20) UNIQUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Violations table
CREATE TABLE IF NOT EXISTS violations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  motorist_id INT,
  enforcer_id INT,
  penalty_id INT,
  location VARCHAR(255),
  fine_amount DECIMAL(10,2),
  top_number VARCHAR(100),
  status ENUM('pending', 'validated', 'rejected', 'paid') DEFAULT 'validated',
  violation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  confiscated_items VARCHAR(255) DEFAULT 'None',
  violation_details TEXT,
  FOREIGN KEY (motorist_id) REFERENCES motorists(id),
  FOREIGN KEY (enforcer_id) REFERENCES users(id),
  FOREIGN KEY (penalty_id) REFERENCES penalties(id)
);

-- Motorist offense counts table
CREATE TABLE IF NOT EXISTS motorist_offense_counts (
  motorist_id INT PRIMARY KEY,
  offense_count INT DEFAULT 0,
  last_violation_at DATETIME,
  FOREIGN KEY (motorist_id) REFERENCES motorists(id)
);

-- Evidence table
CREATE TABLE IF NOT EXISTS evidence (
    id INT AUTO_INCREMENT PRIMARY KEY,
    violation_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (violation_id) REFERENCES violations(id) ON DELETE CASCADE
);

-- Payments table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    violation_id INT NOT NULL,
    treasurer_id INT NOT NULL,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    payment_amount DECIMAL(10, 2) NOT NULL,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (violation_id) REFERENCES violations(id),
    FOREIGN KEY (treasurer_id) REFERENCES users(id)
);

-- Articles/Tutorials table
CREATE TABLE IF NOT EXISTS articles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  link_url VARCHAR(1000) NOT NULL DEFAULT '',
  attachment_path VARCHAR(500) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_articles_slug (slug)
);

-- Welcome page announcements
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    image_path VARCHAR(500) DEFAULT '',
    posted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Welcome page tutorial videos
CREATE TABLE IF NOT EXISTS tutorial_videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(500) NULL,
    file_path VARCHAR(500) DEFAULT '',
    description TEXT,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Audit Trail table for tracking changes
CREATE TABLE IF NOT EXISTS audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action TEXT NOT NULL,
    table_name TEXT NOT NULL,
    record_id INT,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Motorist feedback/complaints table
CREATE TABLE IF NOT EXISTS feedback_concerns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    motorist_user_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    reference_number VARCHAR(100) NOT NULL,
    violation_id INT NULL,
    contact_info VARCHAR(150) NOT NULL,
    concern_type ENUM('Dispute', 'Inquiry', 'Complaint') NOT NULL,
    message TEXT NOT NULL,
    status ENUM('Pending', 'Reviewed', 'Resolved') DEFAULT 'Pending',
    supervisor_response TEXT NULL,
    supervisor_id INT NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_feedback_motorist (motorist_user_id),
    INDEX idx_feedback_status (status),
    INDEX idx_feedback_reference (reference_number),
    FOREIGN KEY (motorist_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (violation_id) REFERENCES violations(id) ON DELETE SET NULL,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert updated violations penalties (all 100 except Open muffler 1000)
INSERT INTO penalties (violation_name, description, fine_amount) VALUES
('Unregistered MV', 'Vehicle not registered with LTO', 100.00),
('Unlicensed driver', 'Driver without valid DL', 100.00),
('Colorum/unfranchised operation', 'Unfranchised public utility vehicle', 100.00),
('Invalid or suspended/revoked/expired CR', 'Invalid or expired Certificate of Registration', 100.00),
('Invalid or suspended/revoked/expired DL', 'Invalid or expired Driver''s License', 100.00),
('Out of line', 'Public utility vehicle out of authorized route', 100.00),
('Student driver not accompanied by LD', 'Student permit driver without licensed companion', 100.00),
('Discorteous driver/conduct', 'Discourteous driving behavior', 100.00),
('CR/OR not carried', 'CR/OR not in driver''s possession', 100.00),
('CPC/PA/Permit not carried', 'Conductor''s permit or other docs not carried', 100.00),
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
-- Hash: $2y$10$jyFsh7rurQUU7jDIK9hQ0.6s48T2E4KKgohiITEY/upHZZOhv/j.m
INSERT INTO users (username, password, full_name, role, status) VALUES
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
