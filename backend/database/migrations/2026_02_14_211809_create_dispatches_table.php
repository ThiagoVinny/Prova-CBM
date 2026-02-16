<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('occurrence_id');
            $table->string('resource_code');
            $table->string('status');
            $table->timestamps();

            $table->foreign('occurrence_id')
                ->references('id')
                ->on('occurrences')
                ->onDelete('cascade');

            $table->index(['occurrence_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatches');
    }
};
