{{-- Server flash → toast. Rendered inside <main> on every page so the persisted
     toast host receives it after full loads and after wire:navigate. --}}
@php
  $flash = [];
  foreach (['success' => 'success', 'status' => 'info', 'error' => 'error', 'warning' => 'warning'] as $key => $type) {
      if (session()->has($key)) $flash[] = ['type' => $type, 'message' => session($key)];
  }
  if (session()->has('toast')) $flash[] = session('toast');
  if ($errors->any() && ! request()->header('X-Livewire')) $flash[] = ['type' => 'error', 'message' => $errors->first()];
@endphp
@if($flash)<template data-td-flash='@json($flash)'></template>@endif
