<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The data-plane migrations run, are idempotent, and roll back cleanly (SQLite here; MySQL uses the same Blueprint calls). */
class DataPlaneMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}.php");
    }

    public function test_tombstones_table_exists_with_its_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('sync_tombstones', ['id', 'hospital_id', 'entity', 'uuid', 'revision', 'reason', 'deleted_at']));
        $this->assertTrue(Schema::hasIndex('sync_tombstones', 'sync_tombstones_hospital_revision'));
        $this->assertTrue(Schema::hasIndex('sync_tombstones', 'sync_tombstones_entity_uuid'));
    }

    public function test_the_tombstone_migration_rolls_back_and_forward(): void
    {
        $m = $this->migration('2026_10_17_100000_create_sync_tombstones_table');

        $m->down();
        $this->assertFalse(Schema::hasTable('sync_tombstones'));
        $m->up();
        $this->assertTrue(Schema::hasTable('sync_tombstones'));
    }

    public function test_the_index_migration_is_idempotent_and_reversible(): void
    {
        $m = $this->migration('2026_10_17_100001_index_delta_and_search_columns');

        $this->assertTrue(Schema::hasIndex('patients', 'patients_hospital_updated_idx'));
        $this->assertTrue(Schema::hasIndex('stock_items', 'stock_items_hospital_sku_idx'));

        $m->up(); // a second run adds nothing and does not fail
        $m->down();
        $this->assertFalse(Schema::hasIndex('patients', 'patients_hospital_updated_idx'));
        $this->assertFalse(Schema::hasIndex('users', 'users_hospital_name_idx'));
        $m->up();
        $this->assertTrue(Schema::hasIndex('visits', 'visits_hospital_updated_idx'));
    }
}
