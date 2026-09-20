{{-- Global toast host (persisted across wire:navigate). Livewire components fire
     $this->dispatch('toast', message: '…', type: 'success'|'error'|'info'|'warning');
     server flash is bridged by <template data-td-flash> rendered in <main>. --}}
<div class="tb-toast-host" aria-live="polite" aria-atomic="true" x-data="tdToasts" @toast.window="add($event.detail)">
  <template x-for="t in toasts" :key="t.id">
    <div class="tb-toast" :class="'tb-toast-' + t.type" x-transition.opacity.duration.200ms role="status">
      <i class="fas" :class="icon(t.type)" aria-hidden="true"></i>
      <span x-text="t.message"></span>
      <button type="button" class="tb-toast-x" @click="remove(t.id)" aria-label="Dismiss"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
  </template>
</div>
