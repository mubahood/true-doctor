<?php

namespace App\Services;

use App\Enums\AdmissionStatus;
use App\Enums\BedStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\BedUnavailableException;
use App\Exceptions\VisitMismatchException;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedTransfer;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Inpatient admissions. Bed assignment/transfer/discharge all run in a
 * transaction with a locking read on the bed(s) — a bed can never be
 * double-occupied (constraint F). Bed charges (nights × the bed's nightly rate)
 * are computed in bcmath and billed to the linked visit on discharge.
 */
class AdmissionService
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * Admit a patient to a bed, and raise the order that IS the stay.
     *
     * The order matters as much as the admission. A stay is a piece of work
     * being done for the patient, so it belongs in the one list with
     * everything else (docs/orders.md) — and because it stays OPEN until
     * discharge, a visit cannot reach billing while its patient is still in a
     * bed. That rule used to hold by nobody's doing: an active admission was
     * not an order at all, so the visit's own gate could not see it.
     *
     * @param  array{visit_id?:int,admitting_doctor_id?:int|null,reason?:string|null,title?:string|null,admitted_at?:Carbon|string|null}  $data
     */
    public function admit(Patient $patient, Bed $bed, array $data, ?int $by = null): Admission
    {
        if (app(CurrentHospital::class)->id() === null) {
            throw new RuntimeException('Cannot admit with no resolved hospital.');
        }

        return DB::transaction(function () use ($patient, $bed, $data, $by) {
            // One patient, one bed. Nothing used to stop a second admission
            // being raised while the first was live, which put one patient in
            // two beds and billed both of them.
            $already = Admission::where('patient_id', $patient->id)
                ->whereIn('status', [AdmissionStatus::Admitted->value])
                ->lockForUpdate()
                ->first();

            if ($already !== null) {
                throw new RuntimeException(
                    'This patient is already admitted'.
                    ($already->bed?->name !== null ? " to {$already->bed->name}" : '').
                    '. Discharge or transfer them instead.'
                );
            }

            /** @var Bed $lockedBed */
            $lockedBed = Bed::whereKey($bed->id)->lockForUpdate()->firstOrFail();
            if (! $lockedBed->status->isAssignable()) {
                throw BedUnavailableException::make($lockedBed->name);
            }

            $visit = isset($data['visit_id'])
                ? Visit::findOrFail($data['visit_id'])
                : app(VisitService::class)->currentOrOpenFor($patient, $by);

            // A stay is an ORDER ON A VISIT, and an order can only be placed on
            // the visit of the patient it is for. Nothing checked that: a
            // visit_id from anywhere in the hospital was accepted, which put
            // one patient's inpatient stay — and its bed charge — on another
            // patient's bill.
            if ($visit->patient_id !== $patient->id) {
                throw VisitMismatchException::make();
            }

            $admission = Admission::create([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'bed_id' => $lockedBed->id,
                'visit_id' => $visit->id,
                'admitting_doctor_id' => $data['admitting_doctor_id'] ?? null,
                'status' => AdmissionStatus::Admitted,
                'reason' => $data['reason'] ?? null,
                'admitted_at' => $data['admitted_at'] ?? Carbon::now(),
            ]);

            $lockedBed->update(['status' => BedStatus::Occupied]);

            // In progress, not pending: the patient is in the bed as of now.
            app(OrderService::class)->place($visit, OrderType::Admission,
                trim((string) ($data['title'] ?? '')) ?: 'Inpatient stay', [
                    'notes' => $data['reason'] ?? null,
                    'subject' => $admission,
                    'assigned_to' => $data['admitting_doctor_id'] ?? null,
                    'status' => OrderStatus::InProgress,
                ], $by);

            return $admission;
        });
    }

    /**
     * Put the nights this stay has completed onto its order.
     *
     * One item per night, priced at what the bed costs WHEN THE NIGHT IS
     * BILLED. That is the whole point of doing it a night at a time: the
     * stay used to be one line raised at discharge and priced at the rate in
     * force then, so putting a ward's rate up on Friday silently repriced
     * Monday's night as well. A night that has been billed is finished with.
     *
     * Safe to run as often as you like. It bills the difference between the
     * nights completed and the nights already on the bill, so a second run in
     * the same day adds nothing and a run after three days of downtime adds
     * the three nights that were missed.
     *
     * @param  Carbon|null  $asOf  pretend it is now this moment (for the
     *                             catch-up command and for tests)
     * @return int how many nights were added
     */
    public function accrueNightlyCharges(Admission $admission, ?Carbon $asOf = null, ?int $by = null): int
    {
        return DB::transaction(function () use ($admission, $asOf, $by) {
            /** @var Admission $locked */
            $locked = Admission::whereKey($admission->id)->lockForUpdate()->firstOrFail();

            // A closed stay is settled. Its nights were billed on the way out.
            if (! $locked->status->isActive()) {
                return 0;
            }

            $moment = ($asOf ?? Carbon::now())->copy()->startOfDay();

            // A night is COMPLETED once the next day has started. Somebody
            // admitted this afternoon has completed none of it yet, which is
            // why the admission day itself bills nothing and a same-day
            // discharge is charged its single night on the way out.
            $completed = max(0, (int) $locked->admitted_at->copy()->startOfDay()->diffInDays($moment));

            return $this->billNightsUpTo($locked, $completed, $by);
        });
    }

    /**
     * Bill every night from the last one billed up to `$target`.
     *
     * Each night is its own item, named for the date it covers, so a long
     * stay reads as the nights it actually was rather than a multiplication
     * somebody has to trust. The running total is kept on the admission as
     * the nights land: with the rate free to change between them, the total
     * is the SUM of what was charged and can no longer be recomputed as
     * rate × nights.
     *
     * @return int how many nights were added
     */
    private function billNightsUpTo(Admission $admission, int $target, ?int $by): int
    {
        $already = (int) $admission->nights_billed;

        if ($target <= $already) {
            return 0;
        }

        $stay = $this->stayOrderFor($admission, $by);

        if ($stay === null) {
            return 0;
        }

        $bed = $admission->bed_id === null
            ? null
            : Bed::whereKey($admission->bed_id)->lockForUpdate()->first();

        $rate = $bed === null ? '0.00' : (string) $bed->daily_charge;
        $total = (string) $admission->bed_charge_total;

        for ($night = $already + 1; $night <= $target; $night++) {
            $covers = $admission->admitted_at->copy()->startOfDay()->addDays($night - 1);

            // A bed that costs nothing still consumes a night — the counter
            // moves either way — but an item worth nothing is noise on a bill.
            if (bccomp($rate, '0', 2) > 0) {
                $this->billing->addItem($stay, [
                    // Keeps the "Bed charge" prefix the single line used to
                    // carry, so a bill, a report or a reader scanning for it
                    // still finds it — and adds the two facts the old line
                    // could not give: which night, and which bed.
                    'name' => 'Bed charge — '.($bed?->name !== null ? $bed->name.', ' : '')
                        .'night of '.$covers->format('D d M Y'),
                    'unit_price' => $rate,
                    'quantity' => 1,
                ], $by);

                $total = bcadd($total, $rate, 2);
            }
        }

        $admission->forceFill([
            'nights_billed' => $target,
            'bed_charge_total' => $total,
        ])->save();

        return $target - $already;
    }

    /** The order that IS this stay, raised if an older admission has none. */
    private function stayOrderFor(Admission $admission, ?int $by): ?Order
    {
        $admission->loadMissing('visit');

        if ($admission->visit === null) {
            return null;
        }

        /** @var Order|null $stay */
        $stay = Order::where('subject_type', $admission->getMorphClass())
            ->where('subject_id', $admission->getKey())
            ->first();

        return $stay ?? app(OrderService::class)->place(
            $admission->visit,
            OrderType::Admission,
            'Inpatient stay',
            ['subject' => $admission, 'status' => OrderStatus::InProgress],
            $by,
        );
    }

    /**
     * Finish the order the admission raised, and put the bed charge on it.
     *
     * The stay's own order is completed rather than a second one invented at
     * discharge: the work and what it cost belong together, and a visit's gate
     * reads "no order still open", which a stay that never closes would hold
     * shut for ever.
     *
     * An admission from before stays were orders has none, so one is raised
     * here, already finished — that is what the old code did for every
     * discharge.
     */
    private function closeTheStay(Admission $admission, int $nights, string $charge, ?string $notes, ?int $by): void
    {
        $admission->loadMissing('visit');
        $visit = $admission->visit;

        if ($visit === null) {
            return;
        }

        /** @var Order|null $stay */
        $stay = Order::where('subject_type', $admission->getMorphClass())
            ->where('subject_id', $admission->getKey())
            ->first();

        $orders = app(OrderService::class);

        if ($stay === null) {
            $stay = $orders->place($visit, OrderType::Admission, 'Inpatient stay', [
                'notes' => $notes,
                'subject' => $admission,
                'status' => OrderStatus::InProgress,
            ], $by);
        }

        // No bed charge is added here any more. The nights are already on the
        // order, one item each, put there as they were completed — adding a
        // summary line as well would bill the stay twice.

        // The stay says what it was, on its own order. A completed order has
        // to carry evidence of the work (Order::hasEvidence), and a stay in a
        // bed that costs nothing would otherwise finish with no trace at all.
        $stay->refresh();
        if (trim((string) $stay->report) === '') {
            $said = trim((string) $notes);
            $orders->saveReport($stay, trim(
                'Discharged after '.$nights.' night'.($nights === 1 ? '' : 's').'.'.
                ($said !== '' ? ' '.$said : '')
            ));
        }

        if ($stay->fresh()?->status->isOpen()) {
            $orders->transition($stay->fresh(), OrderStatus::Completed, $by);
        }
    }

    public function transfer(Admission $admission, Bed $toBed, ?string $reason = null, ?int $by = null): BedTransfer
    {
        if (! $admission->status->isActive()) {
            throw new RuntimeException('Only an active admission can be transferred.');
        }

        return DB::transaction(function () use ($admission, $toBed, $reason, $by) {
            $fromBedId = $admission->bed_id;

            // Lock destination bed first, then the current one, in id order to avoid deadlock.
            $ids = array_filter([$toBed->id, $fromBedId]);
            sort($ids);
            Bed::whereIn('id', $ids)->lockForUpdate()->get();

            /** @var Bed $dest */
            $dest = Bed::whereKey($toBed->id)->firstOrFail();
            if (! $dest->status->isAssignable()) {
                throw BedUnavailableException::make($dest->name);
            }

            if ($fromBedId !== null) {
                Bed::whereKey($fromBedId)->update(['status' => BedStatus::Available]);
            }
            $dest->update(['status' => BedStatus::Occupied]);
            $admission->update(['bed_id' => $dest->id]);

            return BedTransfer::create([
                'admission_id' => $admission->id,
                'from_bed_id' => $fromBedId,
                'to_bed_id' => $dest->id,
                'reason' => $reason,
                'transferred_by' => $by,
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Close an admission with an outcome. Frees the bed, computes the bed charge
     * (nights × nightly rate) and — if the admission is tied to a visit —
     * bills it, so IPD charges flow onto the same invoice.
     */
    public function discharge(Admission $admission, AdmissionStatus $outcome, ?string $notes = null, ?int $by = null): Admission
    {
        if (! in_array($outcome, AdmissionStatus::outcomes(), true)) {
            throw new RuntimeException('Invalid discharge outcome.');
        }
        if (! $admission->status->isActive()) {
            throw new RuntimeException('This admission is already closed.');
        }

        return DB::transaction(function () use ($admission, $outcome, $notes, $by) {
            /** @var Admission $locked */
            $locked = Admission::whereKey($admission->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isActive()) {
                throw new RuntimeException('This admission is already closed.');
            }

            $dischargedAt = Carbon::now();
            $nights = max(1, (int) $locked->admitted_at->diffInDays($dischargedAt));

            // Every night not yet on the bill goes on now, still one item per
            // night. If the nightly job has been running there is usually
            // nothing left to do; if it has never run, the whole stay is
            // billed here — a night at a time, so the bill reads the same
            // either way.
            $this->billNightsUpTo($locked, $nights, $by);
            $locked->refresh();

            if ($locked->bed_id !== null) {
                Bed::whereKey($locked->bed_id)->lockForUpdate()->firstOrFail()
                    ->update(['status' => BedStatus::Available]);
            }

            // The SUM of the nights actually charged, not rate × nights. With
            // the rate free to move between one night and the next, there is
            // no single rate left to multiply by.
            $charge = (string) $locked->bed_charge_total;

            $locked->update([
                'status' => $outcome,
                'discharged_at' => $dischargedAt,
                'discharge_notes' => $notes,
            ]);

            $this->closeTheStay($locked, $nights, $charge, $notes, $by);

            return $locked;
        });
    }
}
