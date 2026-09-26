<?php

namespace App\Console\Commands;

use App\Models\Hospital;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\OrderAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Remove hospitals that should never have existed — spam sign-ups, dummy
 * tenants — with everything they own, and nothing anybody else owns.
 *
 * Guards, all of which must pass for EACH hospital before a row goes:
 *
 *   1. It is not the demonstration hospital (`demo.hospital`). Ever.
 *   2. Nobody has paid for it: no recorded subscription payment and no
 *      successful gateway payment. A paying customer is never "dummy".
 *   3. Its users are not referenced by another hospital's records (a doctor
 *      who also worked elsewhere, say). Removing them would leave the other
 *      hospital's history pointing at nobody, so it refuses instead.
 *
 * What goes: every row in every table with its hospital_id (found from the
 * schema, since a tenant being removed owns all of them), its users and what
 * hangs off them (logins, roles, tokens, notifications, sessions, reset
 * tokens, the activity they caused), its subscriptions' payments, and its
 * uploaded files. A traffic record that converted into it keeps the visit
 * and loses the link. All of it in one transaction: a failure leaves the
 * database as it was.
 *
 * Run with --dry-run first; take a backup before --force.
 */
class PurgeHospitals extends Command
{
    protected $signature = 'hospitals:purge
                            {slugs* : The hospitals to remove, by slug}
                            {--dry-run : Say what would be removed and remove nothing}
                            {--force : Do it without asking}';

    protected $description = 'Remove spam or dummy hospitals and everything they own';

    public function handle(): int
    {
        $hospitals = [];
        foreach ((array) $this->argument('slugs') as $slug) {
            $hospital = Hospital::withoutGlobalScopes()->withTrashed()->where('slug', $slug)->first();
            if ($hospital === null) {
                $this->error("No hospital with the slug '{$slug}'. Nothing was changed.");

                return self::FAILURE;
            }
            if (($why = $this->refusal($hospital)) !== null) {
                $this->error("REFUSED — '{$hospital->name}' ({$slug}): {$why} Nothing was changed.");

                return self::FAILURE;
            }
            $hospitals[] = $hospital;
        }

        $tables = $this->hospitalTables();

        foreach ($hospitals as $hospital) {
            $users = User::withoutGlobalScopes()->where('hospital_id', $hospital->id)->get(['id', 'email']);
            $this->line("<info>{$hospital->name}</info> (id {$hospital->id}, {$hospital->slug}) — ".$users->count().' user(s): '.$users->pluck('email')->implode(', '));
            foreach ($tables as $table) {
                $n = DB::table($table)->where('hospital_id', $hospital->id)->count();
                if ($n > 0) {
                    $this->line(sprintf('    %-30s %d', $table, $n));
                }
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Remove these hospitals and everything above?', false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        foreach ($hospitals as $hospital) {
            $files = $this->filesOf($hospital);
            $this->purge($hospital, $tables);
            $this->deleteFiles($files);
            $this->info("Removed {$hospital->name} ({$hospital->slug}).");
        }

        return self::SUCCESS;
    }

    /** Why this hospital must not be removed, or null. */
    private function refusal(Hospital $hospital): ?string
    {
        if ($hospital->slug === (string) config('demo.hospital', 'general-hospital-a')) {
            return 'it is the demonstration hospital.';
        }

        $subscriptionIds = DB::table('subscriptions')->where('hospital_id', $hospital->id)->pluck('id');
        if ($subscriptionIds->isNotEmpty() && DB::table('subscription_payments')->whereIn('subscription_id', $subscriptionIds)->exists()) {
            return 'it has recorded subscription payments — it is a customer.';
        }
        if (Schema::hasTable('gateway_logs') && Schema::hasColumn('gateway_logs', 'status')
            && DB::table('gateway_logs')->where('hospital_id', $hospital->id)->whereIn('status', ['successful', 'success', 'completed', 'paid'])->exists()) {
            return 'it has a successful online payment — it is a customer.';
        }

        $userIds = User::withoutGlobalScopes()->where('hospital_id', $hospital->id)->pluck('id');
        if ($userIds->isNotEmpty()) {
            foreach ($this->userForeignKeys() as [$table, $column]) {
                $q = DB::table($table)->whereIn($column, $userIds);
                if (Schema::hasColumn($table, 'hospital_id')) {
                    $q->where(fn ($w) => $w->where('hospital_id', '!=', $hospital->id)->orWhereNull('hospital_id'));
                }
                if ($table !== 'users' && $q->exists()) {
                    return "its users are referenced by another hospital's records ({$table}.{$column}).";
                }
            }
        }

        return null;
    }

    /** @return list<string> every table with a hospital_id, users last */
    private function hospitalTables(): array
    {
        if (DB::getDriverName() === 'mysql') {
            // One question to the catalogue, not one per table.
            $tables = collect(DB::select(
                'select c.table_name as t from information_schema.columns c
                 join information_schema.tables tb on tb.table_schema = c.table_schema and tb.table_name = c.table_name
                 where c.table_schema = database() and c.column_name = ? and tb.table_type = ?',
                ['hospital_id', 'BASE TABLE'],
            ))->pluck('t')->map(fn ($t) => (string) $t)->all();
        } else {
            $tables = array_values(array_filter(
                array_map(fn ($t) => (string) $t['name'], Schema::getTables()),
                fn ($t) => Schema::hasColumn($t, 'hospital_id'),
            ));
        }

        $tables = array_values(array_filter($tables, fn ($t) => ! in_array($t, ['users', 'hospitals'], true)));
        sort($tables);
        $tables[] = 'users';

        return $tables;
    }

    /** @var list<array{0:string,1:string}>|null */
    private ?array $userKeys = null;

    /** @return list<array{0:string,1:string}> columns that point at users */
    private function userForeignKeys(): array
    {
        if ($this->userKeys !== null) {
            return $this->userKeys;
        }

        if (DB::getDriverName() === 'mysql') {
            return $this->userKeys = collect(DB::select(
                'select table_name as t, column_name as c from information_schema.key_column_usage
                 where table_schema = database() and referenced_table_name = ?',
                ['users'],
            ))->map(fn ($r) => [(string) $r->t, (string) $r->c])->all();
        }

        $out = [];
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys((string) $table['name']) as $fk) {
                if (($fk['foreign_table'] ?? null) === 'users') {
                    foreach ($fk['columns'] as $column) {
                        $out[] = [(string) $table['name'], (string) $column];
                    }
                }
            }
        }

