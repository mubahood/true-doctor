<?php

namespace App\Services\Sync;

use App\Enums\AdmissionStatus;
use App\Enums\LabOrderStatus;
use App\Enums\VisitStatus;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Device;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\MedicationAdministration;
use App\Models\NursingNote;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\VitalRound;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the server has that a device does not.
 *
 * Changes come back in revision order, in pages, and the device advances its
 * cursor ONLY after it has committed the page locally (invariant I-4). The
 * reverse order — advance, then save — loses the whole page on a crash,
 * permanently, because the server will never send it again.
 *
 * The scope is deliberately narrow. A device does not get the hospital; it
 * gets what this shift touches (plan §15): recent patients, their open visits,
 * current inpatients and their charts, and the reference data needed to fill a
 * form. Everything else stays on the server, where it is one page-load away
 * for anyone who is online.
 */
class PullService
{
    /** A page. Small enough to commit in one local transaction on a phone. */
    public const PAGE = 200;

    /** How far back "recent" reaches for patients, in days. */
    public const PATIENT_WINDOW_DAYS = 30;

    /** How far back closed visits stay interesting. */
    public const VISIT_WINDOW_DAYS = 7;

    /**
     * The window a hospital has actually configured.
     *
     * A rural clinic with daily outages needs a wider one than an urban
     * hospital with occasional flaps; the constants above are the defaults
     * and the safer thing to be wrong about (plan §15).
     */
    private function patientDays(): int
    {
        return (int) config('offline.window.patient_days', self::PATIENT_WINDOW_DAYS);
    }

    private function visitDays(): int
    {
        return (int) config('offline.window.visit_days', self::VISIT_WINDOW_DAYS);
    }

    private function pageSize(): int
    {
        return (int) config('offline.window.page_size', self::PAGE);
    }

    /**
     * One page of changes, oldest revision first.
     *
     * @return array{changes: array<int,array<string,mixed>>, next_cursor: string, has_more: bool, current_revision: int}
     */
    public function page(PullCursor $cursor, User $actor, Device $device): array
    {
        $rows = [];

        foreach ($this->streams($actor) as $entity => $stream) {
            /** @var Builder<Model> $query */
            $query = $stream['query'];

            $found = $query
                ->where('sync_revision', '>', $cursor->revision)
                ->orderBy('sync_revision')
                ->limit($this->pageSize() + 1)
                ->get();

            foreach ($found as $model) {
                $rows[] = [
                    'entity' => $entity,
                    // Read off the attribute bag: the loop is deliberately
                    // generic over six models and a typed property would mean
                    // six near-identical loops.
                    'revision' => (int) $model->getAttribute('sync_revision'),
                    'record' => $stream['shape']($model),
                ];
            }
        }

        // Interleave the streams by revision, so a page is a slice of the
        // hospital's history rather than all of one table then all of another.
        // Without this, a cursor advanced past a big patients page would skip
        // every visit written in the same window.
        usort($rows, fn ($a, $b) => $a['revision'] <=> $b['revision']);

        $hasMore = count($rows) > $this->pageSize();
        $page = array_slice($rows, 0, $this->pageSize());

        $highest = $page === [] ? $cursor->revision : (int) end($page)['revision'];

        return [
            'changes' => $page,
            'next_cursor' => $cursor->advancedTo($highest)->encode(),
            'has_more' => $hasMore,
            'current_revision' => \App\Support\SyncRevision::current(),
        ];
    }

    /**
     * How many records of each kind this user's device would receive.
     *
     * For the readiness screen, so somebody can see the size of the download
     * before starting it — on a phone tether that is the difference between a
     * considered decision and a surprise. It counts the SAME permission-scoped
     * streams a real pull would send, so it cannot drift from the truth by
     * being a separate estimate.
     *
     * The counts are of rows, not bytes: a row count is a thing a person can
     * sanity-check against the ward they work on, and a megabyte figure is not.
     *
     * @return array<string,int>
     */
    public function availableFor(User $actor): array
    {
        $counts = [];

        foreach ($this->streams($actor) as $entity => $stream) {
            $counts[$entity] = (clone $stream['query'])->count();
        }

        return $counts;
    }

