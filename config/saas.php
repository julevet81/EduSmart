<?php

declare(strict_types=1);

return [

    'tenancy' => [
        // Header used to identify the tenant when no subdomain is used (API clients, mobile apps).
        'header' => 'X-Tenant',

        // If set (e.g. "myapp.dz"), "school.myapp.dz" resolves to the tenant "school".
        'base_domain' => env('TENANCY_BASE_DOMAIN'),

        // Seconds a tenant record stays cached after lookup by slug.
        'cache_ttl' => 300,

        'reserved_slugs' => ['www', 'api', 'admin', 'app', 'platform', 'static', 'mail', 'support', 'status'],
    ],

    'trial_days' => (int) env('TRIAL_DAYS', 30),

    'tokens' => [
        'tenant_ttl_minutes' => (int) env('TENANT_TOKEN_TTL_MINUTES', 720),
        'platform_ttl_minutes' => (int) env('PLATFORM_TOKEN_TTL_MINUTES', 240),
    ],

    // Enables lazy-loading / silent-attribute protections. Keep true in local dev once stable.
    'strict_models' => (bool) env('STRICT_MODELS', false),

];
