CREATE TABLE user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255),
    token VARCHAR(255),
    waiting_flg INT DEFAULT 0,
    start_time TIMESTAMP
);
