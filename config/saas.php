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

    // New institutions start a trial on this plan (its quotas apply during the trial).
    'trial_plan' => env('TRIAL_PLAN', 'standard'),

    'subscription' => [
        // Days after coverage ends during which everything still works (the owner is warned) before the account turns read-only.
        'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
    ],

    'tokens' => [
        // Access tokens are short; the rotating refresh token renews them without a new password entry.
        'tenant_ttl_minutes' => (int) env('TENANT_TOKEN_TTL_MINUTES', 60),
        'platform_ttl_minutes' => (int) env('PLATFORM_TOKEN_TTL_MINUTES', 30),
        'tenant_refresh_days' => (int) env('TENANT_REFRESH_DAYS', 30),
        'platform_refresh_days' => (int) env('PLATFORM_REFRESH_DAYS', 7),
        // Absolute life of a login: rotation never extends it, the user must sign in again after that.
        'refresh_max_days' => (int) env('REFRESH_MAX_DAYS', 90),
    ],

    // Support login-as: short, audited, read-only unless the platform admin explicitly asks to write.
    'impersonation' => [
        'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 30),
        'max_minutes' => (int) env('IMPERSONATION_MAX_MINUTES', 120),
        'require_two_factor' => (bool) env('IMPERSONATION_REQUIRE_2FA', true),
    ],

    // Per-account lockout after repeated wrong passwords / wrong second-factor codes.
    'lockout' => [
        'max_attempts' => (int) env('LOCKOUT_MAX_ATTEMPTS', 10),
        'minutes' => (int) env('LOCKOUT_MINUTES', 15),
    ],

    'two_factor' => [
        'challenge_ttl_seconds' => 300,
        'recovery_codes' => 10,
    ],

    // Enables lazy-loading / silent-attribute protections. Keep true in local dev once stable.
    'strict_models' => (bool) env('STRICT_MODELS', false),

];
