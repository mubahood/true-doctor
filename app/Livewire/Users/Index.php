<?php

namespace App\Livewire\Users;

use App\Exceptions\PlanLimitExceededException;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\StaffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Staff management — live table + slide-over create/edit. Every security
 * decision (tenancy, assignable roles, plan seats, temporary passwords, avatar
 * files, welcome mail) lives in App\Services\StaffService; this component only
 * authorizes, validates and reports. Tenancy is explicit because User has no
 * BelongsToHospital global scope (super-admins share the table).
 *
 * A row names a person and their role. What that role actually LETS THEM DO is
 * the question anybody auditing this list is asking, and it was nowhere on the
 * screen — so the dialog names the permissions the role carries, alongside the
 * clinical profile that decides whether they can be booked.
 *
 * @property-read User|null $peeked
 * @property-read StaffProfile|null $peekedProfile
 * @property-read list<string> $peekedAbilities
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, PeeksAndEditsRecords, WithFileUploads, WithTable;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public ?string $email = null;

    public ?string $role = null;

    public ?string $phone = null;

    public ?string $bio = null;

    public bool $is_active = true;

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public $avatar = null;        // transient upload

    public bool $remove_avatar = false;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    private function staff(): StaffService
    {
        return app(StaffService::class);
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Auth::user();

        return $actor;
    }

    /** Resolve a manageable account or 404 — a foreign tenant's row simply does not exist here. */
    private function manageable(int $id): User
    {
        return $this->staff()->manageableBy($this->actor())->findOrFail($id);
    }

    /** @return list<string> */
    #[Computed]
    public function roles(): array
    {
        return $this->staff()->assignableRoles($this->actor());
    }

    public function create(): void
    {
        $this->authorize('create', User::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = $this->manageable($id);
        $this->authorize('update', $user);

        $this->editingId = $user->id;
        $this->name = (string) $user->name;
        $this->email = $user->email;
        $this->role = $user->role;
        $this->phone = $user->phone;
        $this->bio = $user->bio;
        $this->is_active = (bool) $user->is_active;
        $this->password = null;
        $this->password_confirmation = null;
        $this->avatar = null;
        $this->remove_avatar = false;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->editingId)],
            'role' => ['required', Rule::in($this->staff()->assignableRoles($this->actor()))],
            'phone' => ['nullable', 'string', 'max:20'],
            'bio' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'password' => $this->editingId
                ? ['nullable', 'string', Password::defaults(), 'confirmed']
                : ['nullable'],
        ];
    }

    public function save(): void
    {
        $user = $this->editingId ? $this->manageable($this->editingId) : null;

        $user
            ? $this->authorize('update', $user)
            : $this->authorize('create', User::class);

        $data = $this->validate();
        unset($data['password'], $data['avatar']);
        $data['phone'] = filled($this->phone) ? $this->phone : null;
        $data['bio'] = filled($this->bio) ? $this->bio : null;
        $data['is_active'] = $this->is_active;

        if ($user === null) {
            try {
                $result = $this->staff()->create($this->actor(), $data, $this->avatar);
            } catch (PlanLimitExceededException $e) {
                $this->dispatch('toast', message: $e->getMessage(), type: 'error');

                return;
            }

            $created = $result['user'];
            $note = $result['emailed']
                ? " Login credentials sent to {$created->email}."
                : ' (Email delivery failed — share credentials manually.)';
            $message = "User {$created->name} created.{$note}";
        } else {
            $this->staff()->update($user, $data, $this->avatar, $this->remove_avatar, $this->password);
            $message = "User {$user->name} updated.";
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('toast', message: $message, type: 'success');
    }

    public function delete(int $id): void
    {
        $user = $this->manageable($id);
        $this->authorize('delete', $user);

        if ($user->id === Auth::id()) {
            $this->dispatch('toast', message: 'You cannot delete your own account.', type: 'error');

            return;
        }

        $name = $user->name;
        $user->delete();
        $this->dispatch('toast', message: "User {$name} deleted.", type: 'success');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'role', 'phone', 'bio', 'password', 'password_confirmation', 'avatar', 'remove_avatar']);
        $this->is_active = true;
        $this->resetErrorBag();
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return User::class;
    }

    protected function peekCaches(): array
    {
        return ['peekedProfile', 'peekedAbilities'];
    }

    /**
     * User has no BelongsToHospital scope — super admins share the table — so
     * the tenancy check that every other peek gets for free is made by hand.
     */
    protected function authorizePeek(Model $record): void
    {
        $this->authorize('view', $record);
    }

    #[Computed]
    public function peekedProfile(): ?StaffProfile
    {
        return $this->peekId === null
            ? null
            : StaffProfile::with('department')->where('user_id', $this->peekId)->first();
    }

    /**
     * What the role actually lets them do.
     *
     * A role name is a label; the permissions behind it are the thing an audit
     * is asking about, and reading them meant opening the seeder.
     *
     * @return list<string>
     */
    #[Computed]
    public function peekedAbilities(): array
    {
        $user = $this->peeked;

        if ($user === null) {
            return [];
        }

        return $user->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    public function render()
    {
        $this->authorize('viewAny', User::class);

        $rows = $this->staff()->manageableBy($this->actor())
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('livewire.users.index', ['rows' => $rows])->title('User accounts');
    }
}
