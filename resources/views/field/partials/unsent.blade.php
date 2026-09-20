{{-- What has not reached the server.

     A pending count in the bar is reassurance; this is the answer to "what,
     exactly?". Laid out like the panel's own operations list: a `tb-card`
     holding a `tb-table`, a `badge-tb` for the state.

     It shows what each record IS, never what is in it — a clinical value in a
     diagnostics list is a second copy under nobody's governance. --}}
<div x-data="{
       rows: [],
       async load() { this.rows = await ($store.offline.flows()?.unsent() ?? []); },
       tone(status) {
         return { pending: 'badge-info', processing: 'badge-info', failed: 'badge-warn',
                  rejected: 'badge-danger', conflict: 'badge-warn' }[status] ?? 'badge-neutral';
       },
       says(status) {
         return {
           pending: 'Waiting to be sent',
           processing: 'Being sent',
           failed: 'Could not be sent — will try again',
           rejected: 'The server refused this',
           conflict: 'Needs a decision',
         }[status] ?? status;
       },
     }"
     x-init="load(); $watch('$store.offline.status', () => load())">

  <div class="tb-filter-bar tb-toolbar">
    <div class="muted tb-small">
      <span x-text="rows.length"></span>
      <span x-text="rows.length === 1 ? 'record' : 'records'"></span> not yet confirmed by the server
    </div>
    <div class="tb-toolbar-actions">
      <button type="button" class="btn-tb btn-tb-primary" x-show="rows.length > 0"
              @click="$store.offline.sync().then(() => load())">
        <i class="fas fa-rotate" aria-hidden="true"></i> Try again now
      </button>
    </div>
  </div>

  <div class="tb-card">
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Records on this device the server has not confirmed</caption>
        <thead>
          <tr>
            <th>What</th>
            <th>State</th>
            <th>Recorded</th>
            <th>Attempts</th>
          </tr>
        </thead>
        <tbody>
          <template x-for="row in rows" :key="row.operation_id">
            <tr>
              <td class="tb-fw-500" x-text="row.entity.replace('_', ' ')"></td>
              <td>
                <span class="badge-tb" :class="tone(row.status)" x-text="says(row.status)"></span>
                {{-- The reason, when the server gave one. Never the payload. --}}
                <template x-if="row.last_error">
                  <span class="tb-row-sub" x-text="row.last_error"></span>
                </template>
              </td>
              <td class="muted tb-nowrap" x-text="new Date(row.at).toLocaleString()"></td>
              <td class="muted" x-text="row.retry_count > 0 ? row.retry_count : '—'"></td>
            </tr>
          </template>

          <template x-if="rows.length === 0">
            <tr>
              <td colspan="4">
                <div class="fm-empty">
                  <i class="fas fa-check" aria-hidden="true" style="color:var(--ok);"></i>
                  Everything on this device has reached the server.
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>

  <div class="muted tb-small tb-mt-3" x-show="rows.length > 0">
    None of this is lost. It stays on this device until the server confirms it — through a reload,
    a restart, or a week in a drawer.
  </div>
</div>
