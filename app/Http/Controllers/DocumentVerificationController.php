<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Services\DocumentVerifier;
use App\Support\HospitalBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/v/{code}`: is this paper genuine? (roadmap 05 §4)
 *
 * The answer names the kind of document, its number, the hospital, the day it
 * was printed and the patient's initials, and nothing that is in it. `/v`
 * alone is a box to type the code printed beside the QR.
 */
class DocumentVerificationController extends Controller
{
    public function form(Request $request): Response|RedirectResponse
    {
        $typed = trim((string) $request->query('code', ''));
        if ($typed !== '') {
            $code = DocumentVerifier::normalise($typed);

            return $code !== null
                ? redirect()->route('verify.show', ['code' => $code])
                : redirect()->route('verify.form')->withErrors(['code' => 'A code is 8 letters and numbers, like K7QM-4XPA.'])->withInput();
        }

        return $this->page(null, null, false);
    }

    public function show(string $code, DocumentVerifier $verifier): Response
    {
        $row = $verifier->check($code);
        $hospital = $row !== null ? Hospital::query()->find($row->hospital_id) : null;

        return $this->page($row, $hospital, true, $row === null ? 404 : 200);
    }

    private function page(?\App\Models\DocumentVerification $row, ?Hospital $hospital, bool $checked, int $status = 200): Response
    {
        return response()->view('verify.show', [
            'row' => $row,
            'brand' => new HospitalBrand($hospital),
            'checked' => $checked,
        ], $status)->withHeaders(['X-Robots-Tag' => 'noindex, nofollow']);
    }
}
