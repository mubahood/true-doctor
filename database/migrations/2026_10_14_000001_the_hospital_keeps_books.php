<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A general ledger (docs/finance.md).
 *
 * Until now every money record kept its own trail: invoices, payments, the card
 * ledger, the insurer floats and the stock ledger. Each was right about itself,
 * and none of them could say what the hospital is worth, what it earned last
 * month after its costs, or where the money went. They answer to these three
 * tables now.
 *
 *  - `ledger_accounts` is the chart of accounts. An account marked `is_book`
 *    is a place money actually sits — the cash drawer, a bank account, a
 *    mobile-money wallet — and is what a receipt or a payment names.
 *  - `journal_entries` is one event, written once. A mistake is undone by a
 *    second entry that reverses it, never by editing the first.
 *  - `journal_lines` are its debits and credits, which always balance. The
 *    entry's date is copied onto each line so a balance is one indexed sum.
 *
 * `source_type`/`source_id`/`source_event` name the system record an entry was
 * posted from. Unique together, so posting the same invoice twice — a retry, a
 * backfill run after live posting — finds the first entry instead of making a
 * second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ledger_accounts')) {
            Schema::create('ledger_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->string('code', 12);
                $table->string('name', 120);
                $table->string('type', 16);                 // asset | liability | equity | income | expense
                $table->string('subtype', 32)->nullable();  // cash, bank, receivable, cost_of_sales…
                // What the system posts to by name ("receivable", "revenue.lab").
                // Null for an account the hospital made itself.
                $table->string('system_key', 48)->nullable();
                $table->boolean('is_book')->default(false);
                $table->string('description', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['hospital_id', 'code']);
                $table->unique(['hospital_id', 'system_key']);
                $table->index(['hospital_id', 'type']);
            });
        }

        if (! Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->string('entry_no', 32);
                $table->date('date');
                $table->string('kind', 16);                  // system | income | expense | transfer | journal | opening | reversal
                $table->string('memo', 255);
                $table->string('reference', 120)->nullable();  // a receipt, a cheque, a transaction id
                $table->string('party', 120)->nullable();      // who paid, or who was paid
                $table->decimal('total', 14, 2);

                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('source_event', 32)->nullable();

                $table->foreignId('reverses_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('reversed_by_id')->nullable()->constrained('journal_entries')->nullOnDelete();

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['hospital_id', 'entry_no']);
                $table->unique(['hospital_id', 'source_type', 'source_id', 'source_event'], 'journal_source_once');
                $table->index(['hospital_id', 'date']);
                $table->index(['hospital_id', 'kind', 'date']);
            });
        }

        if (! Schema::hasTable('journal_lines')) {
            Schema::create('journal_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
                $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
                $table->date('date');
                $table->decimal('debit', 14, 2)->default(0);
                $table->decimal('credit', 14, 2)->default(0);
                $table->string('memo', 255)->nullable();

                $table->index(['hospital_id', 'ledger_account_id', 'date'], 'journal_lines_balance');
            });
        }

        // Which income a price-list service earns into, and the specialty it is
        // listed under. Both optional: a service with no account earns into the
        // one its kind of order maps to (docs/finance.md).
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'category')) {
                $table->string('category', 60)->nullable()->after('code');
            }
            if (! Schema::hasColumn('services', 'ledger_account_id')) {
                $table->foreignId('ledger_account_id')->nullable()->after('category')
                    ->constrained('ledger_accounts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'ledger_account_id')) {
                $table->dropConstrainedForeignId('ledger_account_id');
            }
            if (Schema::hasColumn('services', 'category')) {
                $table->dropColumn('category');
            }
        });

        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
