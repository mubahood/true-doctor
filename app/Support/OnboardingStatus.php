<?php

namespace App\Support;

use App\Models\Bed;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Room;
use App\Models\Service;
use App\Models\StockCategory;
use App\Models\User;
use App\Models\Ward;
use App\Support\Onboarding\Step;
use Illuminate\Support\Collection;

/**
 * Single source of truth for hospital setup — used by BOTH the onboarding
 * wizard (App\Livewire\Onboarding\Index) and the redirect gate
 * (App\Http\Middleware\RequireOnboarding), so what the admin is told and what
 * the system enforces can never drift apart.
 *
 * REQUIRED steps are the minimum a hospital needs before it can treat and bill
 * a patient: an identifiable hospital, money configured, somewhere to work, a
 * price list, and someone besides the owner to do the work. Until all of them
 * pass, the gate keeps the owner in the wizard — there is no skip.
 *
 * RECOMMENDED steps (rooms, wards, catalogues) shape the modules a hospital
 * chooses to use; they are surfaced but never block.
 *
 * Paying for the subscription is deliberately NOT a step: a hospital on its
 * free trial is fully operational and must never be trapped behind a paywall
 * here. Lapsed subscriptions are enforced separately by EnsureSubscribed.
 */
class OnboardingStatus
{
    /** Steps whose absence blocks access to the rest of the system. */
    public const REQUIRED = ['profile', 'billing', 'departments', 'services', 'staff'];

    // ── Individual checks (kept small so each reads as a sentence) ─────────

    /** The hospital can identify itself on an invoice: address plus a way to reach it. */
    public function profileConfigured(Hospital $hospital): bool
    {
        $contact = $this->contact($hospital);

        return filled($hospital->address) && (filled($contact['phone'] ?? null) || filled($contact['email'] ?? null));
    }

    public function billingConfigured(Hospital $hospital): bool
    {
        $billing = is_array($hospital->settings['billing'] ?? null) ? $hospital->settings['billing'] : [];

        return filled($billing['currency_code'] ?? null) && filled($billing['invoice_prefix'] ?? null);
    }

    /** A price list is only useful if something on it can actually be charged. */
    public function hasServices(Hospital $hospital): bool
    {
        return $this->scoped(Service::class, $hospital)->where('is_active', true)->exists();
    }

    public function hasDepartments(Hospital $hospital): bool
    {
        return $this->scoped(Department::class, $hospital)->where('is_active', true)->exists();
    }

    /** More than just the founding admin — at least one real staff member added. */
    public function hasStaff(Hospital $hospital): bool
    {
        return User::where('hospital_id', $hospital->id)->where('is_active', true)->count() > 1;
    }

    /** Every REQUIRED step complete → the hospital is ready to operate. */
    public function isComplete(?Hospital $hospital): bool
    {
        if ($hospital === null) {
            return true; // No hospital context (e.g. super-admin) — nothing to gate.
        }

        return $this->profileConfigured($hospital)
            && $this->billingConfigured($hospital)
            && $this->hasDepartments($hospital)
            && $this->hasServices($hospital)
            && $this->hasStaff($hospital);
    }

    /**
     * Is this user being held in setup right now? True only for the admin who
     * can actually finish it, and only while a required step is outstanding.
     *
     * Shared by the gate and the sidebar, so the menu can never offer a page
     * the gate would bounce. The hospital is read fresh rather than through the
     * user's cached relation: a step completed moments ago must count.
     */
    public function mustCompleteSetup(?User $user): bool
    {
        if (! config('onboarding.gate_enabled', true)) {
            return false;
        }

        if (! $user || $user->isSuperAdmin() || ! $user->can('manage-settings')) {
            return false;
        }

        return ! $this->isComplete($user->hospital()->first());
    }

    // ── The checklist the wizard renders ──────────────────────────────────

