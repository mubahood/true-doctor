{{--
  A drawing of the panel.

  Not a screenshot: a screenshot goes stale the week the design changes, needs
  a real hospital's data in it or an obviously fake one, and weighs more than
  the rest of the page put together. This is built from the same design tokens
  the panel itself uses, so it ages with the product instead of against it.

  Decorative, and marked so. Every figure in it is plainly illustrative and
  none of it is a claim the page has to stand behind — the numbers are the
  shape of a small clinic's morning, nothing more.
--}}
<div class="shot" role="img" aria-label="The True-Doctor dashboard: today's figures, and the visits currently open.">
  <div class="shot-bar" aria-hidden="true">
    <i></i><i></i><i></i>
    <span>true-doctor.online/admin</span>
  </div>

  <div class="shot-body" aria-hidden="true">
    <nav class="shot-side">
      <b>Clinical</b>
      <a class="on"><i class="fas fa-gauge-high"></i> Dashboard</a>
      <a><i class="fas fa-user-injured"></i> Patients</a>
      <a><i class="fas fa-stethoscope"></i> Visits</a>
      <a><i class="fas fa-calendar-check"></i> Appointments</a>
      <a><i class="fas fa-bed"></i> Admissions</a>
      <b style="padding-top:12px;">Money</b>
      <a><i class="fas fa-file-invoice-dollar"></i> Invoices</a>
      <a><i class="fas fa-chart-line"></i> Reports</a>
    </nav>

    <div class="shot-main">
      <div class="shot-kpis">
        <div class="shot-kpi"><em>Seen today</em><strong>38</strong></div>
        <div class="shot-kpi"><em>Received</em><strong class="ok">$1,240</strong></div>
        <div class="shot-kpi"><em>Outstanding</em><strong class="warn">$310</strong></div>
        <div class="shot-kpi"><em>Beds free</em><strong>7 / 33</strong></div>
      </div>

      <div class="shot-table">
        <div class="r h"><span>Patient</span><span>Stage</span><span>Doctor</span><span>Balance</span></div>
        <div class="r"><span>Nakato, Sarah</span><span><b class="shot-pill a">Consulting</b></span><span>Dr. Alice</span><span>$25.00</span></div>
        <div class="r"><span>Okumu, Brian</span><span><b class="shot-pill c">Awaiting lab</b></span><span>Dr. Alice</span><span>$47.00</span></div>
        <div class="r"><span>Nabwire, Margaret</span><span><b class="shot-pill c">Payment</b></span><span>Dr. Bob</span><span>$18.00</span></div>
        <div class="r"><span>Wasswa, Denis</span><span><b class="shot-pill b">Settled</b></span><span>Dr. Alice</span><span>$0.00</span></div>
        <div class="r"><span>Kemigisha, Agnes</span><span><b class="shot-pill a">Triage</b></span><span>—</span><span>$0.00</span></div>
      </div>
    </div>
  </div>
</div>

<p class="shot-cap">The dashboard a hospital administrator opens on. Figures shown are illustrative.</p>
