{{-- Registering somebody standing in front of you.

     Laid out exactly like the panel's own patient form
     (`livewire/patients/_fields.blade.php`): the same card sections in the
     same order, the same `tb-form-grid` / `tb-form-group` / `tb-label`, and
     errors under the control they belong to. Somebody who fills this in at a
     bedside and the same form at a desk should not feel they have changed
     systems.

     Every input carries a `name`. That is what makes the form the source of
     truth at submit time rather than an Alpine model — a browser autofill can
     put a value in a box without Alpine ever seeing it, which is how this
     form came to report "a first name is needed" with a name plainly in it.

     No patient number is minted here. The record shows as provisional until
     the server issues one, because a number that later changes is worse than
     no number at all (invariant I-11). --}}
<form x-data="fieldForm(async function (f) {
        const row = await $store.offline.flows().registerPatient(f);
        return { message: row.first_name + ' ' + row.last_name + ' is saved on this device. '
                          + 'Their patient number is issued when this syncs.' };
      })"
      x-init="reset = () => $el.reset()"
      @submit.prevent="submit()">

  <div class="muted tb-small tb-mb-3">
    Saved on this device straight away. The patient number comes from the server when this syncs —
    until then the record is marked <span class="fm-provisional">pending</span>, because a number
    that later changes is worse than no number at all.
  </div>

  {{-- Whole-form problems only. Anything belonging to a field appears under
       that field, the way the panel does it. --}}
  <template x-for="problem in problems" :key="problem">
    <div class="fm-bad" x-text="problem"></div>
  </template>
  <div class="fm-warn" x-show="saved" x-cloak x-text="saved"
       style="background:var(--ok-soft);border-color:var(--ok);color:var(--ok);"></div>

  {{-- ── Identity ─────────────────────────────────────────────────── --}}
  <div class="tb-card tb-mb-3">
    <div class="tb-card-header"><span class="tb-card-title">Identity</span></div>
    <div class="tb-card-body">
      <div class="tb-form-grid">
        <div class="tb-form-group">
          <label class="tb-label tb-required" for="rg-first">First name</label>
          <input id="rg-first" name="first_name" class="tb-input" maxlength="100"
                 @input="clear('first_name')">
          <template x-if="errors.first_name">
            <div class="tb-field-error" role="alert" x-text="errors.first_name"></div>
          </template>
        </div>

        <div class="tb-form-group">
          <label class="tb-label tb-required" for="rg-last">Last name</label>
          <input id="rg-last" name="last_name" class="tb-input" maxlength="100"
                 @input="clear('last_name')">
          <template x-if="errors.last_name">
            <div class="tb-field-error" role="alert" x-text="errors.last_name"></div>
          </template>
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="rg-dob">Date of birth</label>
          {{-- `max` so the picker itself will not offer a future date. The
               rule is checked again on submit and again on the server; this
               is only so the wrong answer is harder to give. --}}
          <input id="rg-dob" name="dob" type="date" class="tb-input" max="{{ now()->toDateString() }}"
                 @input="clear('dob')">
          <template x-if="errors.dob">
            <div class="tb-field-error" role="alert" x-text="errors.dob"></div>
          </template>
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="rg-sex">Sex</label>
          <select id="rg-sex" name="sex" class="tb-select">
            <option value="">—</option>
            @foreach(\App\Enums\PatientSex::cases() as $case)
              <option value="{{ $case->value }}">{{ ucfirst($case->value) }}</option>
            @endforeach
          </select>
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="rg-blood">Blood type</label>
          <select id="rg-blood" name="blood_type" class="tb-select">
            <option value="">—</option>
            @foreach(\App\Http\Requests\PatientRequest::BLOOD_TYPES as $type)
              <option value="{{ $type }}">{{ $type }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </div>
  </div>

  {{-- ── Contact ──────────────────────────────────────────────────── --}}
  <div class="tb-card tb-mb-3">
    <div class="tb-card-header"><span class="tb-card-title">Contact</span></div>
    <div class="tb-card-body">
      <div class="tb-form-grid">
        <div class="tb-form-group">
          <label class="tb-label" for="rg-phone">Phone (primary)</label>
          <input id="rg-phone" name="phone_1" class="tb-input" maxlength="32" inputmode="tel"
                 @input="clear('phone_1')">
          <template x-if="errors.phone_1">
            <div class="tb-field-error" role="alert" x-text="errors.phone_1"></div>
          </template>
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="rg-phone2">Phone (alt)</label>
          <input id="rg-phone2" name="phone_2" class="tb-input" maxlength="32" inputmode="tel">
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="rg-email">Email</label>
          <input id="rg-email" name="email" type="email" class="tb-input" maxlength="150"
                 @input="clear('email')">
          <template x-if="errors.email">
            <div class="tb-field-error" role="alert" x-text="errors.email"></div>
          </template>
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="rg-address">Current address</label>
          <input id="rg-address" name="address" class="tb-input" maxlength="191">
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="rg-home">Home address</label>
          <input id="rg-home" name="home_address" class="tb-input" maxlength="191">
        </div>

        {{-- District is a dropdown of reference data on the panel. It is not
             offered here: the list is not on the device, and a free-text
             district would not match one. It is added at a desk. --}}
      </div>
    </div>
  </div>

  {{-- ── Medical ──────────────────────────────────────────────────── --}}
  <div class="tb-card tb-mb-3">
    <div class="tb-card-header"><span class="tb-card-title">Medical</span></div>
    <div class="tb-card-body">
      <div class="tb-form-grid">
        <div class="tb-form-group">
          <label class="tb-label" for="rg-allergies">Allergies <span class="muted">(comma-separated)</span></label>
          <input id="rg-allergies" name="allergies" class="tb-input" placeholder="Penicillin, Peanuts">
          <div class="tb-field-hint">This is the field somebody reads before giving a drug.</div>
        </div>

        <div class="tb-form-group">
          <label class="tb-label" for="rg-chronic">Chronic conditions <span class="muted">(comma-separated)</span></label>
          <input id="rg-chronic" name="chronic_conditions" class="tb-input" placeholder="Hypertension, Diabetes">
        </div>
      </div>
    </div>
  </div>

  {{-- ── Family & emergency ───────────────────────────────────────── --}}
  <div class="tb-card tb-mb-3">
    <div class="tb-card-header"><span class="tb-card-title">Family &amp; emergency</span></div>
    <div class="tb-card-body">
      <div class="tb-form-grid">
        <div class="tb-form-group">
          <label class="tb-label" for="rg-spouse">Spouse name</label>
          <input id="rg-spouse" name="spouse_name" class="tb-input" maxlength="150">
        </div>
        <div class="tb-form-group">
          <label class="tb-label" for="rg-father">Father name</label>
          <input id="rg-father" name="father_name" class="tb-input" maxlength="150">
        </div>
        <div class="tb-form-group">
          <label class="tb-label" for="rg-mother">Mother name</label>
          <input id="rg-mother" name="mother_name" class="tb-input" maxlength="150">
        </div>
        <div class="tb-form-group">
          <label class="tb-label" for="rg-ec-name">Emergency contact</label>
          <input id="rg-ec-name" name="emergency_contact_name" class="tb-input" maxlength="150">
        </div>
        <div class="tb-form-group">
          <label class="tb-label" for="rg-ec-phone">Emergency phone</label>
          <input id="rg-ec-phone" name="emergency_contact_phone" class="tb-input" maxlength="32" inputmode="tel">
        </div>
      </div>
    </div>
  </div>

  {{-- Insurance and consent are on the panel's form and deliberately not
       here: neither can be acted on without a connection, and a consent
       checkbox recorded on a device hours before it reaches the server is a
       worse record than one taken at a desk. --}}

  <button type="submit" class="btn-tb btn-tb-primary" :disabled="busy">
    <span x-show="!busy"><i class="fas fa-user-plus" aria-hidden="true"></i> Register</span>
    <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
  </button>
</form>
