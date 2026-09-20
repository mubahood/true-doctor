{{-- Promise-based confirm used by the dirty-guard (window.tdConfirm). Components
     use wire:confirm for their own destructive actions. --}}
<div x-data="tdConfirm" x-show="open" x-cloak class="tb-modal-backdrop" style="display:none;z-index:1400;" @keydown.escape.window="open && answer(false)">
  <div class="tb-modal" role="alertdialog" aria-modal="true" aria-labelledby="td-confirm-title" x-trap.inert="open">
    <div class="tb-modal-head"><b id="td-confirm-title">Please confirm</b></div>
    <div class="tb-modal-body" x-text="message"></div>
    <div class="tb-modal-foot">
      <button type="button" class="btn-tb btn-tb-ghost" @click="answer(false)">Stay</button>
      <button type="button" class="btn-tb btn-tb-danger" @click="answer(true)">Discard changes</button>
    </div>
  </div>
</div>