        return $this->userKeys = $out;
    }

    /** @return list<array{0:string,1:string}> [disk, path] of every file it uploaded */
    private function filesOf(Hospital $hospital): array
    {
        $files = [];
        if (Schema::hasTable('attachments')) {
            foreach (DB::table('attachments')->where('hospital_id', $hospital->id)->pluck('file_path') as $p) {
                $files[] = ['attachments', (string) $p];
            }
        }
        if (Schema::hasTable('patient_documents')) {
            foreach (DB::table('patient_documents')->where('hospital_id', $hospital->id)->pluck('file_path') as $p) {
                $files[] = ['documents', (string) $p];
            }
        }
        if (filled($hospital->logo)) {
            $files[] = ['public', (string) $hospital->logo];
        }

        return $files;
    }

    /** @param list<string> $tables */
    private function purge(Hospital $hospital, array $tables): void
    {
        $id = $hospital->id;
        $users = User::withoutGlobalScopes()->where('hospital_id', $id)->get(['id', 'email']);
        $userIds = $users->pluck('id')->all();
        $emails = $users->pluck('email')->all();
        $userType = User::class;

        // Checks off OUTSIDE the transaction: SQLite ignores the switch inside
        // one. The order below needs no checks; they are off so a combination
        // nobody anticipated cannot stop a removal halfway.
        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($id, $tables, $userIds, $emails, $userType) {
                // What hangs off the people.
                if ($userIds !== []) {
                    DB::table('personal_access_tokens')->where('tokenable_type', $userType)->whereIn('tokenable_id', $userIds)->delete();
                    DB::table('model_has_roles')->where('model_type', $userType)->whereIn('model_id', $userIds)->delete();
                    DB::table('model_has_permissions')->where('model_type', $userType)->whereIn('model_id', $userIds)->delete();
                    DB::table('notifications')->where('notifiable_type', $userType)->whereIn('notifiable_id', $userIds)->delete();
                    if (Schema::hasTable('sessions')) {
                        DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                    }
                    if (Schema::hasTable('activity_log')) {
                        DB::table('activity_log')->where('causer_type', $userType)->whereIn('causer_id', $userIds)->delete();
                    }
                }
                if ($emails !== [] && Schema::hasTable('password_reset_tokens')) {
                    DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
                }

                // What hangs off its subscriptions.
                $subscriptionIds = DB::table('subscriptions')->where('hospital_id', $id)->pluck('id');
                DB::table('subscription_payments')->whereIn('subscription_id', $subscriptionIds)->delete();

                // The visit that brought it in stays; the link goes.
                if (Schema::hasColumn('traffic_sessions', 'converted_hospital_id')) {
                    DB::table('traffic_sessions')->where('converted_hospital_id', $id)->update(['converted_hospital_id' => null]);
                }

                // Everything it owns — always filtered by its id — then it.
                foreach ($tables as $table) {
                    DB::table($table)->where('hospital_id', $id)->delete();
                }
                DB::table('hospitals')->where('id', $id)->delete();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** @param list<array{0:string,1:string}> $files */
    private function deleteFiles(array $files): void
    {
        foreach ($files as [$kind, $path]) {
            try {
                $disk = match ($kind) {
                    'attachments' => app(OrderAttachmentService::class)->disk(),
                    'documents' => app(DocumentService::class)->disk(),
                    default => Storage::disk('public'),
                };
                if ($path !== '' && $disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (Throwable $e) {
                // A file that will not delete is a warning, never a reason to
                // undo a removal that has already been committed.
                $this->warn("Could not delete file {$path}: {$e->getMessage()}");
            }
        }
    }
}
