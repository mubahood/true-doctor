      </td>
    </tr>

    {{-- ── Who sent it, and why ── --}}
    <tr>
      <td style="background:#F5F7FA;border-top:1px solid #EEF2F7;padding:20px 36px 22px;text-align:center;">
        @php
          // An included view does not see what the header worked out.
          $mailBrand = \App\Support\HospitalBrand::forUser($user ?? null);
          $mailOrg = $mailBrand->name();
          $mailContact = $mailBrand->contact();
        @endphp
        @if($mailOrg)
          <p style="font-size:12px;color:#111C2E;font-weight:600;margin:0;">{{ $mailOrg }}</p>
          @if(array_filter([$mailContact['address'], $mailContact['phone']]))
            <p style="font-size:11px;color:#5A6B80;margin:4px 0 0;line-height:1.6;">
              {{ implode('  ·  ', array_filter([$mailContact['address'], $mailContact['phone']])) }}
            </p>
          @endif
        @endif
        <p style="font-size:10.5px;color:#93A1B3;margin:10px 0 0;line-height:1.6;">
          You are receiving this because you have a staff account{{ $mailOrg ? ' at '.$mailOrg : '' }}.<br>
          Sent automatically by {{ $mailOrg ? $mailOrg.'’s' : 'the' }} True-Doctor system.
        </p>
      </td>
    </tr>

  </table>

</td></tr>
</table>
</body>
</html>
