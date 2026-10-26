<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CloseCash;
use App\Http\Controllers\Controller;
use App\Http\Resources\CashClosingResource;
use App\Models\CashClosing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CashController extends Controller
{
    /** What the drawer should hold at the end of a date, before anyone counts it. */
    public function position(Request $request, CloseCash $action): JsonResponse
    {
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $date = $data['date'] ?? today()->toDateString();

        return response()->json(['date' => $date, 'last_closing' => \App\Support\Finance\CashGuard::lastClosing()] + $action->position($date));
    }

    public function closings(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return CashClosingResource::collection(
            CashClosing::query()->orderByDesc('closing_date')->cursorPaginate((int) $request->query('per_page', 31))
        );
    }

    public function close(Request $request, CloseCash $action): JsonResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'counted_balance' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'opening_balance' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $closing = $action->handle(
            $data['date'] ?? today()->toDateString(),
            (int) $data['counted_balance'],
            $data['note'] ?? null,
            $request->user(),
            (int) ($data['opening_balance'] ?? 0),
        );

        return (new CashClosingResource($closing))->response()->setStatusCode(201);
    }
}
