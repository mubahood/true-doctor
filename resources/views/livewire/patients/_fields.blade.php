{{-- Shared patient form fields — included by the full-page editor and the index modal.
     Expects: $sexes, $bloodTypes, $statuses, $districts. Bound to component/trait properties. --}}
<div class="tb-card" style="margin-bottom:18px;">
  <div class="tb-card-header"><span class="tb-card-title">Identity</span></div>
  <div class="tb-card-body">
    <div class="tb-form-grid">
      <div class="tb-form-group">
        <label class="tb-label">First name *</label>
        <input wire:model="first_name" class="tb-input" required>
        @error('first_name')<div class="tb-field-error">{{ $message }}</div>@enderror
      </div>
      <div class="tb-form-group">
        <label class="tb-label">Last name *</label>
        <input wire:model="last_name" class="tb-input" required>
        @error('last_name')<div class="tb-field-error">{{ $message }}</div>@enderror
      </div>
      <div class="tb-form-group">
        <label class="tb-label">Date of birth</label>
        <input type="date" wire:model="dob" class="tb-input">
        @error('dob')<div class="tb-field-error">{{ $message }}</div>@enderror
      </div>
      <div class="tb-form-group">
        <label class="tb-label">Sex</label>
        <select wire:model="sex" class="tb-select">
          <option value="">—</option>
          @foreach($sexes as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
        @error('sex')<div class="tb-field-error">{{ $message }}</div>@enderror
      </div>
      <div class="tb-form-group">
        <label class="tb-label">Blood type</label>
        <select wire:model="blood_type" class="tb-select">
          <option value="">—</option>
          @foreach($bloodTypes as $bt)<option value="{{ $bt }}">{{ $bt }}</option>@endforeach
        </select>
        @error('blood_type')<div class="tb-field-error">{{ $message }}</div>@enderror
      </div>
      <div class="tb-form-group">
        <label class="tb-label">Status</label>
        <select wire:model="status" class="tb-select">
          @foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
        </select>
        @error('status')<div class="tb-field-error">{{ $message }}</div>@enderror
      </div>
    </div>
  </div>
</div>

<div class="tb-card" style="margin-bottom:18px;">
  <div class="tb-card-header"><span class="tb-card-title">Contact</span></div>
  <div class="tb-card-body">
    <div class="tb-form-grid">
      <div class="tb-form-group"><label class="tb-label">Phone (primary)</label><input wire:model="phone_1" class="tb-input">@error('phone_1')<div class="tb-field-error">{{ $message }}</div>@enderror</div>
      <div class="tb-form-group"><label class="tb-label">Phone (alt)</label><input wire:model="phone_2" class="tb-input">@error('phone_2')<div class="tb-field-error">{{ $message }}</div>@enderror</div>
      <div class="tb-form-group"><label class="tb-label">Email</label><input type="email" wire:model="email" class="tb-input">@error('email')<div class="tb-field-error">{{ $message }}</div>@enderror</div>
      <div class="tb-form-group">
        <label class="tb-label">District</label>
        <select wire:model="district_id" class="tb-select">
          <option value="">—</option>
          @foreach($districts as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
        @error('district_id')<div class="tb-field-error">{{ $message }}</div>@enderror
      </div>
      <div class="tb-form-group"><label class="tb-label">Current address</label><input wire:model="address" class="tb-input">@error('address')<div class="tb-field-error">{{ $message }}</div>@enderror</div>
      <div class="tb-form-group"><label class="tb-label">Home address</label><input wire:model="home_address" class="tb-input">@error('home_address')<div class="tb-field-error">{{ $message }}</div>@enderror</div>
    </div>
  </div>
</div>

<div class="tb-card" style="margin-bottom:18px;">
  <div class="tb-card-header"><span class="tb-card-title">Medical</span></div>
  <div class="tb-card-body">
    <div class="tb-form-grid">
      <div class="tb-form-group"><label class="tb-label">Allergies <span class="muted">(comma-separated)</span></label><input wire:model="allergies" class="tb-input" placeholder="Penicillin, Peanuts">@error('allergies')<div class="tb-field-error">{{ $message }}</div>@enderror</div>
      <div class="tb-form-group"><label class="tb-label">Chronic conditions <span class="muted">(comma-separated)</span></label><input wire:model="chronic_conditions" class="tb-input" placeholder="Hypertension, Diabetes">@error('chronic_conditions')<div class="tb-field-error">{{ $message }}</div>@enderror</div>
    </div>
  </div>
</div>

<div class="tb-card" style="margin-bottom:18px;">
  <div class="tb-card-header"><span class="tb-card-title">Family &amp; emergency</span></div>
  <div class="tb-card-body">
    <div class="tb-form-grid">
      <div class="tb-form-group"><label class="tb-label">Spouse name</label><input wire:model="spouse_name" class="tb-input"></div>
      <div class="tb-form-group"><label class="tb-label">Father name</label><input wire:model="father_name" class="tb-input"></div>
      <div class="tb-form-group"><label class="tb-label">Mother name</label><input wire:model="mother_name" class="tb-input"></div>
      <div class="tb-form-group"><label class="tb-label">Emergency contact</label><input wire:model="emergency_contact_name" class="tb-input"></div>
      <div class="tb-form-group"><label class="tb-label">Emergency phone</label><input wire:model="emergency_contact_phone" class="tb-input"></div>
    </div>
  </div>
</div>

<div class="tb-card" style="margin-bottom:18px;">
  <div class="tb-card-header"><span class="tb-card-title">Insurance &amp; consent</span></div>
  <div class="tb-card-body">
    <div class="tb-form-grid">
      <div class="tb-form-group"><label class="tb-label">Insurance provider</label><input wire:model="insurance_provider" class="tb-input"></div>
      <div class="tb-form-group"><label class="tb-label">Insurance member no.</label><input wire:model="insurance_member_no" class="tb-input"></div>
      <div class="tb-form-group full"><label class="tb-label">Notes</label><textarea wire:model="notes" class="tb-textarea" rows="2"></textarea></div>
      <div class="tb-form-group full">
        <label class="tb-check-group"><input type="checkbox" wire:model="consent_given" value="1"> Patient consents to data processing</label>
      </div>
    </div>
  </div>
</div>
