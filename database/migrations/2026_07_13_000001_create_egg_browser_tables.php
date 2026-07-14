<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egg_browser_repository_cache', function (Blueprint $table) {
            $table->id();
            $table->string('owner');
            $table->string('name');
            $table->string('branch')->default('main');
            $table->string('tree_sha')->nullable();
            $table->json('eggs')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['owner', 'name', 'branch']);
        });

        Schema::create('egg_browser_tracked_eggs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('egg_id')->nullable()->index();
            $table->string('source_owner');
            $table->string('source_repo');
            $table->string('source_path');
            $table->string('source_branch')->default('main');
            $table->string('source_sha')->nullable();
            $table->string('source_blob_sha')->nullable();
            $table->string('upstream_fingerprint', 64)->nullable();
            $table->string('installed_fingerprint', 64)->nullable();
            $table->json('installed_snapshot')->nullable();
            $table->uuid('egg_uuid')->nullable()->index();
            $table->string('egg_name')->nullable();
            $table->string('status')->default('unknown_unlinked');
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_installed_at')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['source_owner', 'source_repo', 'source_path'], 'egg_browser_source_unique');
            $table->index(['source_owner', 'source_repo']);
        });

        Schema::create('egg_browser_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egg_browser_settings');
        Schema::dropIfExists('egg_browser_tracked_eggs');
        Schema::dropIfExists('egg_browser_repository_cache');
    }
};
