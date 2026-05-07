<?php

return [
    'secret'      => env('JWT_SECRET', 'sd89f7s98d7f98sd7f98s7df98s7d9f8s7d9f8'), // Generate a better secret in .env
    // Token validity period (seconds)
    'ttl'         => 604800, // 7 days
    // Refresh validity period (seconds)
    'refresh_ttl' => 1209600, // 14 days
    // Hashing algorithm
    'algo'        => 'HS256',
    // Blacklist grace period
    'blacklist_grace_period' => 60,
];
