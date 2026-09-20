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
            // `consultations`, not `visits`: the hub is not renamed until
            // 2026_09_16_000002, and a foreign key can only point at a table
            // that exists NOW. `constrained()` would infer `visits` from the
            // column name and fail on MySQL with "Cannot add foreign key
            // constraint" — SQLite does not enforce it at create time, which
            // is why the test suite never saw this. RENAME TABLE carries the
            // key across.
            $table->foreignId('visit_id')->constrained('consultations')->cascadeOnDelete();
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
