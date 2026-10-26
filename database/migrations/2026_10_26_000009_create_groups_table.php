<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('level_id')->constrained();
            $table->foreignId('track_id')->nullable()->constrained();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_year_id', 'name']);
            $table->index(['tenant_id', 'level_id']);
        });

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('group_id')->constrained();
            $table->foreignId('academic_year_id')->constrained();
            $table->string('status', 20);
            // 1 while active, NULL otherwise: the unique index below then allows only ONE active
            // enrollment per student per year, on every database (NULLs never collide).
            $table->unsignedTinyInteger('active_marker')->nullable();
            $table->date('enrolled_on');
            $table->date('ended_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'student_id', 'academic_year_id', 'active_marker'], 'enrollments_one_active_per_year');
            $table->index(['tenant_id', 'group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('groups');
    }
};
