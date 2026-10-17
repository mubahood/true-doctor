<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\CashierDaySummary;
use App\Support\HospitalSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** The cashier's day as a printout: the PDF the app prints, from the web. */
class CashierDayController extends Controller
{
    public function pdf(Request $request, CashierDaySummary $summary)
    {
        $this->authorize('viewAny', Invoice::class);

        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $hospital = app(HospitalSettings::class)->hospital();
        $figures = $summary->forDate($hospital, $data['date'] ?? $summary->today($hospital));

        return Pdf::loadView('pdf.cashier-day', ['summary' => $figures, 'hospital' => $hospital])
            ->stream("cashier-{$figures['date']}.pdf");
    }
}
