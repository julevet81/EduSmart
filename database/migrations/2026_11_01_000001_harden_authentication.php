<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'platform_admins'] as $table) {
            // MySQL cannot roll back DDL: a failed earlier run may already have added the columns
            if (Schema::hasColumn($table, 'two_factor_secret')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                $t->text('two_factor_secret')->nullable();                  // encrypted TOTP secret
                $t->text('two_factor_recovery_codes')->nullable();          // encrypted list of keyed hashes
                $t->timestamp('two_factor_confirmed_at')->nullable();
                $t->unsignedBigInteger('two_factor_last_step')->nullable(); // last accepted TOTP time step (replay guard)
                $t->unsignedSmallInteger('failed_login_count')->default(0);
                $t->timestamp('locked_until')->nullable();
            });
        }

        Schema::dropIfExists('refresh_tokens');

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 32);                              // morph alias: user | platform_admin
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('tenant_id')->nullable();             // null for platform admins
            $table->char('token_hash', 64)->unique();                        // sha256 of the opaque token
            $table->char('family_id', 36)->index();                          // one login = one family; reuse kills the family
            $table->unsignedBigInteger('access_token_id')->nullable();       // the Sanctum token issued alongside
            $table->dateTime('expires_at');
            $table->dateTime('family_expires_at');                           // absolute limit, rotation never extends it
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');

        foreach (['users', 'platform_admins'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
                    'two_factor_last_step', 'failed_login_count', 'locked_until']);
            });
        }
    }
};