    /** @return Collection<int, Step> */
    public function steps(?Hospital $hospital): Collection
    {
        if ($hospital === null) {
            return collect();
        }

        $contact = $this->contact($hospital);
        $billing = is_array($hospital->settings['billing'] ?? null) ? $hospital->settings['billing'] : [];

        $departments = $this->scoped(Department::class, $hospital)->where('is_active', true)->count();
        $services = $this->scoped(Service::class, $hospital)->where('is_active', true)->count();
        $inactiveServices = $this->scoped(Service::class, $hospital)->where('is_active', false)->count();
        $staff = User::where('hospital_id', $hospital->id)->where('is_active', true)->count();
        $rooms = $this->scoped(Room::class, $hospital)->count();
        $wards = $this->scoped(Ward::class, $hospital)->count();
        $beds = $this->scoped(Bed::class, $hospital)->count();
        $labTests = $this->scoped(LabTest::class, $hospital)->count();
        $stockCategories = $this->scoped(StockCategory::class, $hospital)->count();

        return collect([
            new Step(
                key: 'profile',
                title: 'Hospital details',
                summary: 'Address and contact details for invoices and documents.',
                why: 'Every invoice, receipt, lab report and patient ID card is printed with these details. Without them your documents go out unidentifiable.',
                icon: 'fa-hospital',
                required: true,
                done: $this->profileConfigured($hospital),
                detail: $this->profileDetail($hospital, $contact),
                issues: $this->profileIssues($hospital, $contact),
                // Website, licence number and the footer line live on the full
                // letterhead page; this step asks for the minimum a document
                // cannot go out without.
                route: route('admin.settings.hospital'),
            ),
            new Step(
                key: 'billing',
                title: 'Currency and billing',
                summary: 'Currency, tax and invoice numbering.',
                why: 'Every price, invoice and payment in the system is stored and displayed in this currency. Set it before you take your first payment — changing it later does not convert existing records.',
                icon: 'fa-coins',
                required: true,
                done: $this->billingConfigured($hospital),
                detail: $this->billingConfigured($hospital)
                    ? trim(($billing['currency_code'] ?? '').' · invoices '.($billing['invoice_prefix'] ?? '').'-'.date('Y').'-00001')
                    : 'Not configured',
                issues: $this->billingIssues($billing),
                route: route('admin.settings.billing'),
            ),
            new Step(
                key: 'departments',
                title: 'Departments',
                summary: 'The clinics and units patients are seen in.',
                why: 'Appointments, visits and staff are organised by department. At least one is needed before anyone can be booked in.',
                icon: 'fa-sitemap',
                required: true,
                done: $departments > 0,
                detail: $departments > 0 ? $this->plural($departments, 'active department') : 'None yet',
                route: route('admin.departments.index'),
            ),
            new Step(
                key: 'services',
                title: 'Price list',
                summary: 'What you charge for, and how much.',
                why: 'Consultations, procedures and tests are billed from this list. A visit cannot be invoiced until at least one service is priced.',
                icon: 'fa-tags',
                required: true,
                done: $services > 0,
                detail: $services > 0 ? $this->plural($services, 'priced service') : 'None yet',
                issues: $inactiveServices > 0 && $services === 0
                    ? [$this->plural($inactiveServices, 'service').' exist but none is active — activate one so it can be charged.']
                    : [],
                route: route('admin.services.index'),
            ),
            new Step(
                key: 'staff',
                title: 'Your team',
                summary: 'Doctors, nurses, receptionists and cashiers.',
                why: 'Each staff member signs in with their own account and sees only what their role allows. Work is attributed to whoever did it.',
                icon: 'fa-user-plus',
                required: true,
                done: $staff > 1,
                detail: $staff > 1 ? $this->plural($staff - 1, 'colleague').' besides you' : 'Only your own account',
                route: route('admin.users.index'),
            ),
            new Step(
                key: 'rooms',
                title: 'Consultation rooms',
                summary: 'Where appointments take place.',
                why: 'Rooms let you schedule around a physical space and prevent two clinicians being booked into the same one.',
                icon: 'fa-door-open',
                required: false,
                done: $rooms > 0,
                detail: $rooms > 0 ? $this->plural($rooms, 'room') : 'None yet',
                route: route('admin.rooms.index'),
            ),
            new Step(
                key: 'wards',
                title: 'Wards and beds',
                summary: 'Only if you admit inpatients.',
                why: 'Admissions occupy a bed in a ward, and the nightly rate on the bed is what the stay is billed at.',
                icon: 'fa-bed',
                required: false,
                done: $wards > 0 && $beds > 0,
                detail: $wards > 0 ? $this->plural($wards, 'ward').', '.$this->plural($beds, 'bed') : 'None yet',
                issues: $wards > 0 && $beds === 0 ? ['You have wards but no beds — a patient cannot be admitted yet.'] : [],
                route: route('admin.wards.index'),
            ),
            new Step(
                key: 'catalogues',
                title: 'Lab and pharmacy catalogues',
                summary: 'Tests you run and the categories your stock falls into.',
                why: 'Lab orders are placed against the test catalogue, and stock items need a category before they can be received or dispensed.',
                icon: 'fa-flask',
                required: false,
                done: $labTests > 0 || $stockCategories > 0,
                detail: ($labTests > 0 || $stockCategories > 0)
                    ? $this->plural($labTests, 'lab test').', '.$this->plural($stockCategories, 'stock category')
                    : 'None yet',
                route: route('admin.lab-tests.index'),
            ),
        ]);
    }

