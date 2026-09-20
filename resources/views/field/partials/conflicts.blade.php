{{-- Somebody else changed it first.

     The server refuses a write when the record moved underneath this device,
     and those refusals pile up here until a person decides. Two answers and
     only two: a third would be a merge, and a merge is what the server already
     tried before it gave up and asked. --}}
<div x-data="fieldConflicts" x-init="$watch('$store.offline.status', () => load())">

  <div class="tb-filter-bar tb-toolbar">
    <div class="muted tb-small">
      <span x-text="items.length"></span>
      <span x-text="items.length === 1 ? 'decision' : 'decisions'"></span> waiting on a person
    </div>
  </div>

  <template x-if="items.length === 0">
    <div class="tb-card"><div class="tb-card-body">
      <div class="fm-empty">
        <i class="fas fa-check" aria-hidden="true" style="color:var(--ok);"></i>
        Nothing is waiting on a decision.
      </div>
    </div></div>
  </template>

  <template x-for="c in items" :key="c.id">
    <div class="tb-card tb-mb-3">
      <div class="tb-card-header">
        <span class="tb-card-title">
          Somebody else changed this <span x-text="c.entity.replace('_', ' ')"></span>
        </span>
        <span class="badge-tb badge-warn">Needs a decision</span>
      </div>
      <div class="tb-card-body">
      <div class="muted tb-small tb-mb-3">
        You changed it on this device
        <span x-text="new Date(c.created_at).toLocaleString()"></span>, and it had already been
        changed on the server. Nothing was overwritten — choose which is right.
        <template x-if="c.merged.length">
          <span>
            (The rest of your change was saved:
            <span x-text="c.merged.join(', ').replace(/_/g, ' ')"></span>.)
          </span>
        </template>
      </div>

      <div class="tb-table-wrap tb-mb-3">
      <table class="tb-table">
        <thead><tr><th>Field</th><th>On this device</th><th>On the server</th></tr></thead>
        <tbody>
          <template x-for="f in c.fields" :key="f.field">
            <tr>
              <td class="tb-fw-500" x-text="f.field.replace(/_/g, ' ')"></td>
              <td x-text="show(f.mine)"></td>
              <td x-text="show(f.theirs)"></td>
            </tr>
          </template>
        </tbody>
      </table>
      </div>

      <div class="tb-flex" style="gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn-tb btn-tb-primary" :disabled="busy === c.id"
                @click="decide(c.id, true)">
          <i class="fas fa-mobile-screen" aria-hidden="true"></i> Mine is right
        </button>
        <button type="button" class="btn-tb" :disabled="busy === c.id"
                @click="decide(c.id, false)">
          <i class="fas fa-server" aria-hidden="true"></i> The server's is right
        </button>
      </div>

      <div class="muted tb-small tb-mt-2">
        Either way this is recorded against your name and this device.
        <strong>Mine is right</strong> sends your value again, against the version the server holds now.
      </div>
      </div>
    </div>
  </template>
</div>
