<?php

declare(strict_types=1);

/*
| The API uses bearer tokens (no cookies), so credentials are never allowed cross-origin.
| CORS_ALLOWED_ORIGINS is a comma separated list of the front-end origins, e.g.
|   CORS_ALLOWED_ORIGINS=https://app.myapp.dz,http://localhost:5173
| Left empty, browsers on other origins are refused (server-to-server and Postman calls are not affected by CORS).
| A wildcard is rejected by "php artisan app:preflight".
*/
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
    'allowed_origins_patterns' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))))),
    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Tenant', 'X-Requested-With', 'Idempotency-Key'],
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],
    'max_age' => 600,
    'supports_credentials' => false,
];
