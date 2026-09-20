<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adding a foreign key to a table that already has rows in it.
 *
 * SQLite cannot add a constraint to a live table, so Laravel adds one by
 * REBUILDING the table: copy into `__temp__<table>`, `drop table <table>`,
 * rename. It emits `PRAGMA foreign_keys = 0` first — but SQLite silently
 * ignores that pragma inside a transaction, which is where a migration run by
 * a test, or by anything else holding one open, lives. The drop then cascades
 * and takes every dependent row with it.
 *
 * That is not a theory: adding `visits.discounted_by` deleted every order,
 * order item and bill line belonging to an existing visit, and
 * OrderMigrationTest caught it by migrating a real bill forward and comparing
 * the figures.
 *
 * On MySQL — what this system runs on — the same thing is an in-place ALTER
 * that touches nothing else. So the constraint is added there and skipped on
 * SQLite, and the relation works either way: Eloquent needs no database key,
 * and a row whose target has gone simply reads as null.
 *
 * This is only about ALTERING a table. A foreign key declared inside
 * `Schema::create` is written into the table from the start, costs no rebuild,
 * and needs none of this.
 */
class SchemaKeys
{
    /** True where adding a constraint to a live table costs a whole-table copy. */
    public static function alterMeansRebuild(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * Add a foreign key to an existing table, where the engine can do it in
     * place. Re-runnable: a key that is already there is left alone.
     *
     * @param  'cascade'|'null'  $onDelete
     */
    public static function add(string $table, string $column, string $on, string $onDelete = 'null'): void
    {
        if (self::alterMeansRebuild() || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (self::nameOf($table, $column) !== null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $on, $onDelete) {
            $key = $blueprint->foreign($column)->references('id')->on($on);
            $onDelete === 'cascade' ? $key->cascadeOnDelete() : $key->nullOnDelete();
        });
    }

    /** Drop it again, by whatever name the engine gave it. Safe to re-run. */
    public static function drop(string $table, string $column): void
    {
        if (self::alterMeansRebuild() || ! Schema::hasTable($table)) {
            return;
        }

        $name = self::nameOf($table, $column);

        if ($name !== null) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        }
    }

    /** The constraint's own name, or null where there is none on that column. */
    private static function nameOf(string $table, string $column): ?string
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'], true)) {
                return ($foreignKey['name'] ?: null) ?? $column;
            }
        }

        return null;
    }
}
