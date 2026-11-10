<?php

return [
    /**
     * 初期 admin ユーザーの credentials（AdminUserSeeder が updateOrCreate に使う）。
     * 本番では .env で必ず上書きすること。
     */
    'email' => env('ADMIN_EMAIL', 'admin@example.com'),
    'password' => env('ADMIN_PASSWORD', 'password'),
    'name' => env('ADMIN_NAME', 'Admin'),
];
