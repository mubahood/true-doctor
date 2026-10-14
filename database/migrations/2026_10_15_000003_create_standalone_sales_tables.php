<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standalone_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price_ugx', 14, 2);
            $table->unsignedSmallInteger('updates_months')->default(12);
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('standalone_licences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('product_id')->constrained('standalone_products');
            $table->string('customer_name');
            $table->string('customer_email')->index();
            $table->json('domains');
            $table->json('limits')->nullable();
            $table->date('updates_until');
            $table->string('status', 20)->default('active');
            $table->string('source', 20);
            $table->string('reference')->nullable();
            $table->timestamps();
        });
        Schema::create('standalone_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('standalone_products');
            $table->uuid('licence_id')->nullable();
            $table->foreign('licence_id')->references('id')->on('standalone_licences');
            $table->string('reference')->unique();
            $table->string('gateway_tracking_id')->nullable()->unique();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('domain');
            $table->decimal('amount_ugx', 14, 2);
            $table->unsignedSmallInteger('updates_months');
            $table->json('limits')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('standalone_downloads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('licence_id');
            $table->foreign('licence_id')->references('id')->on('standalone_licences');
            $table->foreignId('release_id')->constrained('standalone_releases');
            $table->ipAddress('ip')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standalone_downloads');
        Schema::dropIfExists('standalone_orders');
        Schema::dropIfExists('standalone_licences');
        Schema::dropIfExists('standalone_products');
    }
};
