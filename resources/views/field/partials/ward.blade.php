{{-- The ward round: who is in a bed, and recording against them.

     Laid out like the panel's own admissions list
     (`livewire/admissions/index.blade.php`): a filter bar, then a `tb-card`
     holding a `tb-table`, the patient's name as a `tb-rowbtn` that opens the
     row. Somebody who works the ward at a desk and the same ward at a bedside
     should recognise the screen.

     Everything recorded here is append-only, which is why it is safe offline:
     two people recording two rounds recorded two rounds, and there is nothing
     to merge or overwrite. --}}
<div x-data="fieldWard">

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" class="tb-input" x-model="term" placeholder="Search this ward…"
             aria-label="Search the inpatients on this device">
    </div>
    <div class="muted tb-small">
      <span x-text="filtered.length"></span>
      <span x-text="filtered.length === 1 ? 'inpatient' : 'inpatients'"></span> on this device
    </div>
  </div>

  <div class="tb-card">
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Inpatients held on this device</caption>
        <thead>
          <tr>
            <th>Patient</th>
            <th>Ward / Bed</th>
            <th>Admitted</th>
            <th>Alerts</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <template x-for="a in filtered" :key="a.uuid">
            <tr>
              <td class="tb-fw-500">
                <button type="button" class="tb-rowbtn" @click="open(a.uuid)"
                        :title="openUuid === a.uuid ? 'Close this stay' : 'Record against this stay'"
                        x-text="a.patient ? (a.patient.first_name + ' ' + a.patient.last_name) : 'Unknown patient'"></button>
                <span class="tb-row-sub">
                  <template x-if="a.patient?.patient_no">
                    <span x-text="a.patient.patient_no"></span>
                  </template>
                  <template x-if="!a.patient?.patient_no">
                    <span class="fm-provisional">
                      <i class="fas fa-clock" aria-hidden="true"></i> number pending
                    </span>
                  </template>
                </span>
              </td>
              <td class="muted" x-text="a.bed_name ?? '—'"></td>
              <td class="muted tb-nowrap"
                  x-text="a.admitted_at
                    ? new Date(a.admitted_at).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
                    : '—'"></td>
              <td>
                {{-- Allergies are the one thing somebody reads before giving a
                     drug, so they are a column and not a detail behind a click. --}}
                <template x-if="a.patient?.allergies?.length">
                  <span class="badge-tb badge-danger">
                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                    <span x-text="a.patient.allergies.join(', ')"></span>
                  </span>
                </template>
                <template x-if="!a.patient?.allergies?.length">
                  <span class="muted">—</span>
                </template>
              </td>
              <td class="tb-text-right tb-nowrap">
                <button type="button" class="btn-tb btn-tb-sm" @click="open(a.uuid)"
                        x-text="openUuid === a.uuid ? 'Close' : 'Record'"></button>
              </td>
            </tr>
          </template>

          <template x-if="filtered.length === 0">
            <tr>
              <td colspan="5">
                <div class="fm-empty" x-show="admissions.length === 0">
                  No inpatients on this device. Sync while you have a connection to bring the ward down.
                </div>
                <div class="fm-empty" x-show="admissions.length > 0" x-cloak>
                  Nobody on this ward matches that.
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>

  {{-- ── Recording against the open stay ──────────────────────────────
       Below the list rather than inside the row: the forms are long, and a
       table cell that grows to hold three of them stops being a table. --}}
  <template x-if="openUuid">
    <div class="tb-mt-4">

      <div class="tb-page-header" style="margin-bottom:12px;">
        <div>
          <h2 style="font-size:16px;font-weight:600;"
              x-text="stay?.patient
                ? ('Recording for ' + stay.patient.first_name + ' ' + stay.patient.last_name)
                : 'Recording'"></h2>
          <div class="muted tb-small">
            <span x-text="stay?.bed_name ?? 'No bed'"></span> · everything below is saved on this
            device and sent when the connection returns.
          </div>
        </div>
        <div class="tb-flex">
          <button type="button" class="btn-tb btn-tb-sm" @click="open(openUuid)">
            <i class="fas fa-xmark" aria-hidden="true"></i> Close
          </button>
        </div>
      </div>

      {{-- ── Vitals ─────────────────────────────────────────────────── --}}
      <form class="tb-card tb-mb-3"
            x-data="fieldForm(async function (f) {
              await $store.offline.flows().recordVitals(openUuid, f);
              await refresh();
              return { message: 'Vitals recorded on this device.' };
            })"
            x-init="reset = () => $el.reset()"
            @submit.prevent="submit()">
        <div class="tb-card-header"><span class="tb-card-title">Vitals</span></div>
        <div class="tb-card-body">
          <template x-for="p in problems" :key="p">
            <div class="fm-bad" x-text="p"></div>
          </template>
          <div class="fm-warn" x-show="saved" x-cloak x-text="saved"
               style="background:var(--ok-soft);border-color:var(--ok);color:var(--ok);"></div>

          <div class="tb-form-grid">
            <div class="tb-form-group">
              <label class="tb-label" for="wv-temp">Temperature (°C)</label>
              <input id="wv-temp" name="temperature" type="number" step="0.1" class="tb-input"
                     inputmode="decimal" @input="clear('temperature')">
              <template x-if="errors.temperature">
                <div class="tb-field-error" role="alert" x-text="errors.temperature"></div>
              </template>
            </div>
            <div class="tb-form-group">
              <label class="tb-label" for="wv-pulse">Pulse</label>
              <input id="wv-pulse" name="pulse" type="number" class="tb-input"
                     inputmode="numeric" @input="clear('pulse')">
              <template x-if="errors.pulse">
                <div class="tb-field-error" role="alert" x-text="errors.pulse"></div>
              </template>
            </div>
            <div class="tb-form-group">
              <label class="tb-label" for="wv-bp">Blood pressure</label>
              <input id="wv-bp" name="blood_pressure" type="text" class="tb-input" placeholder="120/80">
            </div>
            <div class="tb-form-group">
              <label class="tb-label" for="wv-rr">Breathing rate</label>
              <input id="wv-rr" name="respiratory_rate" type="number" class="tb-input"
                     inputmode="numeric" @input="clear('respiratory_rate')">
              <template x-if="errors.respiratory_rate">
                <div class="tb-field-error" role="alert" x-text="errors.respiratory_rate"></div>
              </template>
            </div>
            <div class="tb-form-group">
              <label class="tb-label" for="wv-spo2">Oxygen (%)</label>
              <input id="wv-spo2" name="spo2" type="number" class="tb-input"
                     inputmode="numeric" @input="clear('spo2')">
              <template x-if="errors.spo2">
                <div class="tb-field-error" role="alert" x-text="errors.spo2"></div>
              </template>
            </div>
            <div class="tb-form-group full">
              <label class="tb-label" for="wv-note">Anything else</label>
              <input id="wv-note" name="note" type="text" class="tb-input" maxlength="2000">
            </div>
          </div>

          <button type="submit" class="btn-tb btn-tb-primary" :disabled="busy">
            <span x-show="!busy"><i class="fas fa-check" aria-hidden="true"></i> Record vitals</span>
            <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>

      {{-- ── Nursing note ───────────────────────────────────────────── --}}
      <form class="tb-card tb-mb-3"
            x-data="fieldForm(async function (f) {
              await $store.offline.flows().recordNursingNote(openUuid, f.note);
              await refresh();
              return { message: 'Note recorded on this device.' };
            })"
            x-init="reset = () => $el.reset()"
            @submit.prevent="submit()">
        <div class="tb-card-header"><span class="tb-card-title">Nursing note</span></div>
        <div class="tb-card-body">
          <template x-for="p in problems" :key="p">
            <div class="fm-bad" x-text="p"></div>
          </template>
          <div class="fm-warn" x-show="saved" x-cloak x-text="saved"
               style="background:var(--ok-soft);border-color:var(--ok);color:var(--ok);"></div>

          <div class="tb-form-group">
            <label class="tb-label" for="wn-note">What happened</label>
            <textarea id="wn-note" name="note" class="tb-textarea" rows="3" maxlength="5000"
                      @input="clear('note')"></textarea>
            <template x-if="errors.note">
              <div class="tb-field-error" role="alert" x-text="errors.note"></div>
            </template>
            <div class="tb-field-hint">
              Notes are never edited. A correction is a new note that supersedes this one.
            </div>
          </div>

          <button type="submit" class="btn-tb btn-tb-primary" :disabled="busy">
            <span x-show="!busy"><i class="fas fa-pen-to-square" aria-hidden="true"></i> Record note</span>
            <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>

      {{-- ── Medication ─────────────────────────────────────────────── --}}
      <form class="tb-card tb-mb-3"
            x-data="fieldForm(async function (f) {
              await $store.offline.flows().recordMedication(openUuid, f);
              await refresh();
              return { message: 'Recorded on this device.' };
            })"
            x-init="reset = () => $el.reset()"
            @submit.prevent="submit()">
        <div class="tb-card-header"><span class="tb-card-title">Medication</span></div>
        <div class="tb-card-body">
          <template x-for="p in problems" :key="p">
            <div class="fm-bad" x-text="p"></div>
          </template>
          <div class="fm-warn" x-show="saved" x-cloak x-text="saved"
               style="background:var(--ok-soft);border-color:var(--ok);color:var(--ok);"></div>

          <div class="tb-form-grid">
            <div class="tb-form-group">
              <label class="tb-label tb-required" for="wm-drug">Drug</label>
              <input id="wm-drug" name="drug_name" type="text" class="tb-input" maxlength="150"
                     @input="clear('drug_name')">
              <template x-if="errors.drug_name">
                <div class="tb-field-error" role="alert" x-text="errors.drug_name"></div>
              </template>
            </div>
            <div class="tb-form-group">
              <label class="tb-label" for="wm-dose">Dose</label>
              <input id="wm-dose" name="dose" type="text" class="tb-input" maxlength="60" placeholder="1g">
            </div>
            <div class="tb-form-group">
              <label class="tb-label" for="wm-route">Route</label>
              <input id="wm-route" name="route" type="text" class="tb-input" maxlength="40" placeholder="oral">
            </div>
            <div class="tb-form-group">
              {{-- No default. "Not given, patient refused" is as important a
                   record as "given" and far more important than a blank, so
                   it has to be chosen rather than accepted by accident. --}}
              <label class="tb-label tb-required" for="wm-status">Was it given?</label>
              <select id="wm-status" name="status" class="tb-select" @change="clear('status')">
                <option value="">— choose —</option>
                <option value="given">Given</option>
                <option value="refused">Refused by the patient</option>
                <option value="held">Held</option>
                <option value="missed">Missed</option>
              </select>
              <template x-if="errors.status">
                <div class="tb-field-error" role="alert" x-text="errors.status"></div>
              </template>
            </div>
            <div class="tb-form-group full">
              <label class="tb-label" for="wm-note">Anything else</label>
              <input id="wm-note" name="note" type="text" class="tb-input" maxlength="2000">
            </div>
          </div>

          <button type="submit" class="btn-tb btn-tb-primary" :disabled="busy">
            <span x-show="!busy"><i class="fas fa-pills" aria-hidden="true"></i> Record</span>
            <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>

      {{-- ── What is already on this chart ──────────────────────────── --}}
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">On this chart</span></div>
        <div class="tb-card-body">
          <template x-if="chart && chart.vitals.length === 0 && chart.notes.length === 0 && chart.medications.length === 0">
            <div class="muted tb-small">Nothing recorded yet.</div>
          </template>
          <ul class="tb-peek-trail" x-show="chart">
            <template x-for="r in (chart?.vitals ?? []).slice(0, 5)" :key="r.uuid">
              <li>
                <span class="tb-peek-step">
                  Vitals ·
                  <span x-text="[r.temperature && r.temperature + '°C', r.pulse && r.pulse + ' bpm', r.blood_pressure].filter(Boolean).join(' · ')"></span>
                </span>
                <span class="tb-peek-meta" x-text="new Date(r.created_at).toLocaleString()"></span>
              </li>
            </template>
            <template x-for="n in (chart?.notes ?? []).slice(0, 5)" :key="n.uuid">
              <li>
                <span class="tb-peek-step" x-text="n.note"></span>
                <span class="tb-peek-meta" x-text="new Date(n.created_at).toLocaleString()"></span>
              </li>
            </template>
            <template x-for="d in (chart?.medications ?? []).slice(0, 5)" :key="d.uuid">
              <li>
                <span class="tb-peek-step">
                  <span x-text="d.drug_name"></span> <span x-text="d.dose ?? ''"></span> ·
                  <span x-text="d.status"></span>
                </span>
                <span class="tb-peek-meta" x-text="new Date(d.created_at).toLocaleString()"></span>
              </li>
            </template>
          </ul>
        </div>
      </div>
    </div>
  </template>
</div>
