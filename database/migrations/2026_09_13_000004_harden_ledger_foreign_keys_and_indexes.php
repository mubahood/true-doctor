<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan findings K6 (ledger rows must never cascade away with a force-delete),
 * K13 (missing indexes) and K14 (users.role default was a retired role).
 * FK changes are MySQL-only: SQLite (tests) cannot alter constraints in place
 * and does not enforce the production semantics anyway.
 */
return new class extends Migration
{
    /** @var array<string, array{0:string,1:string}> table => [column, referenced table] */
    private const LEDGER_FKS = [
        'payments' => ['invoice_id', 'invoices'],
        'card_records' => ['patient_card_id', 'patient_cards'],
        'stock_movements' => ['stock_item_id', 'stock_items'],
        'invoice_items' => ['invoice_id', 'invoices'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::LEDGER_FKS as $table => [$column, $ref]) {
                Schema::table($table, function (Blueprint $t) use ($column, $ref) {
                    $t->dropForeign([$column]);
                    $t->foreign($column)->references('id')->on($ref)->restrictOnDelete();
                });
            }
        }

        Schema::table('invoices', fn (Blueprint $t) => $t->index(['hospital_id', 'created_at'], 'invoices_hospital_created_idx'));
        Schema::table('payments', fn (Blueprint $t) => $t->index(['hospital_id', 'created_at'], 'payments_hospital_created_idx'));
        Schema::table('notifications', fn (Blueprint $t) => $t->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_idx'));
        Schema::table('medical_services', fn (Blueprint $t) => $t->index(['hospital_id', 'status'], 'medical_services_hospital_status_idx'));
        Schema::table('users', fn (Blueprint $t) => $t->index(['hospital_id', 'role'], 'users_hospital_role_idx'));

        // A user created without an explicit role must fail loudly, not be
        // silently locked out with the retired 'data_clerk' default.
        Schema::table('users', fn (Blueprint $t) => $t->string('role', 32)->nullable()->default(null)->change());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('role', 32)->default('data_clerk')->change());
        Schema::table('users', fn (Blueprint $t) => $t->dropIndex('users_hospital_role_idx'));
        Schema::table('medical_services', fn (Blueprint $t) => $t->dropIndex('medical_services_hospital_status_idx'));
        Schema::table('notifications', fn (Blueprint $t) => $t->dropIndex('notifications_unread_idx'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('payments_hospital_created_idx'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropIndex('invoices_hospital_created_idx'));

        if (DB::getDriverName() === 'mysql') {
            foreach (self::LEDGER_FKS as $table => [$column, $ref]) {
                Schema::table($table, function (Blueprint $t) use ($column, $ref) {
                    $t->dropForeign([$column]);
                    $t->foreign($column)->references('id')->on($ref)->cascadeOnDelete();
                });
            }
        }
    }
};
