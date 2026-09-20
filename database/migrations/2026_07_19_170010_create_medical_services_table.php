<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A billable service line ordered on a visit. name/unit_price are
 * *snapshots* taken when ordered (so later catalogue price changes never rewrite
 * history); line_total = unit_price × quantity, computed by BillingService (bcmath).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');                          // snapshot
            $table->decimal('unit_price', 12, 2);            // snapshot
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('line_total', 12, 2);
            $table->boolean('tax_exempt')->default(false);
            $table->string('status', 12)->default('ordered');
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hospital_id', 'visit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_services');
    }
};
