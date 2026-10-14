CREATE TABLE IF NOT EXISTS chat_threads (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, title VARCHAR(120) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id), INDEX chat_threads_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_messages (
 id CHAR(32) PRIMARY KEY, thread_id CHAR(32) NOT NULL, user_id CHAR(32) NULL,
 role ENUM('user','assistant') NOT NULL, content TEXT NOT NULL,
 request_id CHAR(32) NULL UNIQUE, reply_to CHAR(32) NULL UNIQUE,
 status ENUM('pending','complete','failed') NOT NULL DEFAULT 'complete',
 sources JSON NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
 FOREIGN KEY (user_id) REFERENCES users(id), INDEX chat_messages_thread (thread_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_usage (
 bucket_key VARCHAR(32) PRIMARY KEY, used INT UNSIGNED NOT NULL DEFAULT 0,
 leased_until BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
