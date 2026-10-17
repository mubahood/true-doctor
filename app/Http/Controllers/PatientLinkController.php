<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\PatientLink;
use App\Models\PatientLinkOpen;
use App\Services\PatientLinks\PatientLinkService;
use App\Services\PatientLinks\SharedDocuments;
use App\Support\CurrentHospital;
use App\Support\HospitalBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page a patient lands on from a text message: `/p/{token}`.
 *
 * No login. The page asks for the code from the message or the patient's date
 * of birth; once answered, that browser may open the document for half an
 * hour. A link that has stopped working (expired, withdrawn, locked) says so
 * and gives the clinic's number. Every visit is logged.
 *
 * The tenant is taken from the link, so the page wears that hospital's brand
 * and the document is looked up inside that hospital only.
 */
class PatientLinkController extends Controller
{
    public function __construct(
        private readonly PatientLinkService $links,
        private readonly SharedDocuments $documents,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $link = $this->links->resolve($token);
        if ($link === null) {
            return $this->page(null, 'missing', $token, 404);
        }

        if (! $link->isActive()) {
            $this->links->record($link, PatientLinkOpen::REFUSED, $request->ip(), $request->userAgent());

            return $this->page($link, $link->state(), $token, 410);
        }

        $unlocked = $this->unlocked($request, $link);
        if (! $unlocked) {
            $this->links->record($link, PatientLinkOpen::VIEWED, $request->ip(), $request->userAgent());
        }

        return $this->page($link, $unlocked ? 'open' : 'ask', $token);
    }

    public function unlock(Request $request, string $token): RedirectResponse|Response
    {
        $link = $this->links->resolve($token);
        if ($link === null) {
            return $this->page(null, 'missing', $token, 404);
        }

        $answer = mb_substr(trim((string) $request->input('answer', '')), 0, 40);
        if ($answer === '') {
            return redirect()->route('patient-link.show', ['token' => $token])
                ->withErrors(['answer' => 'Type the code from your message, or your date of birth.']);
        }

        $outcome = $this->links->unlock($link, $answer, $request->ip(), $request->userAgent());

        if ($outcome === PatientLinkOpen::UNLOCKED) {
            $request->session()->put($this->sessionKey($link), now()->addMinutes(PatientLinkService::UNLOCK_MINUTES)->getTimestamp());

            return redirect()->route('patient-link.show', ['token' => $token]);
        }

        if ($outcome === PatientLinkOpen::WRONG_CODE) {
            $left = $this->links->triesLeft($link->fresh() ?? $link);

            return redirect()->route('patient-link.show', ['token' => $token])
                ->withErrors(['answer' => "That does not match. {$left} ".($left === 1 ? 'try' : 'tries').' left.']);
        }

        // Locked by this answer, or no longer working.
        return redirect()->route('patient-link.show', ['token' => $token]);
    }

    public function document(Request $request, string $token): Response|RedirectResponse
    {
        $link = $this->links->resolve($token);
        abort_if($link === null, 404);

        if (! $link->isActive() || ! $this->unlocked($request, $link)) {
            return redirect()->route('patient-link.show', ['token' => $token]);
        }

        app(CurrentHospital::class)->set($link->hospital_id);
        $record = $this->documents->findById($link->document_type, $link->document_id);
        abort_if($record === null, 404);

        $this->links->record($link, PatientLinkOpen::DOWNLOADED, $request->ip(), $request->userAgent());

        return response($this->documents->pdf($link->document_type, $record)->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->documents->filename($link->document_type, $record).'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function unlocked(Request $request, PatientLink $link): bool
    {
        $until = (int) $request->session()->get($this->sessionKey($link), 0);

        return $until > now()->getTimestamp();
    }

    private function sessionKey(PatientLink $link): string
    {
        return 'patient_links.'.$link->uuid;
    }

    /**
     * The one page, in each of its states: missing, ask (for the code), open,
     * or why it stopped working (expired, revoked, locked). The patient's
     * first name appears only once the link is unlocked.
     */
    private function page(?PatientLink $link, string $state, string $token, int $status = 200): Response
    {
        $hospital = $link !== null ? Hospital::query()->find($link->hospital_id) : null;
        $brand = new HospitalBrand($hospital);
        $patient = $state === 'open' ? $link?->patient()->withoutGlobalScopes()->first() : null;

        return response()->view('patient-links.show', [
            'link' => $link,
            'state' => $state,
            'token' => $token,
            'brand' => $brand,
            'firstName' => $patient?->first_name,
            'triesLeft' => $link !== null ? $this->links->triesLeft($link) : 0,
        ], $status)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
