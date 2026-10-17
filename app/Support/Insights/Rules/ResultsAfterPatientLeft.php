<?php

namespace App\Support\Insights\Rules;

use App\Enums\ResultFlag;
use App\Models\LabOrderItem;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/**
 * Clinical follow-up: an abnormal lab result that came back after the visit
 * had closed, in the last 7 days. The patient has gone home and nobody in the
 * room read it; somebody has to call them. A doctor sees their own patients'.
 */
final class ResultsAfterPatientLeft extends Rule
{
    public const DAYS = 7;

    public function key(): string
    {
        return 'results-after-patient-left';
    }

    protected function permission(): string
    {
        return 'visits.diagnose';
    }

    protected function module(): string
    {
        return 'laboratory';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = LabOrderItem::query()
            ->join('lab_orders', 'lab_orders.id', '=', 'lab_order_items.lab_order_id')
            ->join('visits', 'visits.id', '=', 'lab_orders.visit_id')
            ->whereIn('lab_order_items.result_flag', [ResultFlag::Low->value, ResultFlag::High->value, ResultFlag::Abnormal->value])
            ->where('lab_order_items.resulted_at', '>=', $c->now->copy()->subDays(self::DAYS))
            ->whereNotNull('visits.completed_at')
            ->whereColumn('lab_order_items.resulted_at', '>', 'visits.completed_at')
            ->when($c->user->role === 'doctor', fn ($q) => $q->where('visits.doctor_user_id', $c->user->id))
            ->distinct()->count('lab_order_items.lab_order_id');

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'danger',
            self::plural($n, 'abnormal result').' came back after the patient left',
            'Nobody saw these in the room. Read them and call the patients who need to come back.',
            'admin.lab-orders.index', 'Open the lab results', $n, 84,
        );
    }
}
