-- Database untuk Gym Management System
CREATE DATABASE IF NOT EXISTS gym_management1;
USE gym_management1;

-- Tabel untuk users (admin dan member)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabel untuk membership
CREATE TABLE IF NOT EXISTS memberships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    package_type ENUM('1_month', '3_months', '6_months', '12_months') NOT NULL,
    status ENUM('active', 'expired', 'pending') DEFAULT 'active',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Tabel untuk absensi
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    check_in DATETIME NOT NULL,
    notes TEXT,
    created_by INT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Tabel untuk history perpanjangan membership
CREATE TABLE IF NOT EXISTS membership_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    membership_id INT NOT NULL,
    extended_by INT,
    extension_date DATE NOT NULL,
    previous_end_date DATE NOT NULL,
    new_end_date DATE NOT NULL,
    package_type ENUM('1_month', '3_months', '6_months', '12_months') NOT NULL,
    amount DECIMAL(10,2),
    payment_method VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
    FOREIGN KEY (extended_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default admin
INSERT INTO users (username, password, full_name, email, phone, role) 
VALUES ('admin', MD5('admin123'), 'Administrator', 'admin@gym.com', '08123456789', 'admin');

-- Insert sample members
INSERT INTO users (username, password, full_name, email, phone, role) 
VALUES 
('user1', MD5('user123'), 'John Doe', 'john@email.com', '08111111111', 'user'),
('user2', MD5('user123'), 'Jane Smith', 'jane@email.com', '08122222222', 'user'),
('user3', MD5('user123'), 'Mike Johnson', 'mike@email.com', '08133333333', 'user');

-- Insert sample memberships
INSERT INTO memberships (user_id, start_date, end_date, package_type, status, price, payment_method)
VALUES 
(2, '2025-11-01', '2025-12-01', '1_month', 'active', 250000, 'cash'),
(3, '2025-10-01', '2026-01-01', '3_months', 'active', 650000, 'transfer'),
(4, '2025-09-01', '2025-11-01', '1_month', 'expired', 250000, 'cash');

-- Insert sample attendance
INSERT INTO attendance (user_id, check_in, created_by)
VALUES 
(2, '2025-11-25 08:00:00', 1),
(3, '2025-11-25 09:30:00', 1),
(2, '2025-11-24 07:45:00', 1);

-- Tabel untuk request perpanjangan membership (menunggu verifikasi admin)
CREATE TABLE IF NOT EXISTS extension_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    membership_id INT NOT NULL,
    package_type ENUM('1_month', '3_months', '6_months', '12_months') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    payment_proof VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    admin_notes TEXT,
    verified_by INT,
    verified_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Tabel untuk catatan berat badan harian
CREATE TABLE IF NOT EXISTS weight_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    weight DECIMAL(5,2) NOT NULL,
    log_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_date (user_id, log_date)
);

-- Tabel untuk transaksi keuangan (data permanen, tidak terpengaruh jika member dihapus)
CREATE TABLE IF NOT EXISTS financial_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    membership_id INT NULL,
    member_name VARCHAR(100) NOT NULL,
    member_username VARCHAR(50) NOT NULL,
    transaction_type ENUM('new_membership', 'extension') NOT NULL,
    package_type ENUM('1_month', '3_months', '6_months', '12_months') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'transfer', 'qris') NOT NULL,
    transaction_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_transaction_type (transaction_type)
);