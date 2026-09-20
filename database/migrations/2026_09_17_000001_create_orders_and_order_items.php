<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One shape for every piece of work done for a patient (docs/orders.md).
 *
 * Until now four services each billed by hand — LabService, RadiologyService,
 * DispensationService and AdmissionService all called
 * BillingService::addCustomLine($visit, "Lab: {$name}", …), producing a charge
 * hanging off the VISIT with no link back to the work that caused it. So a
 * cancelled lab order left its charge on the bill, and revenue could only be
 * attributed by matching name strings.
 *
 * This creates `orders` and re-parents the billing lines onto it:
 * `medical_services` becomes `order_items`, a child of the work rather than a
 * sibling of it.
 *
 * THE ONE RULE THIS MIGRATION MUST NOT BREAK: no money moves. Every existing
 * line is carried across with its own name, unit price, quantity, line total,
 * tax flag and status untouched, so every visit's total is identical before and
 * after. OrderMigrationTest asserts exactly that.
 *
 * Every step is guarded. MySQL cannot roll back DDL, so a migration this wide
 * must resume from wherever it stopped rather than leave a database that can go
 * neither forward nor back.
 */
return new class extends Migration
{
    /** Name prefixes the old hand-written lines used, and the type they imply. */
    private const PREFIXES = [
        'Lab: ' => 'lab',
        'Radiology: ' => 'imaging',
        'Bed charge' => 'admission',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();

                // Everything belongs to a visit, and dies with it (visits.md).
                $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
                // Denormalised so a worklist can filter by patient without a join.
                $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

                $table->string('type', 16);
                $table->string('status', 16)->default('pending');
                $table->string('title');
                $table->string('notes')->nullable();

                // Who should do it — a person, a department, or neither yet.
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

                // The specialist record, where the type has one. Nullable: "see
                // Dr Alice today" needs no record of its own.
                $table->nullableMorphs('subject');

                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->string('cancel_reason')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['hospital_id', 'visit_id']);
                // The worklist: what is outstanding, oldest first.
                $table->index(['hospital_id', 'status', 'created_at'], 'ord_worklist');
                // "What is waiting on me" and "what is waiting on the lab".
                $table->index(['hospital_id', 'assigned_to', 'status'], 'ord_assignee');
                $table->index(['hospital_id', 'type', 'status'], 'ord_type');
            });
        }

        // ── medical_services becomes order_items ────────────────────────
        if (Schema::hasTable('medical_services') && ! Schema::hasTable('order_items')) {
            Schema::rename('medical_services', 'order_items');
        }

        if (! Schema::hasColumn('order_items', 'order_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                // Nullable for now; the backfill fills it, then it is tightened.
                $table->foreignId('order_id')->nullable()->after('hospital_id')->constrained()->cascadeOnDelete();
            });
        }

        // Pharmacy bills through the same path as everything else.
        if (! Schema::hasColumn('order_items', 'stock_item_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->foreignId('stock_item_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
            });
        }

        $this->backfill();

        // Only once every row has a parent.
        if (Schema::hasColumn('order_items', 'order_id')) {
            $orphans = DB::table('order_items')->whereNull('order_id')->count();
            if ($orphans > 0) {
                throw new RuntimeException("{$orphans} order_items still have no order — refusing to tighten the column.");
            }

            $this->requireOrderId();
        }

        // The line reaches a visit through its order now, exactly as an
        // insurance claim reaches one through its invoice (visits.md).
        if (Schema::hasColumn('order_items', 'visit_id')) {
            // Strict order, and both engines disagree about why:
            //   1. the FOREIGN KEY goes first — MySQL refuses to drop an index
            //      a constraint still needs (errno 1553);
            //   2. then the INDEXES — SQLite refuses to drop a column an index
            //      still depends on;
            //   3. then the column.
            // Neither is found by name: the constraint and index kept their
            // pre-rename names (medical_services_consultation_id_foreign, from
            // two renames ago), so both are located by inspection.
            $this->dropForeignKeysOn('order_items', 'visit_id');
            $this->dropIndexesOn('order_items', 'visit_id');
            Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('visit_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'visit_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->foreignId('visit_id')->nullable()->after('hospital_id')->constrained()->cascadeOnDelete();
            });

            // Put the visit back from the order that now carries it.
            foreach (DB::table('orders')->select('id', 'visit_id')->get() as $order) {
                DB::table('order_items')->where('order_id', $order->id)->update(['visit_id' => $order->visit_id]);
            }
        }

        foreach (['order_id', 'stock_item_id'] as $column) {
            if (Schema::hasColumn('order_items', $column)) {
                Schema::table('order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId($column));
            }
        }

        if (Schema::hasTable('order_items') && ! Schema::hasTable('medical_services')) {
            Schema::rename('order_items', 'medical_services');
        }

        Schema::dropIfExists('orders');
    }

    /**
     * Give every existing line an order.
     *
     * Lines raised on the same visit, by the same person, in the same minute,
     * with the same implied type were one act of work — they become one order.
     * All of them are marked completed: they are history, and pretending they
     * are pending would fill the new worklist with years of closed work.
     */
    private function backfill(): void
    {
        $loose = DB::table('order_items')->whereNull('order_id')->orderBy('id')->get();
        if ($loose->isEmpty()) {
            return;
        }

        $groups = [];
        foreach ($loose as $line) {
            $type = $this->typeOf($line->name);
            $minute = Carbon::parse($line->created_at ?? now())->format('YmdHi');
            $groups[$line->visit_id.'|'.($line->ordered_by ?? 0).'|'.$type.'|'.$minute][] = $line;
        }

        foreach ($groups as $lines) {
            $first = $lines[0];
            $visit = DB::table('visits')->where('id', $first->visit_id)->first();
            if ($visit === null) {
                continue;   // the visit went; the cascade will take these too
            }

            $type = $this->typeOf($first->name);
            $orderId = DB::table('orders')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'hospital_id' => $first->hospital_id,
                'visit_id' => $first->visit_id,
                'patient_id' => $visit->patient_id,
                'type' => $type,
                'status' => 'completed',
                'title' => $this->titleFor($type, $lines),
                'notes' => \App\Models\Order::CARRIED_OVER,
                'requested_by' => $first->ordered_by,
                'assigned_to' => $first->ordered_by,
                'completed_at' => $first->created_at,
                'created_at' => $first->created_at,
                'updated_at' => now(),
            ]);

            DB::table('order_items')
                ->whereIn('id', array_map(fn ($l) => $l->id, $lines))
                ->update(['order_id' => $orderId]);
        }
    }

    /** Drop every foreign key on a column, whatever it happens to be called. */
    private function dropForeignKeysOn(string $table, string $column): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (! in_array($column, $foreignKey['columns'], true)) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(
                $foreignKey['name'] ?: [$column]
            ));
        }
    }

    /** Drop every index that names a column, whatever it happens to be called. */
    private function dropIndexesOn(string $table, string $column): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (! in_array($column, $index['columns'], true) || ($index['primary'] ?? false)) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
        }
    }

    private function typeOf(?string $name): string
    {
        foreach (self::PREFIXES as $prefix => $type) {
            if ($name !== null && str_starts_with($name, $prefix)) {
                return $type;
            }
        }

        return 'consultation';
    }

    /** @param list<object> $lines */
    private function titleFor(string $type, array $lines): string
    {
        if (count($lines) === 1) {
            return (string) $lines[0]->name;
        }

        return ucfirst($type).' — '.count($lines).' items';
    }

    /**
     * Make order_id required with a cascading delete: a line cannot outlive the
     * work it belongs to. Laravel cannot change a constrained column in place,
     * so the constraint comes off, the column is tightened, and it goes back.
     */
    private function requireOrderId(): void
    {
        foreach (Schema::getForeignKeys('order_items') as $foreignKey) {
            if (in_array('order_id', $foreignKey['columns'], true)) {
                Schema::table('order_items', fn (Blueprint $t) => $t->dropForeign($foreignKey['name'] ?: ['order_id']));
            }
        }

        Schema::table('order_items', fn (Blueprint $t) => $t->foreignId('order_id')->nullable(false)->change());
        Schema::table('order_items', fn (Blueprint $t) => $t->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete());
    }
};
