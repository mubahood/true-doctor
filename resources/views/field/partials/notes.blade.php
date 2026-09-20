{{-- The clinical narrative: what you found, and what you think it is.

     Laid out like the panel's visit list — a filter bar, then a `tb-card`
     holding a `tb-table` — and the note itself like the panel's clinical
     panel, with the error under the control it belongs to.

     An UPDATE of a visit somebody else opened. A visit gets its number and
     its consultation charge at reception, so nothing here starts one — and a
     visit that has moved on to billing is not offered at all, because
     amending a narrative underneath an invoice a patient has already been
     shown is done in the panel, where the bill is visible. --}}
<div x-data="fieldNotes" x-init="$watch('$store.offline.status', () => load())">

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" class="tb-input" x-model="term" placeholder="Search today's visits…"
             aria-label="Search the open visits on this device">
    </div>
    <div class="muted tb-small">
      <span x-text="filtered.length"></span>
      <span x-text="filtered.length === 1 ? 'open visit' : 'open visits'"></span> on this device
    </div>
  </div>

  <div class="tb-card">
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Open visits held on this device</caption>
        <thead>
          <tr>
            <th>Patient</th>
            <th>Visit</th>
            <th>Complaints</th>
            <th>Diagnosis</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <template x-for="v in filtered" :key="v.uuid">
            <tr>
              <td class="tb-fw-500">
                <button type="button" class="tb-rowbtn" @click="open(v.uuid)"
                        :title="openUuid === v.uuid ? 'Close' : 'Write a note'"
                        x-text="v.patient
                          ? (v.patient.first_name + ' ' + v.patient.last_name)
                          : 'Unknown patient'"></button>
              </td>
              <td class="muted tb-nowrap" x-text="v.visit_no ?? 'No number yet'"></td>
              <td class="muted" x-text="v.complaints ?? '—'"></td>
              <td>
                <template x-if="v.diagnosis">
                  <span class="tb-fw-500" x-text="v.diagnosis"></span>
                </template>
                <template x-if="!v.diagnosis">
                  <span class="muted">Not yet recorded</span>
                </template>
              </td>
              <td class="tb-text-right tb-nowrap">
                <button type="button" class="btn-tb btn-tb-sm" @click="open(v.uuid)"
                        x-text="openUuid === v.uuid ? 'Close' : 'Write'"></button>
              </td>
            </tr>
          </template>

          <template x-if="filtered.length === 0">
            <tr>
              <td colspan="5">
                <div class="fm-empty" x-show="visits.length === 0">
                  No open visits on this device. Sync while you have a connection to bring today's down.
                </div>
                <div class="fm-empty" x-show="visits.length > 0" x-cloak>
                  No visit matches that.
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>

  {{-- ── Writing on the open visit ────────────────────────────────── --}}
  <template x-if="openUuid">
    <form class="tb-card tb-mt-4"
          x-data="fieldForm(async function (f) {
            const { changed } = await $store.offline.flows().writeClinicalNote(openUuid, f);
            await load();
            return { message: changed ? 'Note saved on this device.' : 'Nothing had changed, so nothing was sent.' };
          })"
          x-init="reset = () => {}"
          @submit.prevent="submit()">
      <div class="tb-card-header">
        <span class="tb-card-title"
              x-text="visit?.patient
                ? ('Note for ' + visit.patient.first_name + ' ' + visit.patient.last_name)
                : 'Note'"></span>
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

        {{-- Opened holding what is already on the visit, not an empty box:
             writing over a colleague's sentence unseen is exactly the
             conflict this whole mechanism exists to avoid. Set ONCE with
             `x-init`, so a background refresh cannot overwrite what somebody
             is halfway through typing. --}}
        <div class="tb-form-group">
          <label class="tb-label" for="nt-complaints">Complaints</label>
          <textarea id="nt-complaints" name="complaints" class="tb-textarea" rows="2" maxlength="2000"
                    x-init="$el.value = visit?.complaints ?? ''" @input="clear('complaints')"></textarea>
          <template x-if="errors.complaints">
            <div class="tb-field-error" role="alert" x-text="errors.complaints"></div>
          </template>
          <div class="tb-field-hint">What the patient came in saying, in their terms.</div>
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="nt-diagnosis">Diagnosis</label>
          <textarea id="nt-diagnosis" name="diagnosis" class="tb-textarea" rows="2" maxlength="2000"
                    x-init="$el.value = visit?.diagnosis ?? ''" @input="clear('diagnosis')"></textarea>
          <template x-if="errors.diagnosis">
            <div class="tb-field-error" role="alert" x-text="errors.diagnosis"></div>
          </template>
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="nt-remarks">Remarks</label>
          <textarea id="nt-remarks" name="doctor_remarks" class="tb-textarea" rows="3" maxlength="2000"
                    x-init="$el.value = visit?.doctor_remarks ?? ''" @input="clear('doctor_remarks')"></textarea>
          <template x-if="errors.doctor_remarks">
            <div class="tb-field-error" role="alert" x-text="errors.doctor_remarks"></div>
          </template>
          <div class="tb-field-hint">
            Advice given, what to watch for, what to do if it does not settle.
          </div>
        </div>

        <div class="muted tb-small tb-mb-3">
          Only what you change is sent. If somebody has written on this visit since your device last
          synced, the part you both changed is brought to you under <strong>Decisions</strong> rather
          than one of you silently losing it.
        </div>

        <button type="submit" class="btn-tb btn-tb-primary" :disabled="busy">
          <span x-show="!busy"><i class="fas fa-pen-nib" aria-hidden="true"></i> Save note</span>
          <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
  </template>
</div>
