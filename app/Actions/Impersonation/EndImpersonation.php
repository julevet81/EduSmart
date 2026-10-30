<?php

declare(strict_types=1);

namespace App\Actions\Impersonation;

use App\Models\ImpersonationSession;
use App\Support\Audit\AuditRecorder;
use Laravel\Sanctum\PersonalAccessToken;

final class EndImpersonation
{
    /** @param  string  $by  user (leaves) | admin (revoked from the platform) */
    public function handle(ImpersonationSession $session, string $by): void
    {
        if ($session->ended_at !== null) {
            return;
        }

        PersonalAccessToken::query()->whereKey($session->token_id)->delete();
        $session->update(['ended_at' => now(), 'ended_by' => $by]);

        AuditRecorder::event('platform.impersonation_ended', ['session_id' => $session->id, 'by' => $by], (int) $session->tenant_id);
    }
}
