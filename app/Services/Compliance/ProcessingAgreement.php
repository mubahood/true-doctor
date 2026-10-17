<?php

namespace App\Services\Compliance;

use App\Models\Hospital;
use App\Models\User;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;

/**
 * The data processing agreement on the online service: which version this
 * clinic accepted, when and by whom. Every acceptance is kept, newest last,
 * so a later version does not erase the record of the earlier one.
 */
class ProcessingAgreement
{
    /** @return array{version:string,accepted_at:string,user_id:int,name:string}|null the newest acceptance */
    public function latest(Hospital $hospital): ?array
    {
        $all = (array) ($hospital->settings['data_protection']['dpa'] ?? []);
        $last = end($all);

        return is_array($last) ? $last : null;
    }

    /** Accepted, and the version accepted is the current one. */
    public function isCurrent(Hospital $hospital): bool
    {
        return ($this->latest($hospital)['version'] ?? null) === (string) config('compliance.dpa_version');
    }

    public function accept(Hospital $hospital, User $by): void
    {
        abort_unless(Edition::isSaas(), 404);

        DB::transaction(function () use ($hospital, $by): void {
            $locked = Hospital::query()->lockForUpdate()->findOrFail($hospital->id);
            if ($this->isCurrent($locked)) {
                return;
            }
            $settings = is_array($locked->settings) ? $locked->settings : [];
            $settings['data_protection']['dpa'][] = [
                'version' => (string) config('compliance.dpa_version'),
                'accepted_at' => now()->toIso8601String(),
                'user_id' => $by->id,
                'name' => (string) $by->name,
            ];
            $locked->forceFill(['settings' => $settings])->save();
            $hospital->setRawAttributes($locked->getAttributes(), true);

            activity()->causedBy($by)->withProperties(['version' => config('compliance.dpa_version')])
                ->tap(fn ($activity) => $activity->setAttribute('hospital_id', $locked->id))
                ->log('data processing agreement accepted');
        });
    }
}
