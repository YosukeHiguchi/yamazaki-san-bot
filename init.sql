CREATE TABLE user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255),
    token VARCHAR(255),
    waiting_flg INT DEFAULT 0,
    start_time TIMESTAMP
);

CREATE TABLE log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(255),
    user1_id VARCHAR(255),
    user2_id VARCHAR(255),
    user1_name VARCHAR(255),
    user2_name VARCHAR(255),
    content LONGTEXT,
    start_time TIMESTAMP,
    end_time TIMESTAMP
)
