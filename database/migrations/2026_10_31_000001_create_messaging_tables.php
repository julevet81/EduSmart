<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('name', 100);
            $table->string('channel', 10)->nullable();
            $table->string('subject', 150)->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        // One table is the outbox of every channel AND the in-app inbox (channel = in_app).
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('kind', 20)->default('custom');
            $table->string('recipient_type', 10);
            $table->unsignedBigInteger('recipient_id');
            $table->string('recipient_name', 150)->nullable();
            $table->string('to', 190)->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 150)->nullable();
            $table->text('body');
            $table->string('status', 10)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->char('batch_id', 36)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['status', 'id']);
            $table->index(['tenant_id', 'recipient_type', 'recipient_id', 'read_at'], 'messages_inbox_index');
            $table->index(['tenant_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_templates');
    }
};
