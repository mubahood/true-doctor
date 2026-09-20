<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the Super Admin account. The retired registry demo accounts
 * (registrar/verifier/auditor/institution_admin/data_clerk) moved with the
 * registry domain; Phase 0 Step 5 seeds demo accounts per real HMS role.
 *
 * Constraint C14: no predictable default password. A random temporary
 * password is generated and printed once, with password_change_required
 * forcing a reset at first login (enforced by
 * App\Http\Middleware\RequirePasswordChange). Re-running the seeder never
 * touches an existing account's password.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'admin@gmail.com')->first();

        if ($user !== null) {
            $this->command->info('AdminUserSeeder: Super Admin already exists — password left untouched.');
            $user->syncSpatieRole();

            return;
        }

        $password = Str::password(16);

        $user = User::create([
            'name' => 'Super Admin',
            'username' => 'super_admin',
            'email' => 'admin@gmail.com',
            'password' => Hash::make($password),
            'role' => 'super_admin',
            'is_admin' => true,
            'is_active' => true,
            'password_change_required' => true,
        ]);

        // Seeders run WithoutModelEvents, so the saved-hook role sync never
        // fires — assign the Spatie role explicitly here.
        $user->syncSpatieRole();

        $this->command->warn("AdminUserSeeder: Super Admin created — admin@gmail.com / {$password}");
        $this->command->warn('AdminUserSeeder: temporary password, change required at first login.');
    }
}
