<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_inboxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key');
            $table->string('source');
            $table->string('type');
            $table->json('payload');
            $table->string('status');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_key', 'type']);
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_inboxes');
    }
};
