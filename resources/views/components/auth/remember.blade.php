{{--
  "Keep me signed in", ticked.

  The hidden companion is the whole point: an unticked checkbox posts nothing,
  so without it the server cannot tell "this person unticked the box" from "this
  client never drew one". Since the box is drawn ticked, the ambiguous case has
  to resolve to ON — and this field is what makes the unticked case
  unambiguous, so unticking really does mean this shift only.

  LoginRequest::remembers() is the other half.
--}}
<input type="hidden" name="remember_present" value="1">

<label class="a-check">
  <input type="checkbox" name="remember" value="1" @checked(old('remember', true))>
  <span>
    Keep me signed in on this device
    <em>For {{ \App\Support\StaffSession::rememberLabel() }}. Untick on a shared or public computer.</em>
  </span>
</label>
