<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings, Import data (docs/imports.md): a hospital brings its patients,
 * price list, stock and booked appointments in from a spreadsheet.
 *
 *   import_batches  one upload: who, when, what kind, how the columns were read, the counts
 *   import_rows     one line of the sheet: the cells, what the check found, what was done
 *
 * Also the old file number on a patient (the number on the paper file or in
 * the old system, kept and searchable), and a flag on an invoice that only
 * carries a balance brought forward from before the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('status', 16)->default('mapping');
            $table->string('file_name', 191);
            $table->json('headings')->nullable();
            $table->json('column_map')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('added_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('undone_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('imported_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('undone_at')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'created_at']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('line');
            $table->json('cells');
            $table->string('status', 16)->default('pending');
            $table->json('problems')->nullable();
            $table->unsignedBigInteger('match_id')->nullable();
            $table->string('match_reason', 100)->nullable();
            $table->string('decision', 8)->nullable();
            $table->string('record_type', 100)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('extra')->nullable();
            $table->string('message', 255)->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'status']);
            $table->index(['import_batch_id', 'line']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('old_file_no', 50)->nullable()->after('patient_no');
            $table->index(['hospital_id', 'old_file_no']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('opening_balance')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('opening_balance');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['hospital_id', 'old_file_no']);
            $table->dropColumn('old_file_no');
        });

        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
