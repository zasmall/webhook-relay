<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->unsignedSmallInteger('replay_count')->default(0)->after('delivered_at');
            $table->timestamp('last_replayed_at')->nullable()->after('replay_count');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['replay_count', 'last_replayed_at']);
        });
    }
};
