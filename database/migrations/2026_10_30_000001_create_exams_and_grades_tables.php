<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Scores are stored in HUNDREDTHS of a point (14,75 => 1475): no floats anywhere in the calculation chain. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('term_id')->constrained();
            $table->foreignId('group_id')->constrained();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('teacher_id')->nullable()->constrained();
            $table->string('name', 150);
            $table->string('kind', 12)->default('exam');
            $table->date('date');
            $table->unsignedSmallInteger('max_score')->default(20);
            $table->unsignedTinyInteger('coefficient')->default(1);
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'group_id', 'term_id']);
            $table->index(['tenant_id', 'teacher_id']);
        });

        Schema::create('grades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained();
            $table->foreignId('student_id')->constrained();
            $table->string('status', 10);
            $table->unsignedInteger('score')->nullable();
            $table->string('comment')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['exam_id', 'student_id']);
            $table->index(['tenant_id', 'student_id']);
        });

        // Weight of a subject in the general average, per level (and optionally per track; track_key 0 = whole level).
        Schema::create('subject_coefficients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained();
            $table->foreignId('track_id')->nullable()->constrained();
            $table->unsignedBigInteger('track_key')->default(0);
            $table->foreignId('subject_id')->constrained();
            $table->unsignedTinyInteger('coefficient');
            $table->timestamps();
            $table->unique(['tenant_id', 'level_id', 'track_key', 'subject_id'], 'subject_coefficients_unique');
        });

        Schema::create('term_remarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('term_id')->constrained();
            $table->string('remark', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'student_id', 'term_id']);
        });
    }

    public function down(): void
    {
        foreach (['term_remarks', 'subject_coefficients', 'grades', 'exams'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
