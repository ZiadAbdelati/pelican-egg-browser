<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('egg_browser_tracked_eggs', function (Blueprint $table) {
            $table->timestamp('checking_disabled_at')->nullable()->after('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('egg_browser_tracked_eggs', function (Blueprint $table) {
            $table->dropColumn('checking_disabled_at');
        });
    }
};
