<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Billing\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The institution's own view of its plan, quotas and coverage (for the "renew" banner and the settings page). */
final class SubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => Subscription::state($request->attributes->get('tenant'))]);
    }
}
