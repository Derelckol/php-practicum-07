CREATE DATABASE IF NOT EXISTS php_practicum_07
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE php_practicum_07;

DROP TABLE IF EXISTS events;

CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    event_date DATETIME NOT NULL,
    venue VARCHAR(255) NOT NULL,
    seats_left INT UNSIGNED NOT NULL DEFAULT 0
);

INSERT INTO events (title, event_date, venue, seats_left)
VALUES
(
    'Imagine Dragons Tribute Show',
    '2026-10-10 19:00:00',
    'Stereo Plaza',
    25
),
(
    'Фестиваль електронної музики',
    '2026-10-15 18:30:00',
    'VDNG',
    0
),
(
    'Stand Up Evening',
    '2026-10-20 20:00:00',
    'Жовтневий палац',
    40
);