<?php

namespace App\Livewire\Concerns;

use App\Enums\VisitStatus;
use App\Exceptions\BedUnavailableException;
use App\Exceptions\VisitMismatchException;
use App\Http\Requests\AdmissionRequest;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\AdmissionService;
use RuntimeException;

/**
 * Admitting a patient, the way every other piece of work is raised.
 *
 * A stay is not a thing of its own: AdmissionService places it as an ORDER of
 * type Admission on a VISIT, in progress from the moment the patient is in the
 * bed, and it stays open until they are discharged — which is what holds that
 * visit's gate shut while somebody is still in a bed (docs/orders.md).
 *
 * All of that was true and none of it was on the screen. The admit dialog
 * asked for a patient, a bed, a doctor and a reason; the visit it would attach
 * to was a property nobody could see and no form ever set, so the occupancy
 * board's own admit did not pass one at all. Two screens, two behaviours, and
 * no way to admit a patient onto the visit they are already being seen on.
 *
 * Held here so there is one admit, and so it SAYS which visit the stay will
 * become an order on.
 */
trait AdmitsPatients
{
    public bool $showAdmit = false;

    public ?int $patient_id = null;

    public ?int $bed_id = null;

    /** The visit the stay becomes an order on. Blank means "their open one". */
    public ?int $visit_id = null;

    public ?int $admitting_doctor_id = null;

    public ?string $reason = null;

    /** What the order is called in the visit's list of work. */
    public ?string $stay_title = null;

    /** Part of every picker's key, so a child remounts with the dialog. */
    public int $admitNonce = 0;

    /** What the order the admission raises is called when nobody says. */
    public const DEFAULT_STAY_TITLE = 'Inpatient stay';

    /** Somewhere for a screen to re-read itself once a patient is in a bed. */
    protected function afterAdmitting(Admission $admission): void {}

    public function openAdmit(?int $bedId = null): void
    {
        $this->authorize('manage', Admission::class);

        $this->reset(['patient_id', 'bed_id', 'visit_id', 'admitting_doctor_id', 'reason', 'stay_title']);
        $this->bed_id = $bedId;
        $this->admitNonce++;
        $this->resetErrorBag();
        $this->showAdmit = true;
    }

    /**
     * The visit this stay will become an order on.
     *
     * Either the one that was picked, or the one the patient already has open —
     * which is what AdmissionService will fall back to. Resolved for the dialog
     * so the screen can say it plainly instead of the visit appearing out of
     * nowhere afterwards. Null means a new one will be opened.
     */
    public function admitVisit(): ?Visit
    {
        if ($this->visit_id !== null) {
            return Visit::with('doctor')->find($this->visit_id);
        }

        if ($this->patient_id === null) {
            return null;
        }

        // The same query VisitService::currentOrOpenFor makes before it decides
        // to open one, so the dialog and the service cannot disagree.
        return Visit::with('doctor')
            ->where('patient_id', $this->patient_id)
            ->where('status', '!=', VisitStatus::Completed->value)
            ->latest('created_at')
            ->first();
    }

    public function admit(AdmissionService $service): void
    {
        $this->authorize('manage', Admission::class);

        $data = $this->validate(AdmissionRequest::rulesFor() + [
            'stay_title' => ['nullable', 'string', 'max:120'],
        ]);

        // Validation already proved both belong to this hospital.
        $bed = Bed::findOrFail($data['bed_id']);

        // The patient is whoever the chosen visit belongs to. The form does
        // not ask separately, so the two can no longer disagree — the
        // mismatch `AdmissionService` has to guard against is now unreachable
        // from this screen rather than merely refused by it.
        $visit = Visit::with('patient')->findOrFail($data['visit_id']);
        $patient = $visit->patient;

        if ($patient === null) {
            $this->addError('visit_id', 'That visit has no patient on it.');

            return;
        }

        try {
            $admission = $service->admit($patient, $bed, [
                'visit_id' => $visit->id,
                'admitting_doctor_id' => $data['admitting_doctor_id'] ?? null,
                'reason' => ($data['reason'] ?? null) ?: null,
                'title' => ($data['stay_title'] ?? null) ?: null,
            ], auth()->id());
        } catch (BedUnavailableException $e) {
            // Every one of these is an answer to the form rather than an
            // accident, so each goes beside the field somebody has to change:
            // the bed that is taken, the visit that is somebody else's, or the
            // patient who is already lying in another bed.
            $this->addError('bed_id', $e->getMessage());

            return;
        } catch (VisitMismatchException $e) {
            $this->addError('visit_id', $e->getMessage());

            return;
        } catch (\DomainException|RuntimeException $e) {
            // On the VISIT field, because that is the only field on this form
            // that identifies who is being admitted — the patient is read off
            // it. Attached to `patient_id`, as it was, the message would have
            // been rendered beside a field that no longer exists: "this
            // patient is already in Bed 3" would have vanished and the dialog
            // would have looked like it did nothing at all.
            $this->addError('visit_id', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'The patient could not be admitted. Please try again.', type: 'error');

            return;
        }

        // The dialog closes and the list is where it was. Redirecting to the
        // new record threw whoever opened it out of the list they were working
        // down, and the commonest thing after admitting one patient is
        // admitting the next.
        $this->showAdmit = false;
        $this->reset(['patient_id', 'bed_id', 'visit_id', 'admitting_doctor_id', 'reason', 'stay_title']);
        $this->afterAdmitting($admission);

        $this->dispatch('toast', type: 'success', message: $patient->full_name
            .' admitted to '.$bed->name
            .($admission->visit === null ? '' : ' · on visit '.$admission->visit->visit_no).'.');
    }
}
