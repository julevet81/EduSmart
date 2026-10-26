<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All money columns are INTEGER CENTIMES of Algerian dinar (1 DA = 100 centimes): no floats, no rounding drift.
 * Invoices and payments are append-only (see the models): corrections are voids / reversals, never edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('subject_id')->nullable()->constrained();
            $table->string('name', 150);
            $table->string('billing_mode', 12);
            $table->unsignedBigInteger('amount');
            $table->unsignedTinyInteger('due_day')->default(5);
            $table->boolean('bill_absences')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'academic_year_id']);
        });

        Schema::create('student_fees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('fee_plan_id')->constrained();
            $table->string('discount_type', 10)->nullable();
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->string('discount_reason')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['student_id', 'fee_plan_id']);
            $table->index(['tenant_id', 'fee_plan_id']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('student_id')->constrained();
            $table->foreignId('student_fee_id')->nullable()->constrained();
            $table->foreignId('fee_plan_id')->nullable()->constrained();
            $table->foreignId('academic_year_id')->nullable()->constrained();
            $table->string('description');
            $table->string('period_key', 40)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('paid_amount')->default(0);   // cache, recomputed from allocations
            $table->string('status', 16);
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['student_fee_id', 'period_key']);       // generation can never bill a period twice
            $table->index(['tenant_id', 'student_id', 'status']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 32);
            $table->foreignId('student_id')->constrained();
            $table->foreignId('guardian_id')->nullable()->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('method', 16);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->string('status', 10);
            $table->string('idempotency_key', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->string('reverse_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'receipt_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'student_id']);
            $table->index(['tenant_id', 'paid_on']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index('invoice_id');
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('student_fees');
        Schema::dropIfExists('fee_plans');
    }
};
