<?php
return [
    'app_url' => 'http://localhost:5173',
    'base_path' => '',
    'secure_cookies' => false, // Set true for HTTPS production.
    'db_dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=jv_story;charset=utf8mb4',
    'db_user' => 'root',
    'db_password' => '',
    'google_client_id' => '',
    'google_client_secret' => '',
    'google_redirect_uri' => 'http://localhost:8080/api/auth/callback',
    'allowed_emails' => ['your-personal@gmail.com', 'partner-personal@gmail.com'],
];
