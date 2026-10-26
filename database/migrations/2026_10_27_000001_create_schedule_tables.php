<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->index(['tenant_id', 'starts_on']);
        });

        // Recurring weekly rule. Dates and times are local wall-clock values (Algeria has no DST).
        Schema::create('schedule_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('group_id')->constrained();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('room_id')->nullable()->constrained();
            $table->unsignedTinyInteger('weekday');          // 0 = Sunday ... 6 = Saturday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'teacher_id', 'weekday']);
            $table->index(['tenant_id', 'room_id', 'weekday']);
            $table->index(['tenant_id', 'group_id', 'weekday']);
        });

        // Concrete lessons: generated from slots for a short window, or added manually (make-up lessons).
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('group_id')->constrained();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('room_id')->nullable()->constrained();
            $table->string('kind', 10);
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('status', 12);
            $table->boolean('is_customized')->default(false);
            $table->string('note', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamps();
            // Makes generation idempotent. NULL slots (make-up lessons) never collide.
            $table->unique(['schedule_slot_id', 'date']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'teacher_id', 'date']);
            $table->index(['tenant_id', 'room_id', 'date']);
            $table->index(['tenant_id', 'group_id', 'date']);
        });

        // Attendance stores EXCEPTIONS only: a student without a row is present.
        Schema::create('absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->string('type', 10);
            $table->string('reason', 255)->nullable();
            $table->boolean('is_justified')->default(false);
            $table->string('justification', 500)->nullable();
            $table->timestamp('justified_at')->nullable();
            $table->timestamps();
            $table->unique(['lesson_id', 'student_id']);
            $table->index(['tenant_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('schedule_slots');
        Schema::dropIfExists('holidays');
    }
};