    /**
     * The streams a device may read, each already narrowed to its window.
     *
     * Scoped by the tenant global scope AND by permission: a receptionist's
     * device has no business holding inpatient charts, and the smallest
     * defensible copy of PHI is the one that is not there (plan §15, §17).
     *
     * @return array<string, array{query: Builder<Model>, shape: callable(Model): array<string,mixed>}>
     */
    private function streams(User $actor): array
    {
        $streams = [];

        if ($actor->can('patients.view')) {
            $streams['patients'] = [
                // Seen lately, or currently being seen. `Patient` has no
                // `visits` relation, so the open-visit side is a subquery on
                // the visit table rather than a relation that does not exist.
                'query' => Patient::withTrashed()
                    ->where(fn (Builder $q) => $q
                        ->where('updated_at', '>=', now()->subDays($this->patientDays()))
                        ->orWhereIn('id', Visit::query()
                            ->where('status', '!=', VisitStatus::Completed)
                            ->select('patient_id'))),
                'shape' => fn (Patient $p) => [
                    'uuid' => $p->uuid,
                    'server_id' => $p->id,
                    'version' => (int) ($p->version ?? 1),
                    'patient_no' => $p->patient_no,
                    'first_name' => $p->first_name,
                    'last_name' => $p->last_name,
                    'search_key' => mb_strtolower(trim($p->first_name.' '.$p->last_name.' '.$p->patient_no)),
                    'dob' => $p->dob?->format('Y-m-d'),
                    'sex' => $p->sex?->value,
                    'phone_1' => $p->phone_1,
                    'phone_2' => $p->phone_2,
                    'address' => $p->address,
                    'blood_type' => $p->blood_type,
                    'allergies' => $p->allergies,
                    'chronic_conditions' => $p->chronic_conditions,
                    'emergency_contact_name' => $p->emergency_contact_name,
                    'emergency_contact_phone' => $p->emergency_contact_phone,
                    // Every field a device may edit travels, so it holds the
                    // base a three-way merge needs: a field sent without a
                    // base is treated as contested, and an offline edit to an
                    // email or a next of kin raised a conflict nobody caused.
                    'email' => $p->email,
                    'home_address' => $p->home_address,
                    'district_id' => $p->district_id,
                    'spouse_name' => $p->spouse_name,
                    'father_name' => $p->father_name,
                    'mother_name' => $p->mother_name,
                    'notes' => $p->notes,
                    'insurance_provider' => $p->insurance_provider,
                    'insurance_member_no' => $p->insurance_member_no,
                    'consent_given' => (bool) $p->consent_given,
                    'status' => $p->status->value,
                    'created_at' => $p->created_at->toIso8601String(),
                    'updated_at' => $p->updated_at?->toIso8601String(),
                    // A tombstone, so a device learns a record has gone rather
                    // than keeping it for ever (plan §10.2).
                    'deleted_at' => $p->deleted_at?->toIso8601String(),
                ],
            ];
        }

        if ($actor->can('visits.view')) {
            // The clinical narrative travels only to somebody who could write
            // one. A receptionist needs to know a visit is open and whose it
            // is; they do not need the diagnosis on their device, and the
            // smallest defensible copy is the one that is not there.
            //
            // It is not optional for a doctor, though: without the narrative
            // the device has no confirmed base for those fields, so EVERY note
            // written offline would come back contested — the three-way merge
            // has nothing to be a third side.
            $narrative = $actor->can('visits.diagnose');

            $streams['visits'] = [
                // Still live, or closed recently enough that somebody may still
                // be finishing it off. There is no `closed_at` column — a visit
                // is done when its status says so.
                'query' => Visit::query()
                    ->where(fn (Builder $q) => $q
                        ->where('status', '!=', VisitStatus::Completed)
                        ->orWhere('updated_at', '>=', now()->subDays($this->visitDays())))
                    ->with('patient:id,uuid'),
                'shape' => fn (Visit $v) => [
                    'uuid' => $v->uuid,
                    'server_id' => $v->id,
                    'version' => (int) ($v->version ?? 1),
                    'visit_no' => $v->visit_no,
                    'patient_uuid' => $v->patient?->uuid,
                    'status' => $v->status->value,
                    'stage' => $v->stage->value,
                    ...($narrative ? [
                        'complaints' => $v->complaints,
                        'diagnosis' => $v->diagnosis,
                        'doctor_remarks' => $v->doctor_remarks,
                    ] : []),
                    'created_at' => $v->created_at->toIso8601String(),
                    'updated_at' => $v->updated_at->toIso8601String(),
                    'deleted_at' => null,
                ],
            ];
        }

        if ($actor->can('ipd.view')) {
            $streams['admissions'] = [
                'query' => Admission::query()->with(['patient:id,uuid', 'bed:id,name,ward_id']),
                'shape' => fn (Admission $a) => [
                    'uuid' => $a->uuid,
                    'server_id' => $a->id,
                    'version' => 1,
                    'patient_uuid' => $a->patient?->uuid,
                    'bed_id' => $a->bed_id,
                    'bed_name' => $a->bed?->name,
                    'status' => $a->status->value,
                    'admitted_at' => $a->admitted_at->toIso8601String(),
                    'discharged_at' => $a->discharged_at?->toIso8601String(),
                    'reason' => $a->reason,
                    'created_at' => $a->created_at?->toIso8601String(),
                    'updated_at' => $a->updated_at?->toIso8601String(),
                    'deleted_at' => null,
                ],
            ];

            // The charts of people who are still here. A discharged patient's
            // observations are history and belong on the server.
            $liveAdmissionIds = fn () => Admission::query()
                ->where('status', AdmissionStatus::Admitted)
                ->select('id');

            $streams['vitals'] = [
                'query' => VitalRound::query()->whereIn('admission_id', $liveAdmissionIds())->with('admission:id,uuid'),
                'shape' => fn (VitalRound $r) => [
                    'uuid' => $r->uuid,
                    'server_id' => $r->id,
                    'version' => 1,
                    'admission_uuid' => $r->admission?->uuid,
                    'temperature' => $r->temperature,
                    'pulse' => $r->pulse,
                    'blood_pressure' => $r->blood_pressure,
                    'respiratory_rate' => $r->respiratory_rate,
                    'spo2' => $r->spo2,
                    'note' => $r->note,
                    'created_at' => $r->created_at?->toIso8601String(),
                    'updated_at' => $r->created_at?->toIso8601String(),
                    'deleted_at' => null,
                ],
            ];

            $streams['nursing_notes'] = [
                'query' => NursingNote::query()->whereIn('admission_id', $liveAdmissionIds())->with('admission:id,uuid'),
                'shape' => fn (NursingNote $n) => [
                    'uuid' => $n->uuid,
                    'server_id' => $n->id,
                    'version' => 1,
                    'admission_uuid' => $n->admission?->uuid,
                    'note' => $n->note,
                    'created_at' => $n->created_at?->toIso8601String(),
                    'updated_at' => $n->created_at?->toIso8601String(),
                    'deleted_at' => null,
                ],
            ];

            $streams['med_administrations'] = [
                'query' => MedicationAdministration::query()->whereIn('admission_id', $liveAdmissionIds())->with('admission:id,uuid'),
                'shape' => fn (MedicationAdministration $m) => [
                    'uuid' => $m->uuid,
                    'server_id' => $m->id,
                    'version' => 1,
                    'admission_uuid' => $m->admission?->uuid,
                    'drug_name' => $m->drug_name,
                    'dose' => $m->dose,
                    'route' => $m->route,
                    'status' => $m->status->value,
                    'note' => $m->note,
                    'created_at' => $m->created_at?->toIso8601String(),
                    'updated_at' => $m->created_at?->toIso8601String(),
                    'deleted_at' => null,
                ],
            ];
        }

        if ($actor->can('lab.view')) {
            // The bench worklist: tests that have been ordered and not yet
            // reported. A resulted one is history and belongs on the server —
            // and a device holding every result ever produced is a device
            // holding far more patient data than the work needs (plan §15).
            $openOrders = fn () => LabOrder::query()
                ->whereNotIn('status', [LabOrderStatus::Completed, LabOrderStatus::Cancelled])
                ->select('id');

            $streams['lab_items'] = [
                'query' => LabOrderItem::query()
                    ->whereIn('lab_order_id', $openOrders())
                    ->with(['order:id,uuid,visit_id,patient_id,status', 'order.patient:id,uuid']),
                'shape' => fn (LabOrderItem $i) => [
                    'uuid' => $i->uuid,
                    'server_id' => $i->id,
                    'version' => (int) ($i->version ?? 1),
                    'lab_order_uuid' => $i->order?->uuid,
                    'patient_uuid' => $i->order?->patient?->uuid,
                    'name' => $i->name,
                    'unit' => $i->unit,
                    'reference_range' => $i->reference_range,
                    'result_value' => $i->result_value,
                    'result_flag' => $i->result_flag?->value,
                    'result_notes' => $i->result_notes,
                    'resulted_at' => $i->resulted_at?->toIso8601String(),
                    'status' => $i->order?->status->value,
                    // Deliberately NOT `price`. A bench does not need to know
                    // what a test costs, and money never reaches a device.
                    'created_at' => $i->created_at?->toIso8601String(),
                    'updated_at' => $i->updated_at?->toIso8601String(),
                    'deleted_at' => null,
                ],
            ];
        }

        return $streams;
    }

