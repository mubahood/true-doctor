<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the data plane (docs/api/plane.md), each added only when missing.
 *
 * - `(hospital_id, updated_at)` on the lists that accept `?updated_since=`, so
 *   a delta is a range scan instead of a table scan.
 * - The prefix columns `GET /search` hits with `LIKE 'x%'`: names, second
 *   phone, SKU, item name, staff name. Numbers (`patient_no`, `visit_no`,
 *   `invoice_no`) and `phone_1` already have a hospital-leading index.
 */
return new class extends Migration
{
    /** @var array<string, list<array{0: list<string>, 1: string}>> */
    private const INDEXES = [
        'patients' => [[['hospital_id', 'updated_at'], 'patients_hospital_updated_idx'], [['hospital_id', 'first_name'], 'patients_hospital_first_name_idx'], [['hospital_id', 'phone_2'], 'patients_hospital_phone_2_idx']],
        'visits' => [[['hospital_id', 'updated_at'], 'visits_hospital_updated_idx']],
        'invoices' => [[['hospital_id', 'updated_at'], 'invoices_hospital_updated_idx']],
        'stock_items' => [[['hospital_id', 'updated_at'], 'stock_items_hospital_updated_idx'], [['hospital_id', 'sku'], 'stock_items_hospital_sku_idx'], [['hospital_id', 'name'], 'stock_items_hospital_name_idx']],
        'appointments' => [[['hospital_id', 'updated_at'], 'appointments_hospital_updated_idx']],
        'lab_orders' => [[['hospital_id', 'updated_at'], 'lab_orders_hospital_updated_idx']],
        'radiology_orders' => [[['hospital_id', 'updated_at'], 'radiology_orders_hospital_updated_idx']],
        'services' => [[['hospital_id', 'updated_at'], 'services_hospital_updated_idx']],
        'users' => [[['hospital_id', 'name'], 'users_hospital_name_idx']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as [$columns, $name]) {
                if (! Schema::hasColumns($table, $columns) || $this->indexed($table, $name)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as [, $name]) {
                if ($this->indexed($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    private function indexed(string $table, string $name): bool
    {
        return Schema::hasIndex($table, $name);
    }
};
