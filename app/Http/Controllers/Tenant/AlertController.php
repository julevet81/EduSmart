<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Messaging\SendAbsenceAlerts;
use App\Actions\Messaging\SendPaymentReminders;
use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Manual trigger of the automatic alerts (the same actions run from the scheduler through "messages:alerts"). */
final class AlertController extends Controller
{
    public function absences(Request $request, SendAbsenceAlerts $action): JsonResponse
    {
        $data = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'channel' => ['sometimes', 'in:sms,whatsapp,email'],
        ]);

        return response()->json($action->handle(
            (int) ($data['days'] ?? 30), (int) ($data['threshold'] ?? 3), MessageChannel::from($data['channel'] ?? 'sms'), $request->user(),
        ));
    }

    public function payments(Request $request, SendPaymentReminders $action): JsonResponse
    {
        $data = $request->validate([
            'min_overdue_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'channel' => ['sometimes', 'in:sms,whatsapp,email'],
        ]);

        return response()->json($action->handle(
            (int) ($data['min_overdue_days'] ?? 1), MessageChannel::from($data['channel'] ?? 'sms'), $request->user(),
        ));
    }
}
