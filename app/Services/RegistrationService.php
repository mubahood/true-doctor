<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Public self-registration: create a hospital, its owner (hospital_admin) and a
 * trial subscription on the chosen plan — all in one transaction. The trial
 * grants access until trial_ends_at; the owner pays to convert it to an active
 * paid period (SubscriptionCheckoutService).
 */
class RegistrationService
{
    private const TRIAL_DAYS = 14;

    /** @param array{hospital_name:string,name:string,email:string,password:string,plan_id:int} $data */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $plan = Plan::findOrFail($data['plan_id']);

            $hospital = Hospital::create([
                'name' => $data['hospital_name'],
                'currency' => 'UGX',
                'status' => 'active',
            ]);

            $owner = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => 'hospital_admin',
                'hospital_id' => $hospital->id,
                'is_active' => true,
                'email_verified_at' => Carbon::now(),
                'password_change_required' => false,
            ]);
            $owner->syncSpatieRole();

            $trialEnds = Carbon::now()->addDays(self::TRIAL_DAYS);
            $subscription = Subscription::create([
                'hospital_id' => $hospital->id,
                'plan_id' => $plan->id,
                'starts_at' => Carbon::now(),
                'ends_at' => $trialEnds,
                'trial_ends_at' => $trialEnds,
                'status' => SubscriptionStatus::Trialing,
            ]);

            $owner->notify(new \App\Notifications\WelcomeToTrial($subscription));

            // Which advertisement brought them. Inside the transaction so a
            // hospital is never created without the answer, and wrapped in
            // the recorder so a reporting failure can never lose a sign-up.
            app(TrafficRecorder::class)->convert($hospital, request());

            return $owner;
        });
    }
}
