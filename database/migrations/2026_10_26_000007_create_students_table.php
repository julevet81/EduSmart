<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('registration_number', 32);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('first_name_latin')->nullable();
            $table->string('last_name_latin')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('birth_certificate_number', 64)->nullable();
            $table->text('national_id')->nullable();      // encrypted
            $table->unsignedTinyInteger('wilaya_code')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('medical_notes')->nullable();    // encrypted
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'registration_number']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
