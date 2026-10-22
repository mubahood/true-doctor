<?php

namespace App\Services;

use App\Enums\FinancialYearStatus;
use App\Enums\InvoiceStatus;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\CardHolder;
use App\Models\CardRecord;
use App\Models\DispensationItem;
use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\PatientDocument;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Services\Backups\MakesArchive;
use App\Services\Finance\LedgerService;
use App\Support\CurrentHospital;
use App\Support\Demo;
use App\Support\Edition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Settings, Remove test records (docs/test-records.md): the patients a clinic
 * made while learning the system, taken out with everything attached before
 * it starts for real.
 *
 * Nothing is deleted silently from the books. Every entry the patient's
 * money made (bills, payments, refunds and credit notes, card top-ups) is
 * reversed by an entry of its own, dated today; what was taken off the shelf
 * for them goes back through StockService. Then the records themselves are
 * removed: visits, orders, bills, payments, the chart, notes, documents and
 * messages. Refused into a closed financial year, for insurance claims, on
 * the demonstration, and for anyone but the hospital admin of an open
 * hospital. A standalone copy is backed up first.
 */
class TestRecordsService
{
    public const REASON = 'Test record removed';

    public function __construct(
        private readonly CurrentHospital $current,
        private readonly StockService $stock,
        private readonly LedgerService $ledger,
    ) {}

    /** Why this person may not use the page, or null when they may. */
    public function refusal(?User $user): ?string
    {
        $hospitalId = $this->current->id();

        return match (true) {
            $user === null => 'Sign in first.',
            $hospitalId === null => 'Open a hospital first. Test records are removed from inside the hospital they belong to.',
            $user->role !== 'hospital_admin' || (int) $user->hospital_id !== $hospitalId => 'Only the hospital admin can remove test records.',
            $this->isDemo($hospitalId) => 'The demonstration is reset on its own; nothing is removed from it by hand.',
            default => null,
        };
    }

    public function hospital(): Hospital
    {
        return Hospital::query()->findOrFail($this->current->id());
    }

