<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->unsignedBigInteger('price_monthly')->default(0);   // centimes
            $table->unsignedInteger('max_students')->nullable();       // null = unlimited
            $table->unsignedInteger('max_users')->nullable();          // staff accounts; parent/student portal accounts are free
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Starting catalogue. Prices are PLACEHOLDERS: set the real ones with PATCH /platform/plans/{id}.
        $now = now();
        DB::table('plans')->insert([
            ['code' => 'starter', 'name' => 'Starter', 'price_monthly' => 300_000, 'max_students' => 100, 'max_users' => 5, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'standard', 'name' => 'Standard', 'price_monthly' => 800_000, 'max_students' => 500, 'max_users' => 20, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'premium', 'name' => 'Premium', 'price_monthly' => 1_800_000, 'max_students' => null, 'max_users' => null, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('tenants', function (Blueprint $table): void {
            $table->date('subscription_ends_at')->nullable()->after('trial_ends_at');
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');                                  // inclusive
            $table->unsignedTinyInteger('months');
            $table->unsignedBigInteger('amount');                        // centimes actually received
            $table->string('method', 32);
            $table->string('reference', 120)->nullable();
            $table->date('paid_on');
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('status', 16)->default('active');            // active | void
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();      // platform admin id
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('subscription_ends_at'));
        Schema::dropIfExists('plans');
    }
};
