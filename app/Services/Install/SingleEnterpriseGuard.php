<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\DB;

/** Installed only in a buyer database; SaaS remains fully multi-tenant. */
final class SingleEnterpriseGuard
{
    public function install(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $existing = DB::table('information_schema.TRIGGERS')
                ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->where('EVENT_OBJECT_TABLE', 'hospitals')
                ->pluck('TRIGGER_NAME')->all();
            if (! in_array('td_hospital_single_insert', $existing, true)) {
                DB::unprepared('CREATE TRIGGER td_hospital_single_insert BEFORE INSERT ON hospitals FOR EACH ROW SET NEW.standalone_slot = 1');
            }
            if (! in_array('td_hospital_single_update', $existing, true)) {
                DB::unprepared('CREATE TRIGGER td_hospital_single_update BEFORE UPDATE ON hospitals FOR EACH ROW SET NEW.standalone_slot = 1');
            }
            if (! in_array('td_hospital_no_delete', $existing, true)) {
                DB::unprepared("CREATE TRIGGER td_hospital_no_delete BEFORE DELETE ON hospitals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The standalone organisation cannot be deleted'");
            }

            return;
        }
        if ($driver === 'sqlite') {
            DB::unprepared('CREATE TRIGGER IF NOT EXISTS td_hospital_single_insert AFTER INSERT ON hospitals BEGIN UPDATE hospitals SET standalone_slot = 1 WHERE id = NEW.id; END');
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS td_hospital_single_update BEFORE UPDATE ON hospitals WHEN NEW.standalone_slot IS NOT 1 AND OLD.standalone_slot IS NOT NULL BEGIN SELECT RAISE(ABORT, 'The standalone organisation slot cannot change'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS td_hospital_no_delete BEFORE DELETE ON hospitals BEGIN SELECT RAISE(ABORT, 'The standalone organisation cannot be deleted'); END");

            return;
        }

        throw new \RuntimeException('Standalone installation needs a MySQL-compatible or SQLite database.');
    }
}
