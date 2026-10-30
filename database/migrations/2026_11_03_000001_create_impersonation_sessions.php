<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('impersonation_sessions');

        // No foreign keys on purpose: the trail of who entered an account must outlive the account.
        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('platform_admin_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('reason', 250);
            $table->boolean('can_write')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('ended_by', 20)->nullable(); // user | admin | expired
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
            $table->index('token_id');
            $table->index('platform_admin_id');
        });

        if (! Schema::hasColumn('audit_logs', 'impersonator_id')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->unsignedBigInteger('impersonator_id')->nullable()->after('actor_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_logs', 'impersonator_id')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->dropColumn('impersonator_id');
            });
        }

        Schema::dropIfExists('impersonation_sessions');
    }
};
