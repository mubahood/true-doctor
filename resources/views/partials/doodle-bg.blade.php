{{-- Faded health-themed doodle background, tiled across the whole page. --}}
<svg class="bgdoodle" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <pattern id="tdDoodles" width="300" height="300" patternUnits="userSpaceOnUse">
      <g fill="none" stroke="#cbd4df" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">

        {{-- medical cross (rounded plus) --}}
        <path d="M26 34 a4 4 0 0 1 4-4 h6 a4 4 0 0 1 4 4 v6 h6 a4 4 0 0 1 4 4 v6 a4 4 0 0 1-4 4 h-6 v6 a4 4 0 0 1-4 4 h-6 a4 4 0 0 1-4-4 v-6 h-6 a4 4 0 0 1-4-4 v-6 a4 4 0 0 1 4-4 h6 z"/>

        {{-- heart --}}
        <g transform="translate(138,22) scale(1.15)">
          <path d="M12 20 C12 20 3.5 13.5 3.5 8 C3.5 5 5.5 3 8 3 C10 3 11.3 4.2 12 5.3 C12.7 4.2 14 3 16 3 C18.5 3 20.5 5 20.5 8 C20.5 13.5 12 20 12 20 Z"/>
        </g>

        {{-- capsule / pill --}}
        <g transform="translate(232,30) rotate(42)">
          <rect x="0" y="0" width="42" height="17" rx="8.5"/>
          <path d="M21 0 v17"/>
        </g>

        {{-- stethoscope --}}
        <g transform="translate(30,120)">
          <path d="M2 0 v11 a11 11 0 0 0 22 0 v-11"/>
          <circle cx="2" cy="-1.5" r="1.8"/><circle cx="24" cy="-1.5" r="1.8"/>
          <path d="M13 22 v10 a13 13 0 0 0 26 2"/>
          <circle cx="43" cy="35" r="6"/>
        </g>

        {{-- lab flask --}}
        <g transform="translate(146,132)">
          <path d="M7 0 v13 l-11 22 a3 3 0 0 0 3 5 h20 a3 3 0 0 0 3-5 l-11-22 v-13"/>
          <path d="M2 0 h16 M-2 30 h28"/>
        </g>

        {{-- ECG / pulse line --}}
        <path d="M214 150 h13 l5-17 l8 31 l6-14 h18"/>

        {{-- syringe --}}
        <g transform="translate(44,214) rotate(-28)">
          <path d="M4 2 h26 v9 h-26 z"/>
          <path d="M0 6.5 h4 M-3 3.5 v6"/>
          <path d="M11 2 v9 M17 2 v9 M23 2 v9"/>
          <path d="M30 6.5 h9 M39 4 l4 2.5 -4 2.5"/>
        </g>

        {{-- DNA helix --}}
        <g transform="translate(150,214)">
          <path d="M0 0 C15 8 15 20 0 28 C-15 36 -15 48 0 56"/>
          <path d="M22 0 C7 8 7 20 22 28 C37 36 37 48 22 56"/>
          <path d="M3 6 h16 M1 14 h20 M1 42 h20 M3 50 h16"/>
        </g>

        {{-- thermometer --}}
        <g transform="translate(244,214)">
          <path d="M4 2 a4 4 0 0 1 8 0 v24 a7 7 0 1 1 -8 0 z"/>
          <circle cx="8" cy="33" r="3.5" fill="#cbd4df" stroke="none"/>
          <path d="M4 9 h3 M4 15 h3 M4 21 h3"/>
        </g>

      </g>
    </pattern>
  </defs>
  <rect width="100%" height="100%" fill="url(#tdDoodles)"/>
</svg>
