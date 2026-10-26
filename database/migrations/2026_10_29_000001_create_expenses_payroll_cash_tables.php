<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Money columns are integer centimes of DZD. Expenses, payslips and cash closings are append-only (see the models). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->string('description');
            $table->string('supplier', 150)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('method', 14);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->string('status', 10)->default('valid');
            $table->string('idempotency_key', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reverse_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'paid_on']);
        });

        Schema::create('staff_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('position', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // payee_type + payee_id point at a teacher or a staff member (no FK: two possible tables).
        Schema::create('salary_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('payee_type', 10);
            $table->unsignedBigInteger('payee_id');
            $table->string('type', 12);
            $table->unsignedBigInteger('amount');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'payee_type', 'payee_id']);
        });

        Schema::create('payslips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->string('payee_type', 10);
            $table->unsignedBigInteger('payee_id');
            $table->char('month', 7);
            $table->unsignedBigInteger('fixed_amount')->default(0);
            $table->unsignedInteger('lessons_count')->default(0);
            $table->unsignedInteger('lessons_minutes')->default(0);
            $table->unsignedBigInteger('variable_amount')->default(0);
            $table->unsignedBigInteger('bonus_amount')->default(0);
            $table->unsignedBigInteger('deduction_amount')->default(0);
            $table->unsignedBigInteger('advance_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->string('status', 10)->default('draft');
            // 1 while the payslip is live (draft/paid), NULL once void/reversed: one live payslip per payee and month.
            $table->unsignedTinyInteger('live_marker')->nullable()->default(1);
            $table->string('method', 14)->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reverse_reason')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'payee_type', 'payee_id', 'month', 'live_marker'], 'payslips_one_live_per_month');
        });

        Schema::create('payroll_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('payee_type', 10);
            $table->unsignedBigInteger('payee_id');
            $table->string('kind', 10);
            $table->unsignedBigInteger('amount');
            $table->string('method', 14)->nullable();
            $table->date('effective_on');
            $table->string('note')->nullable();
            $table->string('status', 10)->default('valid');
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reverse_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'payee_type', 'payee_id', 'status']);
        });

        Schema::create('cash_closings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('closing_date');
            $table->date('period_start')->nullable();
            $table->bigInteger('opening_balance');
            $table->unsignedBigInteger('cash_in');
            $table->unsignedBigInteger('cash_out');
            $table->bigInteger('expected_balance');
            $table->unsignedBigInteger('counted_balance');
            $table->bigInteger('difference');
            $table->string('note', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'closing_date']);
        });
    }

    public function down(): void
    {
        foreach (['cash_closings', 'payroll_adjustments', 'payslips', 'salary_contracts', 'staff_members', 'expenses', 'expense_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
