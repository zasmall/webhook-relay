<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery log date-range filter.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->index('created_at');
        });

        // Health stats and the dashboard look at recent attempts only.
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
