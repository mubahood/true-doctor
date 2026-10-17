<?php

namespace App\Services\Audit;

use App\Models;
use App\Models\AuditEntry;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The write audit (roadmap 07 §3, docs/audit.md).
 *
 * Every clinical and money model is observed (AuditObserver, registered in
 * AppServiceProvider so a model cannot forget to be). Each create, change,
 * delete and restore becomes one append-only AuditEntry: who (the signed-in
 * user, on web or API), when, through what (web, api, system), and, field by
 * field, the value before and after.
 *
 * Values are the stored ones, read straight off the model without another
 * query. Passwords, tokens and every encrypted column are masked: the trail
 * shows THAT a confidential note changed, never what it says, or the audit
 * would be the easiest place to read it. Bookkeeping columns (timestamps, the
 * sync counters) are left out, and a save that changed only those writes
 * nothing.
 */
final class AuditTrail
{
    public const MASK = '[hidden]';

    /** Long text is cut here; the record itself holds the rest. */
    private const MAX_VALUE = 500;

    /**
     * What is audited, with the name the screen shows for it.
     *
     * @var array<class-string<Model>, string>
     */
    public const MODELS = [
        // Clinical
        Models\Patient::class => 'Patient',
        Models\Visit::class => 'Visit',
        Models\VisitDiagnosis::class => 'Diagnosis',
        Models\Order::class => 'Order',
        Models\OrderItem::class => 'Order item',
        Models\LabOrder::class => 'Lab order',
        Models\LabOrderItem::class => 'Lab result',
        Models\RadiologyOrder::class => 'Radiology order',
        Models\RadiologyOrderItem::class => 'Radiology report',
        Models\Prescription::class => 'Prescription',
        Models\DoseItem::class => 'Prescription item',
        Models\Dispensation::class => 'Dispensation',
        Models\DispensationItem::class => 'Dispensed item',
        Models\MedicationAdministration::class => 'Dose given',
        Models\Admission::class => 'Admission',
        Models\BedTransfer::class => 'Bed transfer',
        Models\Bed::class => 'Bed',
        Models\NursingNote::class => 'Nursing note',
        Models\VitalRound::class => 'Vitals round',
        Models\TreatmentRecord::class => 'Treatment record',
        Models\TreatmentRecordItem::class => 'Treatment item',
        Models\DentalChartEntry::class => 'Dental finding',
        Models\DentalTreatmentItem::class => 'Dental plan item',
        // Money
        Models\Invoice::class => 'Invoice',
        Models\InvoiceItem::class => 'Invoice line',
        Models\Payment::class => 'Payment',
        Models\InvoiceCredit::class => 'Credit note or refund',
        Models\PatientCard::class => 'Card',
        Models\CardRecord::class => 'Card transaction',
        Models\InsuranceClaim::class => 'Insurance claim',
        Models\InsuranceTransaction::class => 'Insurance transaction',
        Models\PatientInsurance::class => 'Patient insurance',
        Models\StockMovement::class => 'Stock movement',
        Models\StockItem::class => 'Stock item',
        Models\Service::class => 'Price list item',
        Models\LabTest::class => 'Lab test',
        Models\RadiologyStudy::class => 'Radiology study',
        // People
        Models\User::class => 'User account',
    ];

    public const EVENTS = [
        'created' => 'Created',
        'updated' => 'Changed',
        'deleted' => 'Deleted',
    ];

    /** Bookkeeping, not a change anybody made. */
    private const IGNORED = [
        'created_at', 'updated_at', 'sync_revision', 'version', 'origin_device_id', 'origin_operation_id',
        'client_created_at', 'last_active_at', 'two_factor_last_step', 'remember_token',
    ];

    /** Never written into the trail in clear, whatever the cast says. */
    private const SECRET = [
        'password', 'two_factor_secret', 'two_factor_recovery_codes', 'bank_details', 'card_number', 'card_number_hash',
        'pin', 'pin_hash', 'token', 'api_token', 'share_nonce',
    ];

