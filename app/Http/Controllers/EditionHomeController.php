<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\StandaloneProduct;
use App\Support\Edition;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EditionHomeController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        if (Edition::isStandalone()) {
            return redirect()->route(auth()->check() ? 'admin.dashboard' : 'admin.login');
        }

        // The prices the page quotes come from the tables the checkout charges
        // from, never from copy: plans for the subscription, the cheapest
        // active product for the one-off copy.
        return view('marketing.home', [
            'plans' => Plan::query()->where('is_active', true)->where('price', '>', 0)->orderBy('price')->get(),
            'oneOff' => StandaloneProduct::query()->where('is_active', true)->orderBy('price_ugx')->first(),
            // Only once texts really reach phones from this server (a live gateway).
            'patientMessages' => (bool) config('landing.home.patient_messages', false) || app(\App\Services\Messaging\SmsTransport::class)->live(),
            'day' => self::day(),
            'faq' => self::faq(),
        ]);
    }

    /**
     * A day at the demo hospital, step by step: time, who, what, and the real
     * screenshot of it. Every sentence is something the product does today,
     * shown with the demo's own names so a visitor can open the same records.
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:string}>
     */
    private static function day(): array
    {
        return [
            ['07:30', 'Reception', 'The desk opens', 'Ruth finds returning patients by name, phone or number, or registers new ones in a minute. Each visit joins the queue with its stage and its bill in view.', 'reception'],
            ['08:15', 'The doctor', 'Seen, with the allergy on top', 'Dr. Nakato opens Brian\'s visit. His sulfa allergy is the first thing on the screen, the vitals are already there, and the malaria test is ordered from the same page.', 'visit'],
            ['08:40', 'The laboratory', 'The test reaches the bench', 'Lydia sees the order on the lab worklist and enters the result. It goes straight back to the visit. The charge went on the bill when the test was ordered.', 'lab'],
            ['10:00', 'The dental chair', 'Teeth charted, work planned', 'The dentist marks each tooth and surface, plans a root canal and two fillings from the price list, and prints a quote. Done work goes on the bill with one press.', 'dental'],
            ['14:00', 'The ward', 'Every bed at a glance', 'Grace sees who is in which bed and which beds are free. Each night in a bed is charged the next morning, at that night\'s rate, so the bill is right at discharge.', 'ward'],
            ['17:30', 'The cashier', 'The bill is already complete', 'Everything ordered through the day is on the invoice. Agnes takes cash, mobile money, a card or an insurer, each against the bill it settles. A visit cannot close while money is owed.', 'cashier'],
            ['19:00', 'Closing', 'The till balances', 'The cashier\'s day shows what came in, by method and by person. Agnes counts the drawer against the cash line and prints the day.', 'closing'],
        ];
    }

    /**
     * The questions clinic owners ask first, answered as things stand today.
     * When one changes (EFRIS, thermal printers), the answer changes with it.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function faq(): array
    {
        return [
            ['Do you issue EFRIS e-receipts?', 'Not yet. True-Doctor prints its own receipts and invoices. If URA requires your facility to issue EFRIS e-receipts, keep issuing them through EFRIS for now. A direct link to EFRIS is being built, and this answer will change the day it is ready.'],
            ['Can we bill insurance companies?', 'Yes. Record the insurer and the member\'s card, raise the claim against the invoice, and see what each insurer owes you and what it has paid. Prepaid patient and family cards work the same way.'],
            ['Which printers work?', 'Any printer your computer or phone can print to. Receipts, invoices, lab results, quotes and the cashier\'s day come out as clean PDFs with your clinic\'s name and logo.'],
            ['What if a phone with the app is stolen?', 'Switch that person\'s account off from any computer. The phone is refused at its next contact and its sign-in is deleted. Owners can also turn on two-step sign-in, and anyone can sign out of every device at once.'],
            ['Who owns our data?', 'You do. Your records are kept apart from every other clinic\'s'
                // Said only where the nightly off-site backup is switched on (routes/console.php).
                .(filled(config('backup.encryption_key')) ? ' and backed up every night, off the server' : '')
                .'. If you leave, we hand you a full copy. If you prefer, buy a copy that runs on your own server.'],
            ['We use paper registers. How do we move?', 'Start with the patients who come in from today, and enter an old card when that patient returns. If your patient list is in a spreadsheet, send it to us on WhatsApp and we will bring it in for you.'],
        ];
    }
}
