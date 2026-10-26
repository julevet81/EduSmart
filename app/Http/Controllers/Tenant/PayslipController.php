<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CancelPayslip;
use App\Actions\Finance\GeneratePayslips;
use App\Actions\Finance\PayPayslip;
use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use App\Enums\PayslipStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayslipResource;
use App\Models\Payslip;
use App\Support\Finance\Payee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class PayslipController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'month' => ['sometimes', 'date_format:Y-m'],
            'payee_type' => ['sometimes', Rule::enum(PayeeType::class)],
            'payee_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(PayslipStatus::class)],
        ]);

        $page = Payslip::query()
            ->when($request->query('month'), fn ($q, $v) => $q->where('month', $v))
            ->when($request->query('payee_type'), fn ($q, $v) => $q->where('payee_type', $v))
            ->when($request->query('payee_id'), fn ($q, $v) => $q->where('payee_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25));

        $names = Payee::names($page->getCollection());
        $page->getCollection()->each(fn (Payslip $p) => $p->setAttribute('payee_name', $names[$p->payee_type->value.':'.$p->payee_id] ?? null));

        return PayslipResource::collection($page);
    }

    public function show(string $id): PayslipResource
    {
        return new PayslipResource($this->named(Payslip::query()->findOrFail($id)));
    }

    public function generate(Request $request, GeneratePayslips $action): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'payee_type' => ['required_with:payee_id', Rule::enum(PayeeType::class)],
            'payee_id' => ['sometimes', 'integer'],
        ]);

        return response()->json($action->handle(
            $data['month'],
            isset($data['payee_type']) ? PayeeType::from($data['payee_type']) : null,
            $data['payee_id'] ?? null,
            $request->user(),
        ));
    }

    public function pay(Request $request, string $id, PayPayslip $action): PayslipResource
    {
        $data = $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $payslip = $action->handle(
            Payslip::query()->findOrFail($id),
            PaymentMethod::from($data['method']),
            $data['paid_on'] ?? today()->toDateString(),
            $request->user(),
        );

        return new PayslipResource($this->named($payslip));
    }

    /** Draft only. */
    public function void(Request $request, string $id, CancelPayslip $action): PayslipResource
    {
        return $this->cancel($request, $id, PayslipStatus::Draft, $action);
    }

    /** Paid only. */
    public function reverse(Request $request, string $id, CancelPayslip $action): PayslipResource
    {
        return $this->cancel($request, $id, PayslipStatus::Paid, $action);
    }

    private function cancel(Request $request, string $id, PayslipStatus $expected, CancelPayslip $action): PayslipResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return new PayslipResource($this->named(
            $action->handle(Payslip::query()->findOrFail($id), $expected, $data['reason'], $request->user())
        ));
    }

    private function named(Payslip $payslip): Payslip
    {
        $names = Payee::names([$payslip]);

        return $payslip->setAttribute('payee_name', $names[$payslip->payee_type->value.':'.$payslip->payee_id] ?? null);
    }
}