    /** @return Collection<int, Step> */
    public function requiredSteps(?Hospital $hospital): Collection
    {
        return $this->steps($hospital)->filter(fn (Step $s) => $s->required)->values();
    }

    /** The first required step still outstanding — where the wizard opens. */
    public function nextStep(?Hospital $hospital): ?Step
    {
        return $this->requiredSteps($hospital)->firstWhere(fn (Step $s) => ! $s->done)
            ?? $this->steps($hospital)->firstWhere(fn (Step $s) => ! $s->done);
    }

    /** @return array{done:int,total:int,percent:int} over the REQUIRED steps only. */
    public function progress(?Hospital $hospital): array
    {
        $required = $this->requiredSteps($hospital);
        $total = $required->count();
        $done = $required->filter(fn (Step $s) => $s->done)->count();

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 100,
        ];
    }

    /** Every issue across every step, for the summary banner. @return list<string> */
    public function issues(?Hospital $hospital): array
    {
        return $this->steps($hospital)->flatMap(fn (Step $s) => $s->issues)->values()->all();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    /**
     * How to reach the hospital, from wherever it was last written.
     *
     * There were two of these. Setup wrote `settings['contact']`; the
     * letterhead page wrote `settings['profile']`, which is what DocumentBrand
     * prints. So a hospital could enter its phone number during setup and have
     * every invoice it ever printed carry no way to ring anybody — and filling
     * it in on the letterhead page did not satisfy the setup step that was
     * holding the admin in the wizard.
     *
     * `profile` is the one store now. `contact` is still read underneath it so
     * hospitals set up before this keep the details they already gave.
     *
     * @return array<string,mixed>
     */
    public function contact(Hospital $hospital): array
    {
        $legacy = is_array($hospital->settings['contact'] ?? null) ? $hospital->settings['contact'] : [];
        $current = is_array($hospital->settings['profile'] ?? null) ? $hospital->settings['profile'] : [];

        return array_filter(array_merge($legacy, $current), fn ($value) => filled($value));
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private function scoped(string $model, Hospital $hospital): \Illuminate\Database\Eloquent\Builder
    {
        return $model::withoutGlobalScopes()->where('hospital_id', $hospital->id);
    }

    /** @param array<string,mixed> $contact */
    private function profileDetail(Hospital $hospital, array $contact): string
    {
        $parts = array_filter([
            $hospital->name,
            $contact['phone'] ?? null,
            $contact['email'] ?? null,
        ]);

        return $parts !== [] ? implode(' · ', $parts) : 'Not configured';
    }

    /**
     * @param  array<string,mixed>  $contact
     * @return list<string>
     */
    private function profileIssues(Hospital $hospital, array $contact): array
    {
        $issues = [];

        if (filled($hospital->address) && blank($contact['phone'] ?? null) && filled($contact['email'] ?? null)) {
            $issues[] = 'No phone number — patients and insurers usually call before they email.';
        }

        // Not a reason to hold setup up — a hospital without a logo is still a
        // hospital — but worth saying, because the alternative is finding out
        // from the first invoice a patient carries home.
        if (blank($hospital->logo)) {
            $issues[] = 'No logo yet — documents will print with your name only.';
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $billing
     * @return list<string>
     */
    private function billingIssues(array $billing): array
    {
        $issues = [];

        if (($billing['tax_enabled'] ?? false) && (float) ($billing['tax_rate'] ?? 0) <= 0) {
            $issues[] = 'Tax is switched on but the rate is 0% — invoices will show a tax line of zero.';
        }

        return $issues;
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
