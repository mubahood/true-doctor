<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_releases', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 40)->unique();
            $table->string('commit', 40)->nullable();
            $table->string('status', 24)->default('queued');
            $table->text('notes')->nullable();
            $table->string('progress')->nullable();
            $table->text('failure')->nullable();
            $table->string('archive_path')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->timestamp('staged_at')->nullable();
            $table->timestamp('clean_install_passed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_releases');
    }
};
