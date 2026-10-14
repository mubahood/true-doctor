<?php

namespace App\Services\Install;

use App\Models\Hospital;
use App\Models\User;
use App\Services\Finance\ChartOfAccounts;
use App\Support\CurrentHospital;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use Database\Seeders\StandaloneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** One installation engine, shared by the web installer and CLI command. */
final class StandaloneInstaller
{
    /**
     * @param  array{hospital_name:string,owner_name:string,owner_email:string,owner_password:string,currency:string,timezone:string}  $details
     */
    public function install(array $details): User
    {
        $this->checkIdentity();
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new \RuntimeException('Database migrations failed.');
        }

        return $this->complete($details);
    }

    /**
     * Advance at most a few migrations per HTTP request on shared hosting.
     *
     * @param  array{hospital_name:string,owner_name:string,owner_email:string,owner_password:string,currency:string,timezone:string}  $details
     * @return array{done:bool,completed:int,total:int}
     */
    public function advance(array $details, int $batch = 5): array
    {
        $this->checkIdentity();
        if (! Schema::hasTable('migrations') && Artisan::call('migrate:install') !== 0) {
            throw new \RuntimeException('The migration ledger could not be created.');
        }
        $files = glob(base_path('database/migrations/*.php')) ?: [];
        sort($files, SORT_STRING);
        $ran = DB::table('migrations')->pluck('migration')->all();
        $pending = array_values(array_filter($files, static fn (string $path): bool => ! in_array(pathinfo($path, PATHINFO_FILENAME), $ran, true)));
        foreach (array_slice($pending, 0, max(1, min($batch, 8))) as $path) {
            if (Artisan::call('migrate', ['--path' => 'database/migrations/'.basename($path), '--force' => true]) !== 0) {
                throw new \RuntimeException('A database migration failed: '.basename($path));
            }
        }
        $completed = count(DB::table('migrations')->pluck('migration')->all());
        if ($completed < count($files)) {
            return ['done' => false, 'completed' => $completed, 'total' => count($files)];
        }

        $this->complete($details);

        return ['done' => true, 'completed' => count($files), 'total' => count($files)];
    }

    private function checkIdentity(): void
    {
        if (! Edition::isStandalone()) {
            throw new \DomainException('The installer is only available in the standalone edition.');
        }
        if (is_file($this->marker())) {
            throw new \DomainException('This installation is already complete.');
        }
        $licence = app(InstalledLicence::class);
        if (! $licence->isValid()) {
            throw new \DomainException('Upload a valid standalone licence before installing.');
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($host) || ! $licence->matchesHost($host)) {
            throw new \DomainException('The licence does not cover this installation domain.');
        }
        $releaseFile = base_path('RELEASE.json');
        if (is_file($releaseFile)) {
            $release = json_decode((string) file_get_contents($releaseFile), true);
            $builtAt = is_array($release) ? ($release['built_at'] ?? null) : null;
            $until = $licence->updatesUntil();
            if (! is_string($builtAt) || $until === null
                || \Illuminate\Support\Carbon::parse($builtAt)->greaterThan($until->endOfDay())) {
                throw new \DomainException('This release was built after the licence update entitlement ended. Download an entitled release from the buyer portal.');
            }
        }
    }

    /** @param array{hospital_name:string,owner_name:string,owner_email:string,owner_password:string,currency:string,timezone:string} $details */
    private function complete(array $details): User
    {
        app(SingleEnterpriseGuard::class)->install();
        if (Artisan::call('db:seed', [
            '--class' => StandaloneReferenceSeeder::class,
            '--force' => true,
        ]) !== 0) {
            throw new \RuntimeException('Reference data seeding failed.');
        }

        $owner = DB::transaction(function () use ($details): User {
            $existing = Hospital::withTrashed()->first();
            if ($existing !== null) {
                $owner = User::where('hospital_id', $existing->id)
                    ->where('email', mb_strtolower($details['owner_email']))->first();
                if ($existing->trashed() || Hospital::withTrashed()->count() !== 1 || $owner === null) {
                    throw new \DomainException('This database already contains another organisation.');
                }

                return $owner;
            }

            $hospital = Hospital::create([
                'name' => $details['hospital_name'],
                'currency' => $details['currency'],
                'timezone' => $details['timezone'],
                'status' => 'active',
            ]);

            return User::create([
                'hospital_id' => $hospital->id,
                'name' => $details['owner_name'],
                'email' => mb_strtolower($details['owner_email']),
                'password' => $details['owner_password'],
                'role' => 'hospital_admin',
                'is_active' => true,
                'email_verified_at' => now(),
                'password_change_required' => false,
            ]);
        });

        // The common chart is available on day one. Specialty accounts are
        // added by the existing practice wizard when the owner chooses packs.
        app(CurrentHospital::class)->within($owner->hospital_id, function (): void {
            app(ChartOfAccounts::class)->ensure();
        });

        $marker = $this->marker();
        File::ensureDirectoryExists(dirname($marker), 0700);
        $temporary = $marker.'.'.Str::random(12).'.tmp';
        File::put($temporary, json_encode([
            'edition' => Edition::STANDALONE,
            'version' => Edition::version(),
            'hospital_id' => $owner->hospital_id,
            'licence_id' => app(InstalledLicence::class)->id(),
            'installed_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($temporary, 0600);
        rename($temporary, $marker);

        return $owner;
    }

    private function marker(): string
    {
        $path = config('edition.installed_path');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('The installation marker path is not configured.');
        }

        return $path;
    }
}