    /** Record one write. Called by AuditObserver. */
    public function record(Model $model, string $event): void
    {
        if (! isset(self::MODELS[$model::class]) || ! config('activitylog.enabled', true)) {
            return;
        }

        [$new, $old] = match ($event) {
            'created' => [$this->clean($model, $model->getAttributes()), []],
            'updated' => $this->changes($model),
            'deleted' => [[], $this->clean($model, $model->getAttributes())],
            default => [[], []],
        };

        if ($new === [] && $old === []) {
            return;
        }

        // Only a guard the request already resolved: resolving one here would
        // fix its user before the request's own authentication ran.
        $user = Auth::hasResolvedGuards() ? Auth::user() : null;
        $request = request();

        $entry = new AuditEntry;
        $entry->forceFill([
            // A platform account (no hospital) is never filed under the
            // hospital its owner happens to be looking at.
            'hospital_id' => $model instanceof Models\User ? $model->hospital_id : ($model->getAttribute('hospital_id') ?? app(CurrentHospital::class)->id()),
            'log_name' => self::logName($model::class),
            'description' => $event,
            'event' => $event,
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'causer_type' => $user instanceof Model ? $user->getMorphClass() : null,
            'causer_id' => $user instanceof Model ? $user->getKey() : null,
            'properties' => collect(array_filter([
                'attributes' => $new,
                'old' => $event === 'updated' || $event === 'deleted' ? $old : null,
                'via' => self::via(),
                'ip' => $request->route() !== null ? $request->ip() : null,
            ], fn ($v) => $v !== null)),
        ])->save();
    }

    /** The log name a model's entries are filed under (`patient`, `lab_order_item`). */
    public static function logName(string $class): string
    {
        return Str::snake(class_basename($class));
    }

    /** @return array<string,string> log name => label, for the record-type filter */
    public static function types(): array
    {
        $out = [];
        foreach (self::MODELS as $class => $label) {
            $out[self::logName($class)] = $label;
        }
        asort($out);

        return $out;
    }

    public static function typeLabel(?string $logName): string
    {
        return self::types()[$logName ?? ''] ?? Str::headline((string) $logName);
    }

    /**
     * The trail, newest first, narrowed by the screen's filters.
     *
     * @param  array{type?:string,event?:string,user?:int|null,from?:string,to?:string,search?:string}  $filters
     * @return Builder<AuditEntry>
     */
    public function query(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return AuditEntry::query()
            ->with('user:id,name')
            ->when(($filters['type'] ?? '') !== '', fn (Builder $q) => $q->where('log_name', $filters['type']))
            ->when(($filters['event'] ?? '') !== '', fn (Builder $q) => $q->where('event', $filters['event']))
            ->when(! empty($filters['user']), fn (Builder $q) => $q->where('causer_type', (new Models\User)->getMorphClass())->where('causer_id', $filters['user']))
            ->when(($filters['from'] ?? '') !== '', fn (Builder $q) => $q->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(($filters['to'] ?? '') !== '', fn (Builder $q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'))
            // A record's number is what people search for ("which changes
            // touched record 1042?"); free text over JSON is not.
            ->when($search !== '' && ctype_digit($search), fn (Builder $q) => $q->where('subject_id', (int) $search))
            ->when($search !== '' && ! ctype_digit($search), fn (Builder $q) => $q->where('description', 'like', "%{$search}%"))
            ->latest('id');
    }

    /**
     * One entry's fields as rows for a table: field, before, after.
     *
     * @return list<array{field:string,old:string,new:string}>
     */
    public static function rows(AuditEntry $entry): array
    {
        $new = $entry->newValues();
        $old = $entry->oldValues();
        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $rows[] = [
                'field' => Str::headline((string) $field),
                'old' => self::show($old[$field] ?? null),
                'new' => self::show($new[$field] ?? null),
            ];
        }

        return $rows;
    }

    /** A line for the list: "Status, Paid amount and 2 more". */
    public static function summary(AuditEntry $entry): string
    {
        $fields = array_values(array_unique([...array_keys($entry->oldValues()), ...array_keys($entry->newValues())]));
        if ($fields === []) {
            return '';
        }
        $named = array_map(fn ($f) => Str::headline((string) $f), array_slice($fields, 0, 3));
        $more = count($fields) - count($named);

        return implode(', ', $named).($more > 0 ? " and {$more} more" : '');
    }

    private static function show(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }

        return (string) $value;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function changes(Model $model): array
    {
        $new = $this->clean($model, $model->getChanges());
        $old = [];
        foreach (array_keys($new) as $key) {
            $old[$key] = $this->value($model, $key, $model->getRawOriginal($key));
        }

        return [$new, $old];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function clean(Model $model, array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            if (in_array($key, self::IGNORED, true) || $key === $model->getKeyName()) {
                continue;
            }
            $out[$key] = $this->value($model, $key, $value);
        }

        return $out;
    }

    private function value(Model $model, string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $cast = (string) ($model->getCasts()[$key] ?? '');
        if (in_array($key, self::SECRET, true) || str_starts_with($cast, 'encrypted') || $cast === 'hashed') {
            return self::MASK;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE) {
            return mb_substr($value, 0, self::MAX_VALUE).'…';
        }

        return is_scalar($value) ? $value : (string) json_encode($value);
    }

    private static function via(): string
    {
        $request = request();
        if ($request->route() === null) {
            return 'system';
        }

        return $request->is('api/*') ? 'api' : 'web';
    }
}
