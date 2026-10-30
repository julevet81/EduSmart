<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The half-finished login between "password accepted" and "second factor accepted".
 * One opaque id, short life, a handful of attempts, never reusable, bound to one realm (tenant users / platform).
 */
final class Challenges
{
    private const MAX_ATTEMPTS = 5;

    /** @param  array<string, mixed>  $payload */
    public function create(string $type, int $subjectId, ?int $tenantId, array $payload = []): string
    {
        $id = Str::random(64);

        Cache::put($this->key($id), [
            'type' => $type, 'subject_id' => $subjectId, 'tenant_id' => $tenantId, 'attempts' => 0, 'payload' => $payload,
        ], (int) config('saas.two_factor.challenge_ttl_seconds'));

        return $id;
    }

    /** @return array{type: string, subject_id: int, tenant_id: int|null, attempts: int, payload: array<string, mixed>}|null */
    public function find(string $id, string $type, ?int $tenantId): ?array
    {
        $data = Cache::get($this->key($id));

        return is_array($data) && $data['type'] === $type && $data['tenant_id'] === $tenantId ? $data : null;
    }

    public function fail(string $id): void
    {
        $data = Cache::get($this->key($id));

        if (! is_array($data)) {
            return;
        }

        $data['attempts']++;

        $data['attempts'] >= self::MAX_ATTEMPTS
            ? $this->forget($id)
            : Cache::put($this->key($id), $data, (int) config('saas.two_factor.challenge_ttl_seconds'));
    }

    public function forget(string $id): void
    {
        Cache::forget($this->key($id));
    }

    private function key(string $id): string
    {
        return 'auth_challenge:'.hash('sha256', $id);
    }
}
