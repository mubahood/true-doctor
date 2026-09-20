{{-- Finding somebody in what this device holds.

     Only the working set is here — the last 30 days, today's visits and the
     current inpatients. A patient who is not on the device says so plainly,
     because "no results" reads as "this person does not exist" and that is how
     somebody gets registered twice. --}}
<div x-data="fieldPatients">
  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" class="tb-input" x-model="term" @input.debounce.300ms="search()"
             placeholder="Name or patient number…" aria-label="Search patients on this device">
    </div>
    <span class="fm-why" x-show="searching">Looking…</span>
  </div>

  <div class="tb-card">
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Patients held on this device</caption>
        <thead>
          <tr>
            <th>Patient</th>
            <th>Number</th>
            <th>Phone</th>
            <th>Alerts</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <template x-for="patient in results" :key="patient.uuid">
            <tr>
              <td class="tb-fw-500">
                <button type="button" class="tb-rowbtn" @click="open(patient.uuid)"
                        :title="openUuid === patient.uuid ? 'Close' : 'Correct their details'"
                        x-text="patient.first_name + ' ' + patient.last_name"></button>
              </td>
              <td class="muted tb-nowrap">
                <template x-if="patient.patient_no">
                  <span x-text="patient.patient_no"></span>
                </template>
                <template x-if="!patient.patient_no">
                  <span class="fm-provisional">
                    <i class="fas fa-clock" aria-hidden="true"></i> number pending
                  </span>
                </template>
              </td>
              <td class="muted" x-text="patient.phone_1 ?? '—'"></td>
              <td>
                <template x-if="patient.allergies?.length">
                  <span class="badge-tb badge-danger">
                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                    <span x-text="patient.allergies.join(', ')"></span>
                  </span>
                </template>
                <template x-if="!patient.allergies?.length">
                  <span class="muted">—</span>
                </template>
              </td>
              <td class="tb-text-right tb-nowrap">
                <button type="button" class="btn-tb btn-tb-sm" @click="open(patient.uuid)"
                        x-text="openUuid === patient.uuid ? 'Close' : 'Correct'"></button>
              </td>
            </tr>
          </template>

          <template x-if="results.length === 0 && !searching">
            <tr>
              <td colspan="5">
                {{-- "No results" reads as "this person does not exist", and
                     that is how somebody gets registered twice. --}}
                <div class="fm-empty">
                  <p>Nobody on this device matches that.</p>
                  <p class="tb-mt-2">
                    This device holds the last 30 days of patients, today's visits and the current
                    inpatients — not the whole register. If you are sure they exist, they may need a
                    connection to find. <strong>Do not register them again</strong> unless you are
                    certain they are new.
                  </p>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>

  {{-- ── Correcting the open patient ──────────────────────────────── --}}
  <template x-if="openUuid">
    <form class="tb-card tb-mt-4"
          x-data="fieldForm(async function (f) {
            const out = await $store.offline.flows().updatePatient(openUuid, f);
            await search();
            return { message: out.changed ? 'Saved on this device.' : 'Nothing had changed.' };
          })"
          x-init="reset = () => {}"
          @submit.prevent="submit()">
      <div class="tb-card-header">
        <span class="tb-card-title"
              x-text="patient ? ('Correcting ' + patient.first_name + ' ' + patient.last_name) : 'Correcting'"></span>
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

        {{-- A deliberately small set. Name, date of birth and sex are the
             fields a disagreement is never merged on, so correcting them is
             left to the panel where both sides can be seen. --}}
        <div class="tb-form-grid">
          <div class="tb-form-group">
            <label class="tb-label" for="fp-phone">Phone</label>
            <input id="fp-phone" name="phone_1" type="tel" class="tb-input" maxlength="32"
                   x-init="$el.value = patient?.phone_1 ?? ''" @input="clear('phone_1')">
            <template x-if="errors.phone_1">
              <div class="tb-field-error" role="alert" x-text="errors.phone_1"></div>
            </template>
          </div>
          <div class="tb-form-group">
            <label class="tb-label" for="fp-address">Current address</label>
            <input id="fp-address" name="address" type="text" class="tb-input" maxlength="191"
                   x-init="$el.value = patient?.address ?? ''">
          </div>
          <div class="tb-form-group">
            <label class="tb-label" for="fp-ec-name">Emergency contact</label>
            <input id="fp-ec-name" name="emergency_contact_name" type="text" class="tb-input" maxlength="150"
                   x-init="$el.value = patient?.emergency_contact_name ?? ''">
          </div>
          <div class="tb-form-group">
            <label class="tb-label" for="fp-ec-phone">Emergency phone</label>
            <input id="fp-ec-phone" name="emergency_contact_phone" type="tel" class="tb-input" maxlength="32"
                   x-init="$el.value = patient?.emergency_contact_phone ?? ''">
          </div>
          <div class="tb-form-group full">
            <label class="tb-label" for="fp-allergies">Allergies <span class="muted">(comma-separated)</span></label>
            <input id="fp-allergies" name="allergies" type="text" class="tb-input"
                   x-init="$el.value = (patient?.allergies ?? []).join(', ')">
            <div class="tb-field-hint">This is the field somebody reads before giving a drug.</div>
          </div>
        </div>

        <div class="muted tb-small tb-mb-3">
          Name, date of birth and sex are corrected in the main panel. If two people changed one of
          those, a person has to decide which is right — that is not a choice to make on a device
          that cannot see the other side.
        </div>

        <button type="submit" class="btn-tb btn-tb-primary" :disabled="busy">
          <span x-show="!busy"><i class="fas fa-check" aria-hidden="true"></i> Save correction</span>
          <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
  </template>
</div>
