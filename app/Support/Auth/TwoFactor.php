<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enrolment, verification and recovery for any account using HasTwoFactor.
 * Secrets and recovery codes are stored encrypted; recovery codes additionally as keyed hashes, each usable once.
 *
 * @phpstan-type Account Model&object{two_factor_secret: ?string, two_factor_recovery_codes: ?array<int, string>, two_factor_confirmed_at: mixed, two_factor_last_step: ?int}
 */
final class TwoFactor
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Starts (or restarts) enrolment: a new secret that is not active until confirmed with a valid code. */
    public function begin(Model $account, string $issuer): array
    {
        $secret = Totp::generateSecret();

        $account->forceFill([
            'two_factor_secret' => $secret, 'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null, 'two_factor_last_step' => null,
        ])->saveQuietly();

        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($issuer, (string) $account->email, $secret)];
    }

    /** @return list<string>|null the recovery codes (shown once), or null when the code is wrong */
    public function confirm(Model $account, string $code): ?array
    {
        if ($account->two_factor_secret === null || $account->two_factor_confirmed_at !== null) {
            return null;
        }

        $step = Totp::verify($account->two_factor_secret, $code, null);

        if ($step === null) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $account->forceFill([
            'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map($this->hash(...), $codes),
        ])->saveQuietly();

        return $codes;
    }

    /** A TOTP code (replay-protected) or a recovery code (consumed). Serialised per account. */
    public function check(Model $account, string $code): bool
    {
        return DB::transaction(function () use ($account, $code): bool {
            $fresh = $account->newQuery()->lockForUpdate()->find($account->getKey());

            if ($fresh === null || ! $fresh->hasTwoFactor()) {
                return false;
            }

            if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code) ?? '') === 1) {
                $step = Totp::verify($fresh->two_factor_secret, $code, $fresh->two_factor_last_step);

                if ($step === null) {
                    return false;
                }

                $fresh->forceFill(['two_factor_last_step' => $step])->saveQuietly();

                return true;
            }

            $hash = $this->hash($this->normalise($code));
            $remaining = collect($fresh->two_factor_recovery_codes ?? []);
            $index = $remaining->search(fn (string $stored): bool => hash_equals($stored, $hash));

            if ($index === false) {
                return false;
            }

            $fresh->forceFill(['two_factor_recovery_codes' => $remaining->forget($index)->values()->all()])->saveQuietly();

            return true;
        });
    }

    /** @return list<string> */
    public function regenerate(Model $account): array
    {
        $codes = $this->newRecoveryCodes();
        $account->forceFill(['two_factor_recovery_codes' => array_map($this->hash(...), $codes)])->saveQuietly();

        return $codes;
    }

    public function disable(Model $account): void
    {
        $account->forceFill([
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null, 'two_factor_last_step' => null,
        ])->saveQuietly();
    }

    public function recoveryCodesLeft(Model $account): int
    {
        return count($account->two_factor_recovery_codes ?? []);
    }

    /** @return list<string> formatted XXXXX-XXXXX */
    private function newRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < (int) config('saas.two_factor.recovery_codes'); $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return $codes;
    }

    private function normalise(string $code): string
    {
        return Str::upper(str_replace(['-', ' '], '', $code));
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $this->normalise($code), (string) config('app.key'));
    }
}
