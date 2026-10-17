<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use App\Support\CaseSheet\DentalCaseSheet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An X-ray taken at the chair: what kind, of which teeth, and what it showed.
 * Never edited. The order beside it (`order_id`, an imaging order on the
 * visit) carries its charge and its image, so it is billed and filed the way
 * every other piece of work is. Written only by DentalCaseSheetService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospital_id
 * @property int $patient_id
 * @property int $visit_id
 * @property string $kind
 * @property list<int>|null $teeth
 * @property string $finding
 * @property int|null $service_id
 * @property int|null $order_id
 * @property int|null $recorded_by
 * @property \Illuminate\Support\Carbon $taken_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class DentalXray extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'patient_id', 'visit_id', 'kind', 'teeth', 'finding', 'service_id', 'order_id', 'recorded_by', 'taken_at'];

    protected function casts(): array
    {
        return ['teeth' => 'array', 'taken_at' => 'datetime'];
    }

    public function kindLabel(): string
    {
        return DentalCaseSheet::XRAY_KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    /** "Periapical X-ray, 35 36" */
    public function title(): string
    {
        $teeth = $this->teeth ? ', '.implode(' ', $this->teeth) : '';

        return $this->kindLabel().' X-ray'.$teeth;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