    /**
     * Reference data — catalogues the device reads and never edits.
     *
     * Versioned separately from the change stream because it behaves nothing
     * like it: a formulary of three thousand medications is downloaded once
     * and then changes twice a year. Sending it through the cursor would put
     * it in front of every clinical change on first sync.
     *
     * @return array<string, array<int, array<string,mixed>>>
     */
    public function reference(User $actor, int $sinceVersion = 0): array
    {
        $out = [];

        $out['wards'] = Ward::query()->where('is_active', true)
            ->get(['id', 'name'])->map(fn (Ward $w) => ['id' => $w->id, 'name' => $w->name])->all();

        $out['beds'] = Bed::query()->where('is_active', true)
            ->get(['id', 'ward_id', 'name', 'status'])
            ->map(fn (Bed $b) => [
                'id' => $b->id, 'ward_id' => $b->ward_id, 'name' => $b->name, 'status' => $b->status->value,
            ])->all();

        if ($actor->can('visits.view')) {
            $out['services'] = Service::query()->where('is_active', true)
                ->get(['id', 'name', 'code', 'price'])
                ->map(fn (Service $s) => [
                    'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'price' => (string) $s->price,
                ])->all();
        }

        if ($actor->can('lab.view')) {
            $out['lab_tests'] = LabTest::query()->where('is_active', true)
                ->get(['id', 'name', 'specimen', 'unit', 'reference_range', 'price'])
                ->map(fn (LabTest $t) => [
                    'id' => $t->id, 'name' => $t->name, 'specimen' => $t->specimen,
                    'unit' => $t->unit, 'reference_range' => $t->reference_range, 'price' => (string) $t->price,
                ])->all();
        }

        if ($actor->can('pharmacy.view')) {
            // Names and units only. A stock BALANCE is not reference data — it
            // changes by the minute and a device showing a stale one would be
            // telling a pharmacist something untrue (plan §4.5).
            $out['medications'] = StockItem::query()->where('is_active', true)
                ->get(['id', 'name', 'unit', 'sku'])
                ->map(fn (StockItem $i) => [
                    'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'sku' => $i->sku,
                ])->all();
        }

        return $out;
    }
}
