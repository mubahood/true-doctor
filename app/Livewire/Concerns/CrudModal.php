<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * Shared create/edit slide-over mechanics for catalogue components
 * (docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md §4.5, C9). The host component keeps
 * flat public properties (so `wire:model="name"` and existing tests stay
 * unchanged) and declares:
 *
 *   modelClass()   — the Eloquent model (must be tenant-scoped or explicitly guarded)
 *   formFields()   — the public properties that mirror model attributes
 *   rules()        — delegate to the FormRequest's static rulesFor($this->editingId)
 *   nounLabel()    — "Room" (used in toasts)
 *
 * Optional hooks: nullableFields(), defaults(), beforeSave(&$data, ?$model),
 * afterSave($model, bool $created), fillExtra($model).
 *
 * Requires AuthorizesRequests on the host; every action re-authorizes via the
 * model's Policy, and editingId is #[Locked] so the client cannot swap it.
 */
trait CrudModal
{
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    /**
     * Bumped every time the dialog opens, so a <livewire:ui.select-search>
     * child remounts instead of keeping a pick from the last record edited.
     *
     * It is part of every picker's key; without it, opening "edit" on a second
     * row shows the first row's doctor.
     */
    public int $formNonce = 0;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return list<string> */
    abstract protected function formFields(): array;

    abstract protected function nounLabel(): string;

    /** Fields where '' should persist as null. @return list<string> */
    protected function nullableFields(): array
    {
        return [];
    }

    /** Property defaults applied on create() / after save. @return array<string, mixed> */
    protected function defaults(): array
    {
        return [];
    }

    /** Mutate validated data before persisting (normalisation, generated columns). */
    protected function beforeSave(array &$data, ?Model $model): void {}

    /** Side-effects after a successful create/update. */
    protected function afterSave(Model $model, bool $created): void {}

    /** Copy attributes the generic fill can't derive (relations, casts). */
    protected function fillExtra(Model $model): void {}

    /** Throw a \DomainException to refuse a delete (e.g. a category that still has items). */
    protected function assertDeletable(Model $model): void {}

    /** Throw a \DomainException to refuse a create (e.g. a plan seat limit). */
    protected function assertCreatable(): void {}

    /**
     * Columns that identify "the same record" for archive/restore purposes.
     * Catalogue tables carry a composite unique on (hospital_id, …these…) that
     * ignores soft-deletes, so creating an archived name again would hit the
     * index; instead the archived row is restored and updated (plan K5).
     *
     * @return list<string>
     */
    protected function uniqueKey(): array
    {
        return ['name'];
    }

    public function create(): void
    {
        $this->authorize('create', $this->modelClass());
        $this->resetForm();
        $this->showForm = true;
    }

    /**
     * A <livewire:ui.select-search> child reports its pick.
     *
     * Whitelisted by `formFields()` — the form already declares what it owns,
     * so a name arriving from the wire can only ever set one of those. This
     * lives on the trait rather than on each host because every CRUD dialog
     * that picks a doctor, a room or a user needs exactly this, and twelve
     * copies of it is twelve chances for one to drift.
     */
    #[On('select-search:picked')]
    public function pickedForForm(string $name, int $id): void
    {
        if (in_array($name, $this->formFields(), true)) {
            $this->{$name} = $id;
        }
    }

    #[On('select-search:cleared')]
    public function clearedForForm(string $name): void
    {
        if (in_array($name, $this->formFields(), true)) {
            $this->{$name} = null;
        }
    }

    public function edit(int $id): void
    {
        $model = $this->modelClass()::findOrFail($id);
        $this->authorize('update', $model);

        $this->resetErrorBag();
        $this->formNonce++;
        $this->editingId = $model->getKey();

        foreach ($this->formFields() as $field) {
            $value = $model->{$field};
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            if ($value === null) {
                $value = $this->defaults()[$field] ?? null;
                // keep declared non-nullable scalar types satisfied
                $type = (new \ReflectionProperty($this, $field))->getType();
                if ($value === null && $type instanceof \ReflectionNamedType && ! $type->allowsNull()) {
                    $value = match ($type->getName()) {
                        'string' => '', 'int' => 0, 'float' => 0.0, 'bool' => false, 'array' => [], default => null
                    };
                }
            }
            $this->{$field} = $value;
        }
        $this->fillExtra($model);

        $this->showForm = true;
    }

    public function save(): void
    {
        $model = $this->editingId ? $this->modelClass()::findOrFail($this->editingId) : null;

        $model
            ? $this->authorize('update', $model)
            : $this->authorize('create', $this->modelClass());

        $data = $this->validate();

        if ($model === null) {
            try {
                $this->assertCreatable();
            } catch (\DomainException $e) {
                $this->dispatch('toast', message: $e->getMessage(), type: 'error');

                return;
            }
        }

        foreach ($this->nullableFields() as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field]) && trim($data[$field]) === '') {
                $data[$field] = null;
            }
        }

        $this->beforeSave($data, $model);

        $restored = false;

        if ($model) {
            $model->update($data);
            $created = false;
        } elseif (($archived = $this->findArchivedTwin($data)) && method_exists($archived, 'restore')) {
            $archived->restore();
            $archived->update($data);
            $model = $archived;
            $created = false;
            $restored = true;
        } else {
            $model = $this->modelClass()::create($data);
            $created = true;
        }

        $this->afterSave($model, $created);

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('toast', message: $this->nounLabel().($created ? ' created.' : ($restored ? ' restored from the archive.' : ' updated.')), type: 'success');
    }

    public function delete(int $id): void
    {
        $model = $this->modelClass()::findOrFail($id);
        $this->authorize('delete', $model);

        try {
            $this->assertDeletable($model);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $model->delete();

        $this->dispatch('toast', message: $this->nounLabel().' archived.', type: 'success');
    }

    /** A soft-deleted row with the same unique key, if the model soft-deletes. */
    private function findArchivedTwin(array $data): ?Model
    {
        $class = $this->modelClass();
        if (! in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true)) {
            return null;
        }

        $query = $class::query()
            ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->whereNotNull('deleted_at');
        foreach ($this->uniqueKey() as $column) {
            if (! array_key_exists($column, $data) || $data[$column] === null || $data[$column] === '') {
                return null;
            }
            $query->where($column, $data[$column]);
        }

        return $query->first();
    }

    protected function resetForm(): void
    {
        $this->formNonce++;
        $this->reset(array_merge(['editingId'], $this->formFields()));
        foreach ($this->defaults() as $field => $value) {
            $this->{$field} = $value;
        }
        $this->resetErrorBag();
    }
}
