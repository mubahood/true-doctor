<div>
  <div class="tb-page-header">
    <div>
      <h1>{{ $patient ? 'Edit patient' : 'Register patient' }}</h1>
      <div class="tb-breadcrumb">
        <a wire:navigate href="{{ route('admin.patients.index') }}">Patients</a> <span>/</span>
        {{-- The space before `@else` is load-bearing: Blade only reads a
             directive when the character before `@` is not a word character,
             so `Edit@else` left the whole branch — and the word "New" — out of
             the page entirely. --}}
        @if($patient)<a wire:navigate href="{{ route('admin.patients.show', $patient) }}">{{ $patient->patient_no }}</a> <span>/</span> Edit @else New @endif
      </div>
    </div>
  </div>

  <form wire:submit="save" wire:loading.class="tb-loading">
    @include('livewire.patients._fields')

    <div style="display:flex;gap:10px;align-items:center;">
      <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
        <span wire:loading.remove wire:target="save"><i class="fas fa-check"></i> {{ $patient ? 'Save changes' : 'Register patient' }}</span>
        <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin"></i> Saving…</span>
      </button>
      <a wire:navigate href="{{ $patient ? route('admin.patients.show', $patient) : route('admin.patients.index') }}" class="btn-tb btn-tb-ghost">Cancel</a>
    </div>
  </form>
</div>
