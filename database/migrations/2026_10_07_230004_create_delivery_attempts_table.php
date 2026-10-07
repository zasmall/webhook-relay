<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only log of every HTTP attempt. Never updated or deleted.
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->json('request_headers');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error', 1024)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at');

            $table->unique(['delivery_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
