<?php

namespace App\Livewire\Super\Hospitals;

use App\Enums\HospitalStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Requests\Super\HospitalRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\WithTable;
use App\Models\Hospital;
use App\Models\Scopes\HospitalScope;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Platform hospitals (super-admin) — who signed up, when, what they are on,
 * whether anybody is using it, and how to reach the owner.
 *
 * Hospitals are tenants, not tenant rows, so nothing here is hospital-scoped;
 * everything that IS scoped (subscriptions, patients, visits) is read either
 * through raw correlated subqueries or with HospitalScope dropped explicitly,
 * or a super-admin who has visited one tenant would see the platform list
 * shrink to it (C18).
 *
 * "The subscription" of a hospital means its LATEST one, by start date then
 * id — the same rule for the badge on a row, the filter, and the counts at
 * the top, so the three can never disagree.
 *
 * @property-read array<string,string> $statuses the #[Computed] statuses()
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, WithTable;

    /** Trials ending within this many days are flagged. */
    public const ENDING_SOON_DAYS = 7;

    public string $name = '';

    public ?string $slug = null;

    public ?string $address = null;

    public string $timezone = 'Africa/Kampala';

    public string $currency = 'UGX';

    public string $status = 'active';

    /** Subscription filter: '' · trialing · ending · active · expired · cancelled · none */
    #[Url(except: '')]
    public string $plan = '';

    /** Hospital status filter: '' · active · suspended */
    #[Url(except: '')]
    public string $state = '';

    /** Hospital in the quick view. */
    public ?int $peekId = null;

    public bool $showPeek = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Hospital::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return Hospital::class;
    }

    protected function formFields(): array
    {
        return ['name', 'slug', 'address', 'timezone', 'currency', 'status'];
    }

    protected function nounLabel(): string
    {
        return 'Hospital';
    }

    protected function nullableFields(): array
    {
        return ['slug', 'address'];
    }

    protected function defaults(): array
    {
        return ['timezone' => 'Africa/Kampala', 'currency' => 'UGX', 'status' => 'active'];
    }

    protected function rules(): array
    {
        return HospitalRequest::rulesFor($this->editingId);
    }

    // ── WithTable contract ─────────────────────────────────────
    protected function resetsPage(): array
    {
        return ['plan', 'state'];
    }

    protected function sortableFields(): array
    {
        return ['name', 'created_at', 'last_active_at', 'patients_count', 'visits_count'];
    }

    /** @return array<string,string> */
    #[Computed]
    public function statuses(): array
    {
        return collect(HospitalStatus::cases())->mapWithKeys(fn (HospitalStatus $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<string,string> */
    public function planFilters(): array
    {
        return [
            '' => 'Any subscription',
            'trialing' => 'On trial',
            'ending' => 'Trial ends within '.self::ENDING_SOON_DAYS.' days',
            'active' => 'Paying',
            'expired' => 'Expired',
            'cancelled' => 'Cancelled',
            'none' => 'No subscription',
        ];
    }

    // ── The latest subscription, as SQL ──────────────────────────────────

    /** One column of a hospital's latest subscription, correlated on `hospitals`. */
    private function latest(string $column): string
    {
        return "(select s.{$column} from subscriptions s where s.hospital_id = hospitals.id "
            .'order by s.starts_at desc, s.id desc limit 1)';
    }

    /** @param Builder<Hospital> $query */
    private function whereSubscription(Builder $query, string $filter): void
    {
        $status = $this->latest('status');

        match ($filter) {
            'none' => $query->whereRaw("{$status} is null"),
            'ending' => $query->whereRaw("{$status} = ?", [SubscriptionStatus::Trialing->value])
                ->whereRaw($this->latest('trial_ends_at').' between ? and ?', [Carbon::now(), Carbon::now()->addDays(self::ENDING_SOON_DAYS)]),
            'trialing', 'active', 'expired', 'cancelled' => $query->whereRaw("{$status} = ?", [$filter]),
            default => null,
        };
    }

    // ── The page ─────────────────────────────────────────────────────────

    /**
     * The headline counts. One query, so the figures come from one instant.
     *
     * @return array{total:int,paying:int,trialing:int,ending:int,lapsed:int,no_sub:int,new_month:int,new_week:int,suspended:int}
     */
    #[Computed]
    public function stats(): array
    {
        $status = $this->latest('status');
        $trialEnds = $this->latest('trial_ends_at');
        $now = Carbon::now();

        $row = DB::table('hospitals')
            ->whereNull('deleted_at')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when {$status} = 'active' then 1 else 0 end) as paying")
            ->selectRaw("sum(case when {$status} = 'trialing' then 1 else 0 end) as trialing")
            ->selectRaw("sum(case when {$status} = 'trialing' and {$trialEnds} between ? and ? then 1 else 0 end) as ending",
                [$now, $now->copy()->addDays(self::ENDING_SOON_DAYS)])
            ->selectRaw("sum(case when {$status} in ('expired', 'cancelled') then 1 else 0 end) as lapsed")
            ->selectRaw("sum(case when {$status} is null then 1 else 0 end) as no_sub")
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as new_month', [$now->copy()->startOfMonth()])
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as new_week', [$now->copy()->subDays(7)])
            ->selectRaw("sum(case when status = 'suspended' then 1 else 0 end) as suspended")
            ->first();

        return collect((array) $row)->map(fn ($v) => (int) $v)->all();
    }

    public function render()
    {
        $this->authorize('viewAny', Hospital::class);

        if (! array_key_exists($this->plan, $this->planFilters())) {
            $this->plan = '';
        }
        if (! in_array($this->state, ['', ...array_keys($this->statuses)], true)) {
            $this->state = '';
        }

        $term = trim($this->search);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query = Hospital::query()
            ->select('hospitals.*')
            ->withCount('users')
            ->selectSub(fn ($q) => $q->from('patients')->selectRaw('count(*)')
                ->whereColumn('patients.hospital_id', 'hospitals.id')->whereNull('patients.deleted_at'), 'patients_count')
            ->selectSub(fn ($q) => $q->from('visits')->selectRaw('count(*)')
                ->whereColumn('visits.hospital_id', 'hospitals.id')->whereNull('visits.deleted_at'), 'visits_count')
            // Staff activity, not the owner's alone: a hospital whose
            // receptionist logged in this morning is in use.
            ->selectSub(fn ($q) => $q->from('users')->selectRaw('max(last_active_at)')
                ->whereColumn('users.hospital_id', 'hospitals.id'), 'last_active_at')
            ->with(['subscriptions' => fn ($q) => $q->withoutGlobalScope(HospitalScope::class)
                ->with('plan:id,name,price')
                ->orderByDesc('starts_at')->orderByDesc('id')->limit(1)])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq
                ->where('name', 'like', $like)
                ->orWhere('slug', 'like', $like)
                // Somebody asking "which hospital is this?" usually has the
                // owner's email or phone number in front of them, not the slug.
                ->orWhereExists(fn ($u) => $u->from('users')->selectRaw('1')
                    ->whereColumn('users.hospital_id', 'hospitals.id')
                    ->where(fn ($w) => $w->where('users.email', 'like', $like)
                        ->orWhere('users.phone', 'like', $like)
                        ->orWhere('users.name', 'like', $like)))))
            ->when($this->state !== '', fn (Builder $q) => $q->where('status', $this->state));

        if ($this->plan !== '') {
            $this->whereSubscription($query, $this->plan);
        }

        $rows = $this->applySort($query, fn (Builder $q) => $q->orderByDesc('created_at')->orderByDesc('id'))
            ->paginate($this->perPage);

        return view('livewire.super.hospitals.index', [
            'rows' => $rows,
            'owners' => $this->ownersOf($rows->pluck('id')),
            'demoSlug' => Demo::enabled() ? Demo::hospitalSlug() : null,
        ])->title('Hospitals');
    }

    /**
     * The person to call about each hospital on this page: its first
     * hospital admin — the account created at sign-up. One query for the page.
     *
     * @return Collection<int, User>
     */
    private function ownersOf(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        // The earliest admin of each hospital: at most one row per hospital
        // on the page, whatever the size of the users table.
        $first = DB::table('users')->selectRaw('min(id)')
            ->whereIn('hospital_id', $ids)->where('role', 'hospital_admin')
            ->groupBy('hospital_id');

        return User::query()
            ->whereIn('id', $first)
            ->limit($ids->count())
            ->get(['id', 'hospital_id', 'name', 'email', 'phone', 'last_active_at'])
            ->keyBy('hospital_id');
    }

    // ── Quick view ───────────────────────────────────────────────────────

    public function peek(int $id): void
    {
        $this->authorize('viewAny', Hospital::class);
        $this->peekId = Hospital::findOrFail($id)->id;
        $this->showPeek = true;
        unset($this->peeked);
    }

    public function closePeek(): void
    {
        $this->reset(['showPeek', 'peekId']);
    }

    /** From the quick view straight into the edit form, one dialog at a time. */
    public function editFromPeek(int $id): void
    {
        $this->closePeek();
        $this->edit($id);
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'plan', 'state']);
        $this->resetPage();
    }

    /**
     * Everything about one hospital a platform owner asks: who, since when,
     * on what, using it or not, paid what, and which advert brought them.
     *
     * @return array<string,mixed>|null
     */
    #[Computed]
    public function peeked(): ?array
    {
        if ($this->peekId === null) {
            return null;
        }

        $hospital = Hospital::find($this->peekId);

        if ($hospital === null) {
            return null;
        }

        $subscriptions = $hospital->subscriptions()
            ->withoutGlobalScope(HospitalScope::class)
            ->with('plan:id,name,price')
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->get();

        $payments = DB::table('subscription_payments as sp')
            ->join('subscriptions as s', 's.id', '=', 'sp.subscription_id')
            ->where('s.hospital_id', $hospital->id)
            ->orderByDesc('sp.paid_at')
            ->get(['sp.amount', 'sp.method', 'sp.reference', 'sp.paid_at']);

        $staff = User::query()
            ->where('hospital_id', $hospital->id)
            ->orderByRaw("case when role = 'hospital_admin' then 0 else 1 end")
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'last_active_at', 'created_at']);

        $count = fn (string $table) => DB::table($table)->where('hospital_id', $hospital->id)->whereNull('deleted_at')->count();
        $lastActive = DB::table('users')->where('hospital_id', $hospital->id)->max('last_active_at');

        return [
            'hospital' => $hospital,
            'subscription' => $subscriptions->first(),
            'subscriptions' => $subscriptions,
            'payments' => $payments,
            'paid' => (float) $payments->sum('amount'),
            'staff' => $staff,
            'staffCount' => DB::table('users')->where('hospital_id', $hospital->id)->count(),
            'owner' => $staff->firstWhere('role', 'hospital_admin'),
            'patients' => $count('patients'),
            'visits' => $count('visits'),
            'lastActive' => $lastActive ? Carbon::parse($lastActive) : null,
        ];
    }
}