    /**
     * The patients the page lists, with their visit count and money.
     *
     * @param  array{before?:string|null,name?:string|null,unpaid?:bool}  $filters
     * @return Builder<Patient>
     */
    public function candidates(array $filters): Builder
    {
        $name = trim((string) ($filters['name'] ?? ''));
        $before = $filters['before'] ?? null;

        return Patient::query()
            ->select('patients.*')
            ->selectSub(Visit::query()->selectRaw('count(*)')->whereColumn('visits.patient_id', 'patients.id'), 'visit_count')
            ->selectSub(Invoice::query()->selectRaw('coalesce(sum(total), 0)')->whereColumn('invoices.patient_id', 'patients.id')
                ->where('status', '!=', InvoiceStatus::Void->value), 'billed_total')
            ->selectSub(Payment::query()->selectRaw('coalesce(sum(amount), 0)')->whereColumn('payments.patient_id', 'patients.id'), 'paid_total')
            ->when($name !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereRaw('lower(first_name) like ?', ['%'.mb_strtolower($name).'%'])
                ->orWhereRaw('lower(last_name) like ?', ['%'.mb_strtolower($name).'%'])
                ->orWhere('patient_no', 'like', '%'.$name.'%')))
            ->when(is_string($before) && $before !== '', fn (Builder $q) => $q->where('patients.created_at', '<', Carbon::parse($before)->startOfDay()))
            ->when(! empty($filters['unpaid']), fn (Builder $q) => $q->whereNotExists(fn ($s) => $s->from('payments')->whereColumn('payments.patient_id', 'patients.id')))
            ->latest('patients.created_at')->latest('patients.id');
    }

    /**
     * Remove the chosen patients. The hospital's name has to be typed to
     * confirm; a standalone copy is backed up first. Each patient is removed
     * in a transaction of its own, so one that is refused does not stop the
     * rest.
     *
     * @param  list<int>  $patientIds
     * @return array{removed:int,refused:array<string,string>,backup:string|null}
     */
    public function remove(array $patientIds, string $typedName, User $by): array
    {
        if (($why = $this->refusal($by)) !== null) {
            throw new RuntimeException($why);
        }
        $hospital = $this->hospital();
        if (mb_strtolower(trim($typedName)) !== mb_strtolower(trim((string) $hospital->name))) {
            throw new RuntimeException('Type the hospital\'s name exactly as it is shown to confirm.');
        }

        $patients = Patient::query()->whereKey(array_values(array_unique(array_map('intval', $patientIds))))->get();
        if ($patients->isEmpty()) {
            throw new RuntimeException('Tick the patients to remove first.');
        }

        // Postings are dated today; a closed year today stops everything.
        app(FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        $backup = null;
        if (Edition::isStandalone()) {
            try {
                $backup = basename(app(MakesArchive::class)->create()['path']);
            } catch (\Throwable $e) {
                report($e);

                throw new RuntimeException('The backup could not be made, so nothing was removed. Check the space on the server and try again.');
            }
        }

        $removed = 0;
        $refused = [];
        foreach ($patients as $patient) {
            try {
                $this->removeOne($patient, $by);
                $removed++;
            } catch (RuntimeException|\DomainException $e) {
                $refused[$patient->full_name.' ('.$patient->patient_no.')'] = $e->getMessage();
            } catch (\Throwable $e) {
                // Rolled back with its transaction; the rest carry on.
                report($e);
                $refused[$patient->full_name.' ('.$patient->patient_no.')'] = 'Could not be removed. Nothing of theirs was changed.';
            }
        }

        return ['removed' => $removed, 'refused' => $refused, 'backup' => $backup];
    }

    /** Why this patient cannot be removed, or null when they can. */
    public function blocker(Patient $patient): ?string
    {
        $invoiceIds = Invoice::withTrashed()->where('patient_id', $patient->id)->pluck('id');

        if (InsuranceClaim::query()->where('patient_id', $patient->id)->orWhereIn('invoice_id', $invoiceIds)->exists()) {
            return 'Has insurance claims. Settle or remove them in Insurance first.';
        }

        if (Admission::query()->where('patient_id', $patient->id)->whereNull('discharged_at')->exists()) {
            return 'Is still admitted. Discharge them first, so the bed is freed.';
        }

        // A card is money held for people. Only a card this patient alone
        // holds and spends, funded by nobody else, can go with them: anything
        // shared would change somebody else's balance.
        $ownCards = PatientCard::query()->where('patient_id', $patient->id)->pluck('id');
        $shared = CardHolder::query()->whereIn('patient_card_id', $ownCards)->where('patient_id', '!=', $patient->id)->exists()
            || CardRecord::query()->whereIn('patient_card_id', $ownCards)->where('patient_id', '!=', $patient->id)->exists()
            || CardRecord::query()->where('patient_id', $patient->id)->whereNotIn('patient_card_id', $ownCards)->exists()
            || Payment::query()->where('patient_id', $patient->id)->whereNotNull('patient_card_id')->whereNotIn('patient_card_id', $ownCards)->exists();
        if ($shared) {
            return 'Shares a prepaid card with other patients. Remove them from the card first.';
        }
        if (PatientCard::query()->whereKey($ownCards)->whereNotNull('insurance_provider_id')->exists()
            || CardRecord::query()->whereIn('patient_card_id', $ownCards)->whereNotNull('insurance_transaction_id')->exists()) {
            return 'Has a card funded by an insurer. Settle it in Insurance first.';
        }

        $closed = FinancialYear::query()->where('status', FinancialYearStatus::Closed->value)->get(['starts_on', 'ends_on']);
        if ($closed->isNotEmpty()) {
            foreach ($this->entries($patient) as $entry) {
                $day = Carbon::parse((string) $entry->date)->toDateString();
                foreach ($closed as $year) {
                    if ($day >= $year->starts_on->toDateString() && $day <= $year->ends_on->toDateString()) {
                        return 'Has money in a closed financial year. Its books cannot be changed.';
                    }
                }
            }
        }

        return null;
    }

    private function removeOne(Patient $patient, User $by): void
    {
        $files = DB::transaction(function () use ($patient, $by) {
            /** @var Patient $locked */
            $locked = Patient::withTrashed()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            if (($why = $this->blocker($locked)) !== null) {
                throw new RuntimeException($why);
            }

            $visitIds = Visit::withTrashed()->where('patient_id', $locked->id)->pluck('id');

            // 1. What was taken off the shelf for them goes back, through the
            //    stock ledger (its own entry in the books).
            $orderIds = \App\Models\Order::withTrashed()->whereIn('visit_id', $visitIds)->pluck('id');
            $lines = OrderItem::query()->whereIn('order_id', $orderIds)->get();
            foreach ($lines as $line) {
                $this->stock->returnFor($line, $by->id, self::REASON);
            }
            $dispensed = DispensationItem::query()->whereHas('dispensation', fn ($q) => $q->whereIn('visit_id', $visitIds))->get();
            foreach ($dispensed as $item) {
                $this->stock->returnFor($item, $by->id, self::REASON);
            }

            // 2. Every entry their money made comes back out of the books,
            //    as a reversing entry dated today. Never a silent delete.
            foreach ($this->entries($locked) as $entry) {
                if ($entry->reversed_by_id === null && $entry->reverses_id === null) {
                    $this->ledger->reverse($entry, self::REASON.': '.$locked->patient_no, $by->id, null, true);
                }
            }

            // 3. The records. Each visit and the patient are deleted as models
            //    (the audit log and the phones' sync hear of it); what hangs
            //    off them follows the database's own links: orders, bills,
            //    payments, the chart, notes, documents, messages.
            $files = PatientDocument::query()->where('patient_id', $locked->id)->pluck('file_path')->filter()->all();
            Appointment::query()->where('patient_id', $locked->id)->delete();

            // The money rows go child first: on MySQL the ledger rows refuse
            // to cascade away with their parent (migration 2026_09_13_000004).
            // Their entries were reversed above and stay in the books.
            $invoiceIds = Invoice::withTrashed()->where('patient_id', $locked->id)->pluck('id');
            $cardIds = PatientCard::query()->where('patient_id', $locked->id)->pluck('id');
            Payment::query()->whereIn('invoice_id', $invoiceIds)->delete();
            InvoiceCredit::query()->whereIn('invoice_id', $invoiceIds)->delete();
            InvoiceItem::query()->whereIn('invoice_id', $invoiceIds)->delete();
            Invoice::withTrashed()->whereKey($invoiceIds)->forceDelete();
            CardRecord::query()->whereIn('patient_card_id', $cardIds)->delete();
            PatientCard::query()->whereKey($cardIds)->delete();

            foreach (Visit::withTrashed()->whereIn('id', $visitIds)->get() as $visit) {
                $visit->forceDelete();
            }
            $locked->forceDelete();

            return $files;
        });

        // The documents' files, once the records are gone for good.
        $disk = app(DocumentService::class)->disk();
        foreach ($files as $path) {
            try {
                $disk->delete((string) $path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Every journal entry the patient's money made: their bills, payments,
     * credit notes and refunds, and their cards' movements.
     *
     * @return \Illuminate\Support\Collection<int, JournalEntry>
     */
    private function entries(Patient $patient): \Illuminate\Support\Collection
    {
        $invoiceIds = Invoice::withTrashed()->where('patient_id', $patient->id)->pluck('id');
        $cardIds = PatientCard::query()->where('patient_id', $patient->id)->pluck('id');

        $sources = [
            (new Invoice)->getMorphClass() => $invoiceIds,
            (new Payment)->getMorphClass() => Payment::query()->whereIn('invoice_id', $invoiceIds)->pluck('id'),
            (new InvoiceCredit)->getMorphClass() => InvoiceCredit::query()->whereIn('invoice_id', $invoiceIds)->pluck('id'),
            (new CardRecord)->getMorphClass() => CardRecord::query()->whereIn('patient_card_id', $cardIds)->pluck('id'),
        ];

        return JournalEntry::query()
            ->where(function (Builder $q) use ($sources) {
                foreach ($sources as $type => $ids) {
                    if ($ids->isNotEmpty()) {
                        $q->orWhere(fn (Builder $w) => $w->where('source_type', $type)->whereIn('source_id', $ids->all()));
                    }
                }
            })
            ->when(collect($sources)->every(fn ($ids) => $ids->isEmpty()), fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->orderBy('id')
            ->get();
    }

    private function isDemo(int $hospitalId): bool
    {
        return Demo::enabled() && Hospital::query()->whereKey($hospitalId)->value('slug') === Demo::hospitalSlug();
    }
}
