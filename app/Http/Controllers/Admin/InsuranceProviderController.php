<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InsuranceProvider;
use App\Services\InsuranceLedgerService;
use App\Support\HospitalSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Insurers. The directory and the float both live in Livewire
 * (App\Livewire\InsuranceProviders\{Index,Show}); what is left here is the one
 * thing that has to leave the SPA — the usage statement the hospital hands the
 * insurer, as a PDF.
 */
class InsuranceProviderController extends Controller
{
    public function usagePdf(Request $request, InsuranceProvider $insuranceProvider, InsuranceLedgerService $ledger)
    {
        $this->authorize('viewAny', InsuranceProvider::class);

        // Same window the screen is showing, so the PDF is never a different
        // set of figures from the page it was printed off.
        $from = $this->date($request->query('from'), Carbon::now()->startOfMonth());
        $to = $this->date($request->query('to'), Carbon::now());

        $report = $ledger->usage($insuranceProvider, $from, $to);

        return Pdf::loadView('pdf.insurance-usage', [
            'report' => $report,
            'hospital' => app(HospitalSettings::class)->hospital(),
        ])->stream(
            'usage-'.str($insuranceProvider->name)->slug().'-'.$from->toDateString().'-to-'.$to->toDateString().'.pdf'
        );
    }

    private function date(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
