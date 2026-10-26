<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\AllocateCredit;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

/** A student's running account: what was billed, what was paid, what is still due, and unused credit. */
final class StudentAccountController extends Controller
{
    public function show(string $student): JsonResponse
    {
        return response()->json($this->summary(Student::query()->findOrFail($student)));
    }

    public function allocateCredit(string $student, AllocateCredit $action): JsonResponse
    {
        $model = Student::query()->findOrFail($student);
        $applied = $action->handle($model);

        return response()->json(['applied' => $applied] + $this->summary($model));
    }

    /** @return array<string, mixed> */
    private function summary(Student $student): array
    {
        $live = [InvoiceStatus::Open->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value];

        $billed = (int) Invoice::query()->where('student_id', $student->getKey())->whereIn('status', $live)->sum('total_amount');
        $invoicePaid = (int) Invoice::query()->where('student_id', $student->getKey())->whereIn('status', $live)->sum('paid_amount');

        $received = (int) Payment::query()->where('student_id', $student->getKey())
            ->where('status', PaymentStatus::Valid->value)->sum('amount');
        $allocated = (int) PaymentAllocation::query()->whereNull('reversed_at')
            ->whereIn('payment_id', Payment::query()->where('student_id', $student->getKey())
                ->where('status', PaymentStatus::Valid->value)->select('id'))
            ->sum('amount');

        $overdue = (int) Invoice::query()->where('student_id', $student->getKey())
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::PartiallyPaid->value])
            ->where('due_date', '<', today()->toDateString())
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) as due')->value('due');

        return [
            'student_id' => $student->getKey(),
            'billed' => $billed,
            'paid_on_invoices' => $invoicePaid,
            'outstanding' => $billed - $invoicePaid,
            'overdue' => $overdue,
            'received' => $received,
            'credit' => $received - $allocated,
        ];
    }
}
