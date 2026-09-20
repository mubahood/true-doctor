<?php

namespace App\Livewire\StaffProfiles;

use App\Http\Requests\StaffProfileRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff profiles — live table + slide-over create/edit (CrudModal). One profile
 * per user (enforced per hospital by StaffProfileRequest::rulesFor()); the user
 * link is immutable once created. No navigation.
 *
 * A row abbreviates everything that matters about a clinician: qualifications
 * are cut off, the licence number is a string with no context, and nothing
 * says whether they are bookable at all. The dialog carries the profile whole,
 * with the days they actually work.
 *
 * @property-read StaffProfile|null $peeked
 * @property-read Collection<int,DoctorSchedule> $peekedWindows
 * @property-read int $peekedUpcoming
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, PeeksAndEditsRecords, WithTable;

    public ?int $user_id = null;

    public ?int $department_id = null;

    public ?string $job_title = null;

    public ?string $specialty = null;

    public ?string $license_no = null;

    public ?string $qualifications = null;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', StaffProfile::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return StaffProfile::class;
    }

    protected function formFields(): array
    {
        return ['user_id', 'department_id', 'job_title', 'specialty', 'license_no', 'qualifications', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Profile';
    }

    protected function nullableFields(): array
    {
        return ['job_title', 'specialty', 'license_no', 'qualifications'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function rules(): array
    {
        return StaffProfileRequest::rulesFor($this->editingId);
    }

    /** The user link is immutable once the profile exists (it carries the tenant). */
    protected function beforeSave(array &$data, ?Model $model): void
    {
        if ($model !== null) {
            unset($data['user_id']);
        }
    }

    /**
     * The staff member this profile is linked to, so the dialog can name them
     * once the link is locked.
     *
     * `users` and `departments` used to be two uncapped `->get()`s on every
     * render, drawn into two <select>s; both are pickers now (docs/visits.md).
     */
    #[Computed]
    public function linkedUser(): ?User
    {
        return $this->user_id === null ? null : User::currentHospital()->find($this->user_id);
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return StaffProfile::class;
    }

    protected function peekRelations(): array
    {
        return ['user', 'department'];
    }

    protected function peekCaches(): array
    {
        return ['peekedWindows', 'peekedUpcoming'];
    }

    /**
     * The days they are bookable.
     *
     * "Is this doctor on the roster at all" is not answerable from any column
     * in this table, and it is the first thing asked before an appointment is
     * offered to a patient.
     *
     * @return Collection<int,DoctorSchedule>
     */
    #[Computed]
    public function peekedWindows(): Collection
    {
        $userId = $this->peeked?->user_id;

        return $userId === null
            ? new Collection
            : DoctorSchedule::with('room')
                ->where('doctor_id', $userId)
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->get();
    }

    /** How many patients are already booked with them from today on. */
    #[Computed]
    public function peekedUpcoming(): int
    {
        $userId = $this->peeked?->user_id;

        return $userId === null
            ? 0
            : Appointment::where('doctor_id', $userId)
                ->whereDate('scheduled_at', '>=', now()->toDateString())
                ->count();
    }

    public function render()
    {
        $this->authorize('viewAny', StaffProfile::class);

        $rows = StaffProfile::query()
            ->with(['user', 'department'])
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->whereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$this->search}%"))->orWhere('specialty', 'like', "%{$this->search}%")->orWhere('license_no', 'like', "%{$this->search}%")))
            ->orderByDesc('id')
            ->paginate($this->perPage);

        return view('livewire.staff.index', ['rows' => $rows])->title('Staff profiles');
    }
}
