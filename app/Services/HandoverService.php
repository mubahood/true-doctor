<?php

namespace App\Services;

use App\Enums\AdmissionStatus;
use App\Models\Admission;
use App\Models\HandoverNote;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Records a nursing handover and its acknowledgement by the incoming shift. */
class HandoverService
{
    public const SHIFTS = ['morning', 'afternoon', 'evening', 'night'];

    /**
     * @param  list<array{admission_uuid:string,situation:?string,background:?string,assessment:?string,recommendation:?string}>  $notes
     */
    public function store(User $author, Ward $ward, string $shift, array $notes): HandoverNote
    {
        $admissions = Admission::query()->with(['patient:id,uuid,first_name,last_name,patient_no', 'bed'])
            ->where('status', AdmissionStatus::Admitted->value)
            ->whereIn('uuid', array_column($notes, 'admission_uuid'))
            ->whereHas('bed', fn ($b) => $b->where('ward_id', $ward->id))
            ->get()->keyBy('uuid');

        $rows = [];
        foreach ($notes as $i => $n) {
            $a = $admissions->get($n['admission_uuid']);
            if ($a === null) {
                throw new RuntimeException("notes.{$i}.admission_uuid is not an admitted patient of this ward.");
            }
            $rows[] = [
                'admission_uuid' => $a->uuid,
                'patient' => ['uuid' => $a->patient?->uuid, 'name' => $a->patient?->full_name, 'patient_no' => $a->patient?->patient_no],
                'bed' => $a->bed?->name,
                'situation' => $n['situation'] ?? null,
                'background' => $n['background'] ?? null,
                'assessment' => $n['assessment'] ?? null,
                'recommendation' => $n['recommendation'] ?? null,
            ];
        }

        return DB::transaction(fn () => HandoverNote::create([
            'uuid' => (string) Str::uuid(),
            'ward_id' => $ward->id,
            'shift' => $shift,
            'author_id' => $author->id,
            'notes' => $rows,
        ]));
    }

    /** The incoming nurse takes the handover. The author cannot acknowledge their own. */
    public function acknowledge(HandoverNote $note, User $by): HandoverNote
    {
        return DB::transaction(function () use ($note, $by) {
            $fresh = HandoverNote::query()->lockForUpdate()->findOrFail($note->id);
            if ($fresh->acknowledged_at !== null) {
                return $fresh; // already taken: a retry is not an error
            }
            if ($fresh->author_id === $by->id) {
                throw new RuntimeException('A handover is acknowledged by the person taking over, not the one who wrote it.');
            }
            $fresh->forceFill(['acknowledged_by' => $by->id, 'acknowledged_at' => now()])->save();

            return $fresh;
        });
    }

    /** @return array<string,mixed> */
    public function present(HandoverNote $h): array
    {
        $h->loadMissing(['ward:id,name', 'author:id,name', 'acknowledgedBy:id,name']);

        return [
            'uuid' => $h->uuid,
            'ward' => ['id' => $h->ward_id, 'name' => $h->ward?->name],
            'shift' => $h->shift,
            'author' => $h->author?->name,
            'created_at' => $h->created_at?->toIso8601String(),
            'acknowledged_by' => $h->acknowledgedBy?->name,
            'acknowledged_at' => $h->acknowledged_at?->toIso8601String(),
            'notes' => $h->notes,
        ];
    }
}
