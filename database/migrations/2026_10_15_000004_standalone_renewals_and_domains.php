<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renewing updates online, and moving a licence to another address
 * (docs/proposals/standalone-edition.md §7.3).
 *
 * A product may name what another period of updates costs; without it,
 * renewals stay a conversation with us. An order says whether it bought a
 * licence or renewed one. A licence remembers when its address last changed,
 * because a buyer may move it themselves once a month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standalone_products', function (Blueprint $table): void {
            $table->decimal('renewal_price_ugx', 14, 2)->nullable()->after('price_ugx');
        });
        Schema::table('standalone_orders', function (Blueprint $table): void {
            $table->string('kind', 12)->default('purchase')->after('product_id');
        });
        Schema::table('standalone_licences', function (Blueprint $table): void {
            $table->timestamp('domain_changed_at')->nullable()->after('domains');
        });
    }

    public function down(): void
    {
        Schema::table('standalone_licences', fn (Blueprint $table) => $table->dropColumn('domain_changed_at'));
        Schema::table('standalone_orders', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('standalone_products', fn (Blueprint $table) => $table->dropColumn('renewal_price_ugx'));
    }
};
