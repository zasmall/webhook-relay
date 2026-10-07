<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->json('payload');
            $table->string('idempotency_key');
            $table->timestamp('received_at');

            // Idempotent ingest: a retried publish collides here instead of
            // creating a second event. See IngestEvent.
            $table->unique(['source_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
