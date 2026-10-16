<?php

namespace App\Support\Insights\Rules;

use App\Models\InsuranceProvider;
use App\Models\PatientCard;
use App\Support\HospitalSettings;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** An insurer's float is below what its members owe on their cards: the float cannot clear the debt. */
final class InsurerFloatShort extends Rule
{
    public function key(): string
    {
        return 'insurer-float-short';
    }

    protected function permission(): string
    {
        return 'insurance.manage';
    }

    protected function module(): string
    {
        return 'insurance';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        // Two queries for every insurer: what members owe, then the floats.
        $owed = PatientCard::query()
            ->whereNotNull('insurance_provider_id')
            ->where('balance', '<', 0)
            ->selectRaw('insurance_provider_id, sum(-balance) as owed')
            ->groupBy('insurance_provider_id')
            ->pluck('owed', 'insurance_provider_id');

        if ($owed->isEmpty()) {
            return null;
        }

        $floats = InsuranceProvider::query()->where('is_active', true)->whereIn('id', $owed->keys())->pluck('float_balance', 'id');

        $short = '0.00';
        $count = 0;
        foreach ($floats as $id => $float) {
            $gap = bcsub(number_format((float) $owed[$id], 2, '.', ''), (string) $float, 2);
            if (bccomp($gap, '0', 2) > 0) {
                $count++;
                $short = bcadd($short, $gap, 2);
            }
        }

        if ($count === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($count, 'insurer').' short on float',
            'Members owe '.HospitalSettings::money($short).' more than the float held. Ask for a top-up.',
            'admin.insurance-providers.index', 'See insurers', $count, 58,
        );
    }
}
