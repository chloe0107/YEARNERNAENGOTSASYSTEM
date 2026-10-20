CREATE DATABASE IF NOT EXISTS library_management_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_management_system;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  student_id VARCHAR(60) NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff','user') NOT NULL DEFAULT 'user',
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB;

CREATE TABLE email_verification_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_verify_expiry (expires_at, used_at)
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_password_reset_expiry (expires_at, used_at)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(500) NULL,
  archived_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE authors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  author_name VARCHAR(160) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE books (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  isbn VARCHAR(20) NULL UNIQUE,
  title VARCHAR(220) NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  publisher VARCHAR(160) NULL,
  publication_year SMALLINT UNSIGNED NULL,
  description TEXT NULL,
  cover_image VARCHAR(500) NULL,
  shelf_location VARCHAR(80) NULL,
  total_copies SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  archived_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_book_category FOREIGN KEY (category_id) REFERENCES categories(id),
  INDEX idx_books_title (title),
  INDEX idx_books_category_archived (category_id, archived_at)
) ENGINE=InnoDB;

CREATE TABLE book_authors (
  book_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (book_id, author_id),
  CONSTRAINT fk_book_authors_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_authors_author FOREIGN KEY (author_id) REFERENCES authors(id)
) ENGINE=InnoDB;

CREATE TABLE borrowing_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('pending','approved','rejected','released','cancelled') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  rejection_reason VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_request_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_request_book FOREIGN KEY (book_id) REFERENCES books(id),
  CONSTRAINT fk_request_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
  INDEX idx_requests_status_time (status, requested_at),
  INDEX idx_requests_user_status (user_id, status),
  INDEX idx_requests_book_status (book_id, status)
) ENGINE=InnoDB;

CREATE TABLE borrowing_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  released_by BIGINT UNSIGNED NOT NULL,
  borrowed_at DATETIME NOT NULL,
  due_date DATE NOT NULL,
  returned_at DATETIME NULL,
  received_by BIGINT UNSIGNED NULL,
  status ENUM('borrowed','returned','overdue','lost') NOT NULL DEFAULT 'borrowed',
  notes VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_transaction_request FOREIGN KEY (request_id) REFERENCES borrowing_requests(id),
  CONSTRAINT fk_transaction_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_transaction_book FOREIGN KEY (book_id) REFERENCES books(id),
  CONSTRAINT fk_transaction_releaser FOREIGN KEY (released_by) REFERENCES users(id),
  CONSTRAINT fk_transaction_receiver FOREIGN KEY (received_by) REFERENCES users(id),
  INDEX idx_transactions_status_due (status, due_date),
  INDEX idx_transactions_user (user_id, borrowed_at),
  INDEX idx_transactions_book_status (book_id, status)
) ENGINE=InnoDB;

CREATE TABLE reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  queue_position BIGINT UNSIGNED NULL,
  reserved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ready_at DATETIME NULL,
  expires_at DATETIME NULL,
  fulfilled_at DATETIME NULL,
  status ENUM('waiting','ready_for_pickup','fulfilled','expired','cancelled') NOT NULL DEFAULT 'waiting',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservation_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_reservation_book FOREIGN KEY (book_id) REFERENCES books(id),
  INDEX idx_reservations_queue (book_id, status, queue_position, id),
  INDEX idx_reservations_user (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_activity_created (created_at),
  INDEX idx_activity_entity (entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE system_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE auth_rate_limits (
  bucket_key CHAR(64) PRIMARY KEY,
  window_started DATETIME NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rate_limit_window (window_started)
) ENGINE=InnoDB;

INSERT INTO categories (category_name, description) VALUES
('Computer Science', 'Programming, computing, and information technology'),
('Literature', 'Fiction, poetry, and literary criticism'),
('Science', 'Natural sciences and mathematics'),
('History', 'World, regional, and cultural history');

INSERT INTO authors (author_name) VALUES
('Robert C. Martin'), ('Jane Austen'), ('Stephen Hawking'), ('Yuval Noah Harari');

INSERT INTO books (isbn, title, category_id, publisher, publication_year, description, shelf_location, total_copies) VALUES
('9780132350884', 'Clean Code', 1, 'Prentice Hall', 2008, 'A handbook of agile software craftsmanship.', 'CS-A1', 3),
('9780141439518', 'Pride and Prejudice', 2, 'Penguin Classics', 1813, 'A classic novel about manners and marriage.', 'LIT-B2', 2),
('9780553380163', 'A Brief History of Time', 3, 'Bantam', 1988, 'A guide to cosmology and the nature of time.', 'SCI-C1', 2),
('9780062316097', 'Sapiens', 4, 'Harper', 2011, 'A history of humankind.', 'HIS-D3', 2);

INSERT INTO book_authors (book_id, author_id) VALUES (1,1),(2,2),(3,3),(4,4);
INSERT INTO system_settings (setting_key, setting_value) VALUES
('loan_period_days', '7'),
('borrowing_limit', '3'),
('reservation_expiry_days', '2');

-- Relationships: books belong to one category and connect to authors through book_authors.
-- Each borrowing request belongs to one user and book; its optional transaction is unique per request.
-- Reservations persist queue order by auto-increment ID, mirrored in queue_position for FIFO traversal.
-- Activity logs retain the actor and affected entity; settings are key/value configuration, not derived inventory.
