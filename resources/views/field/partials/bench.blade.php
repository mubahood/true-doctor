{{-- Tests waiting for a number.

     Laid out like the panel's lab worklist: a filter bar, then a `tb-card`
     holding a `tb-table`, the test name as a `tb-rowbtn`, the reference range
     as its own column because it is what a technician checks the number
     against.

     An UPDATE against a line the server created — a doctor ordered the test,
     and only the value in it comes from the bench. A result already recorded
     is never overwritten from here: two benches reporting different numbers
     for one specimen is a question somebody answers with the specimen in
     front of them, not a thing a form decides. --}}
<div x-data="fieldBench" x-init="$watch('$store.offline.status', () => load())">

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" class="tb-input" x-model="term" placeholder="Search the worklist…"
             aria-label="Search the tests waiting on this device">
    </div>
    <div class="muted tb-small">
      <span x-text="filtered.length"></span>
      <span x-text="filtered.length === 1 ? 'test' : 'tests'"></span> waiting
    </div>
  </div>

  <div class="tb-card">
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Tests waiting for a result on this device</caption>
        <thead>
          <tr>
            <th>Test</th>
            <th>Normal range</th>
            <th>Ordered</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <template x-for="item in filtered" :key="item.uuid">
            <tr>
              <td class="tb-fw-500">
                <button type="button" class="tb-rowbtn" @click="open(item.uuid)"
                        :title="openUuid === item.uuid ? 'Close' : 'Report a result'"
                        x-text="item.name"></button>
              </td>
              <td class="muted">
                <template x-if="item.reference_range">
                  <span>
                    <span x-text="item.reference_range"></span>
                    <span x-text="item.unit ?? ''"></span>
                  </span>
                </template>
                {{-- Said plainly rather than left blank: without a range the
                     server cannot flag the result, and a technician should
                     know that before they type one. --}}
                <template x-if="!item.reference_range">
                  <span class="fm-provisional">No range set — cannot be flagged</span>
                </template>
              </td>
              <td class="muted tb-nowrap"
                  x-text="item.created_at
                    ? new Date(item.created_at).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
                    : '—'"></td>
              <td class="tb-text-right tb-nowrap">
                <button type="button" class="btn-tb btn-tb-sm" @click="open(item.uuid)"
                        x-text="openUuid === item.uuid ? 'Close' : 'Report'"></button>
              </td>
            </tr>
          </template>

          <template x-if="filtered.length === 0">
            <tr>
              <td colspan="4">
                <div class="fm-empty" x-show="worklist.length === 0">
                  Nothing is waiting to be reported. Sync while you have a connection to bring the
                  worklist down.
                </div>
                <div class="fm-empty" x-show="worklist.length > 0" x-cloak>
                  No test on the worklist matches that.
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>

  {{-- ── Reporting the open test ──────────────────────────────────── --}}
  <template x-if="openUuid">
    <form class="tb-card tb-mt-4"
          x-data="fieldForm(async function (f) {
            await $store.offline.flows().reportResult(openUuid, f);
            await load();
            return { message: 'Result recorded on this device.' };
          })"
          x-init="reset = () => $el.reset()"
          @submit.prevent="submit()">
      <div class="tb-card-header">
        <span class="tb-card-title" x-text="'Result · ' + (item?.name ?? '')"></span>
        <button type="button" class="btn-tb btn-tb-sm" @click="open(openUuid)">
          <i class="fas fa-xmark" aria-hidden="true"></i> Close
        </button>
      </div>
      <div class="tb-card-body">
        <template x-for="p in problems" :key="p">
          <div class="fm-bad" x-text="p"></div>
        </template>
        <div class="fm-warn" x-show="saved" x-cloak x-text="saved"
             style="background:var(--ok-soft);border-color:var(--ok);color:var(--ok);"></div>

        <div class="tb-form-grid">
          <div class="tb-form-group">
            <label class="tb-label tb-required" for="bn-value">Result</label>
            <input id="bn-value" name="result_value" type="text" class="tb-input" maxlength="191"
                   @input="clear('result_value')">
            <template x-if="errors.result_value">
              <div class="tb-field-error" role="alert" x-text="errors.result_value"></div>
            </template>
            <template x-if="item?.reference_range">
              <div class="tb-field-hint">
                Normal <span x-text="item.reference_range"></span>
                <span x-text="item.unit ?? ''"></span>
              </div>
            </template>
          </div>

          <div class="tb-form-group">
            <label class="tb-label" for="bn-flag">Flag</label>
            <select id="bn-flag" name="result_flag" class="tb-select">
              <option value="">—</option>
              <option value="normal">Normal</option>
              <option value="low">Low</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>

          <div class="tb-form-group full">
            <label class="tb-label" for="bn-notes">Notes</label>
            <input id="bn-notes" name="result_notes" type="text" class="tb-input" maxlength="191">
            <div class="tb-field-hint">
              Anything about the specimen somebody reading this would need to know.
            </div>
          </div>
        </div>

        <div class="muted tb-small tb-mb-3">
          Recorded against your name and this device. Once it reaches the server it cannot be changed
          from here — a correction is made on the bench worklist, where both values can be seen.
        </div>

        <button type="submit" class="btn-tb btn-tb-primary" :disabled="busy">
          <span x-show="!busy"><i class="fas fa-flask" aria-hidden="true"></i> Record result</span>
          <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
  </template>
</div>
