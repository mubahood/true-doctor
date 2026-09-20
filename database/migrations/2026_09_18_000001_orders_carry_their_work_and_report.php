<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order dialog becomes the place the work is done — see docs/orders.md.
 *
 * Three changes, each guarded so a half-applied run can be re-run:
 *
 *  1. `order_items.quantity` becomes decimal. It is an integer today, which is
 *     why DispensationService folds "× 6 tablets" into the line's NAME and
 *     charges a quantity of one. A real quantity can be reversed, reported on
 *     and shown in a column; a name cannot. No money moves: line_total is
 *     stored, and unit_price × quantity comes to the same figure.
 *  2. Orders gain a report — what was found, in words, autosaved.
 *  3. `order_attachments` — results, films and consent, on the PRIVATE disk
 *     and streamed only through a policy-gated controller, exactly as
 *     patient_documents are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('quantity', 12, 2)->default('1.00')->change();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'report')) {
                $table->text('report')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('orders', 'report_updated_at')) {
                $table->timestamp('report_updated_at')->nullable()->after('report');
            }
        });

        if (! Schema::hasTable('order_attachments')) {
            Schema::create('order_attachments', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();

                $table->string('file_path');
                $table->string('original_name')->nullable();
                $table->string('mime', 96)->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['hospital_id', 'order_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attachments');

        Schema::table('orders', function (Blueprint $table) {
            foreach (['report', 'report_updated_at'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->unsignedInteger('quantity')->default(1)->change();
            });
        }
    }
};
