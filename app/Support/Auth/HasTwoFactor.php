<?php

declare(strict_types=1);

namespace App\Support\Auth;

/** Columns and casts shared by every account type that can log in (tenant users, platform admins). */
trait HasTwoFactor
{
    public function initializeHasTwoFactor(): void
    {
        $this->makeHidden(['two_factor_secret', 'two_factor_recovery_codes']);
        $this->mergeCasts([
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
        ]);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }
}
