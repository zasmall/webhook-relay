<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery log now turns date filters into primary-key ranges (ULIDs
 * start with their creation time), so nothing reads this index and every
 * insert paid to maintain it. Measured at 100k deliveries: a 30-day-old range
 * went from 20 ms (PK walked backwards, created_at filtered) to 0.15 ms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->index('created_at');
        });
    }
};
