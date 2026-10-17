<?php

/*
|--------------------------------------------------------------------------
| Guides for clinic owners (roadmap 02 §7, 06 §4)
|--------------------------------------------------------------------------
| Short, practical guides on the public site at /guides. Each is written for
| a clinic owner, dated, signed by a real person, cites its sources and ends
| with one link to the part of the product that solves the problem.
|
| The back office's "Help" link on each screen points at one of these: the
| mapping from screen (route name) to guide is App\Support\HelpGuides::ROUTES.
|
| House rules for the words: plain sentences, no em dashes, figures marked as
| examples when they are examples, and anything a law or a council decides is
| pointed at that body rather than restated as if we were it.
*/

return [

    // Where the guides live for a server that does not carry the public site (a hospital's own copy).
    'site_url' => env('GUIDES_URL', env('APP_UPDATE_SERVER', 'https://true-doctor.online')),

    'author' => [
        'name' => 'Muhindo Mubaraka',
        'credentials' => 'MSc Computer Science, building health systems since 2021',
    ],

    'hub' => [
        'title' => 'Guides for clinic owners in Uganda | True-Doctor',
        'description' => 'Short, practical guides for clinic owners: registering a clinic, HMIS 105, working with no internet, pricing, insurance claims and stopping money leaks.',
        'h1' => 'Guides for running a clinic',
        'intro' => 'Short and practical, written for the person who owns or runs the clinic. Each one says where its facts come from, so you can check them.',
        'updated' => '2026-10-17',
    ],

    'guides' => [

        'register-a-private-clinic-in-uganda' => [
            'updated' => '2026-10-17',
            'minutes' => 6,
            'title' => 'How to register a private clinic in Uganda: steps and documents | True-Doctor',
            'description' => 'Who licenses a private clinic in Uganda, what to prepare before you apply, the documents councils ask for, and what to do once the licence arrives.',
            'h1' => 'How to register a private clinic in Uganda',
            'intro' => 'The council that licenses your clinic depends on who is in charge of it. Get that right first, prepare the premises and papers, then apply. This guide walks the steps in order.',
            'shot' => null,
            'sections' => [
                [
                    'heading' => 'First, which body licenses you',
                    'body' => [
                        'A private health unit is licensed by the professional council of the person in charge of it. A clinic run by a medical doctor or a dentist is licensed by the Uganda Medical and Dental Practitioners Council (UMDPC). Units run by nurses or midwives, and by clinical officers or laboratory professionals, are licensed by their own councils.',
                        'A pharmacy or drug shop is separate again: it is licensed by the National Drug Authority (NDA), even when it sits inside your clinic.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'Before you apply',
                    'body' => ['Councils inspect before they license, so have these in place first.'],
                    'points' => [
                        'The business registered with the Uganda Registration Services Bureau (URSB), as a business name or a company.',
                        'A Tax Identification Number (TIN) from the Uganda Revenue Authority (URA).',
                        'An in-charge who is registered with the council and holds a practising licence for this year.',
                        'Premises that fit the work: a private consulting room, a waiting area, hand washing, safe storage for medicines, a toilet, and a plan for clinical waste and sharps.',
                        'A trading licence from your city, municipal or town council.',
                    ],
                ],
                [
                    'heading' => 'The documents councils usually ask for',
                    'body' => ['Check the current list and fees with your council before you go: they change. Expect to be asked for:'],
                    'points' => [
                        'The completed application form for a private health unit.',
                        'The in-charge\'s registration certificate, current practising licence and academic certificates.',
                        'Your URSB registration and TIN.',
                        'The location of the premises, often with a sketch map, and the services you will offer.',
                        'A list of staff with each person\'s registration and licence.',
                        'A list of the main equipment.',
                        'In many districts, a letter of recommendation from the District Health Officer.',
                        'Proof of payment of the application and licence fees.',
                    ],
                ],
                [
                    'heading' => 'Inspection and the licence',
                    'body' => [
                        'The council, or inspectors acting for it, visit the premises and check them against the application. What they find wanting has to be put right before the licence is issued.',
                        'A licence is for a year. Put the renewal date in your calendar a month early: running on an expired licence is the kind of thing an inspection finds first.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'Once you are licensed',
                    'body' => ['A few things that are easy to forget in the first month.'],
                    'points' => [
                        'Display the licence where patients can see it.',
                        'Ask the district biostatistician to add the clinic to the Ministry of Health\'s facility list, so you can send the monthly HMIS reports (see the HMIS 105 guide).',
                        'Keep a record for every patient you see, with consent where the law asks for it.',
                        'Register with the Personal Data Protection Office: a clinic holds health data, which the law treats as special.',
                    ],
                ],
            ],
            'sources' => [
                ['Uganda Medical and Dental Practitioners Council', 'https://umdpc.ug'],
                ['Uganda Registration Services Bureau', 'https://ursb.go.ug'],
                ['Uganda Revenue Authority', 'https://ura.go.ug'],
                ['National Drug Authority', 'https://www.nda.or.ug'],
                ['Ministry of Health, Uganda', 'https://www.health.go.ug'],
                ['Personal Data Protection Office', 'https://pdpo.go.ug'],
            ],
            'product' => ['Set up a private clinic in True-Doctor', 'for', ['practice' => 'private-clinics']],
        ],

        'hmis-105-explained' => [
            'updated' => '2026-10-17',
            'minutes' => 6,
            'title' => 'HMIS 105 explained: the monthly outpatient report | True-Doctor',
            'description' => 'What the HMIS 105 monthly outpatient report covers, where its numbers come from, how to fill it without errors and when it is due.',
            'h1' => 'HMIS 105 explained',
            'intro' => 'The HMIS 105 is the monthly outpatient report every health facility in Uganda sends to its district, private clinics included. It is a count of the month, taken from your registers. Here is what it asks and how to fill it right.',
            'shot' => null,
            'sections' => [
                [
                    'heading' => 'What it is',
                    'body' => [
                        'The Ministry of Health collects a set of standard reports from every facility through the Health Management Information System (HMIS). The HMIS 105 is the outpatient one: one form per month, sent to the district and entered in the national DHIS2 system.',
                        'The district uses it to see disease trends and plan supplies. A clinic that does not report is invisible to that planning, and districts do follow up.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'What it counts',
                    'body' => ['The form has sections, and their exact layout changes when the HMIS manual is revised. Always use the current form from your district. Broadly, it counts:'],
                    'points' => [
                        'Outpatient attendances: new visits and re-attendances, by age group and sex.',
                        'New cases of each diagnosis on the standard list, by age group and sex.',
                        'Maternal and child health work: antenatal visits, family planning, immunisation.',
                        'Laboratory tests done.',
                        'Stock of tracer medicines and days out of stock.',
                    ],
                ],
                [
                    'heading' => 'Where the numbers come from',
                    'body' => [
                        'Every figure on the HMIS 105 should come from a register: the outpatient register for attendances and diagnoses, the antenatal register for ANC, and so on. The monthly form is the total of the month\'s register pages.',
                        'This is why the register has to be filled every day, as patients are seen. Counting at the end of the month from memory or from receipts gives a different number every time.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'Mistakes that send it back',
                    'body' => [],
                    'points' => [
                        'Counting a return visit for the same illness as a new case.',
                        'Writing a diagnosis that is not on the standard list, so it cannot be counted.',
                        'Putting a patient in the wrong age group.',
                        'Leaving a box blank when the answer is zero. Write 0.',
                        'Totals that do not add up across age groups and sex.',
                    ],
                ],
                [
                    'heading' => 'When and how to send it',
                    'body' => [
                        'Reports are due early in the following month; your district sets the exact date. The in-charge checks and signs the form, and it goes to the district biostatistician, who enters it in DHIS2 or gives the facility its own access to enter it.',
                        'Keep a copy of every month\'s report with the register pages it came from. When the district asks a question, that copy is the answer.',
                    ],
                    'points' => [],
                ],
            ],
            'sources' => [
                ['Ministry of Health, Uganda: Health Management Information System', 'https://www.health.go.ug'],
                ['Uganda national DHIS2 (HMIS)', 'https://hmis.health.go.ug'],
            ],
            'product' => ['See how True-Doctor counts the month for you', 'features', []],
        ],

        'running-a-clinic-with-no-internet' => [
            'updated' => '2026-10-17',
            'minutes' => 5,
            'title' => 'Running a clinic with no internet: what to keep on paper | True-Doctor',
            'description' => 'How a clinic keeps working when the network goes: what belongs on paper, what does not, and a routine for before, during and after an outage.',
            'h1' => 'Running a clinic with no internet',
            'intro' => 'The network will go down on a busy morning. A clinic that has decided in advance what happens then keeps seeing patients and loses nothing. One that has not ends up with two records that disagree.',
            'shot' => 'phone',
            'sections' => [
                [
                    'heading' => 'Decide before it happens',
                    'body' => ['Write a one-page plan and pin it at reception. It answers three questions: who decides that the system is down, what everyone does instead, and who enters the paper afterwards.'],
                    'points' => [],
                ],
                [
                    'heading' => 'What to keep on paper',
                    'body' => ['A small set of papers, kept ready in a drawer:'],
                    'points' => [
                        'A downtime register: date, time, patient name and number, what was done, what was paid.',
                        'A numbered receipt book, so every payment taken by hand can be traced.',
                        'The signed forms the law asks for: consent for procedures, referral letters.',
                        'The Ministry\'s HMIS registers, if your district still expects paper registers.',
                    ],
                ],
                [
                    'heading' => 'What not to keep on paper',
                    'body' => [
                        'Do not keep the full patient record twice. Notes written on paper and typed later are typed late, or differently, or not at all. If your system works offline on a phone or tablet, write the notes there and let it send them when the signal is back.',
                        'Keep paper for what must be signed or what proves money changed hands.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'A routine for each outage',
                    'body' => [],
                    'points' => [
                        'Before: keep phones and tablets charged, signed in, and synced at least once a day. A UPS for the router and a power bank at reception cost little.',
                        'During: keep working on the devices that work offline. Use the downtime register only for what cannot wait.',
                        'After: enter the paper the same day, by one named person. Then check that nothing is waiting to be sent on any device.',
                    ],
                ],
                [
                    'heading' => 'Keep the paper safe',
                    'body' => ['Paper with patient names on it is personal data like any other. Lock it away, and shred it once it has been entered and checked.'],
                    'points' => [],
                ],
            ],
            'sources' => [
                ['Ministry of Health, Uganda: HMIS registers and tools', 'https://www.health.go.ug'],
                ['Personal Data Protection Office: the Data Protection and Privacy Act, 2019', 'https://pdpo.go.ug'],
            ],
            'product' => ['Get the True-Doctor app, which works with no network', 'get-app', []],
        ],

        'setting-clinic-prices-that-cover-costs' => [
            'updated' => '2026-10-17',
            'minutes' => 6,
            'title' => 'How to set clinic prices that cover your costs | True-Doctor',
            'description' => 'A simple way for a clinic to set prices: know what a visit costs you, add what each service uses, add a margin, check the market and review every quarter.',
            'h1' => 'How to set clinic prices that cover your costs',
            'intro' => 'Most small clinics set prices by asking what the clinic down the road charges. That tells you what patients will pay, not whether you will still be open in a year. Start from your own costs.',
            'shot' => 'visit',
            'sections' => [
                [
                    'heading' => 'Step 1: what a month costs you',
                    'body' => ['Add up everything you pay in a normal month whether you see one patient or a hundred: rent, salaries, power, water, internet, security, licences and fees spread over the year, loan repayments, and the system you use.'],
                    'points' => [],
                ],
                [
                    'heading' => 'Step 2: what each visit carries',
                    'body' => [
                        'Divide the month\'s costs by the visits you see in a month. That is what every visit has to bring in before it pays for anything it uses.',
                        'An example, with made-up figures: a clinic spending UGX 9,000,000 a month and seeing 600 visits carries UGX 15,000 a visit.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'Step 3: add what each service uses',
                    'body' => ['For each service, add the things it uses up: gloves, reagents, a test kit, dressing, a syringe. A test that uses UGX 2,500 of reagent and kit costs more than UGX 2,500, because the room, the person and the machine are paid for too.'],
                    'points' => [],
                ],
                [
                    'heading' => 'Step 4: add a margin, then look at the market',
                    'body' => [
                        'Add a margin for the slow months, repairs and growth. Then compare with clinics near you. If you are well above them, look for a cost you can cut. If you are well below them, you are probably losing money on that service.',
                        'Where the work varies, such as a simple or difficult extraction, set a range rather than one price, and charge where each patient falls inside it.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'Step 5: one list, reviewed every quarter',
                    'body' => ['Keep one price list that everybody uses, and do not let anyone type a price by hand. Review it every three months: suppliers raise prices, and a list that was right in January can be losing money by July.'],
                    'points' => [
                        'Ask your accountant or URA whether VAT applies to any of your services.',
                        'Insurers pay from their own agreed tariff, so price those services against the tariff too.',
                    ],
                ],
            ],
            'sources' => [
                ['Uganda Revenue Authority: VAT', 'https://ura.go.ug'],
                ['Uganda Bureau of Statistics: consumer price index, for how fast costs are rising', 'https://www.ubos.org'],
            ],
            'product' => ['See the price list with ranges in True-Doctor', 'features', []],
        ],

        'getting-paid-by-insurers-in-uganda' => [
            'updated' => '2026-10-17',
            'minutes' => 6,
            'title' => 'Getting paid by insurers in Uganda: claims that come back paid | True-Doctor',
            'description' => 'How a clinic gets insurance claims paid: check cover at the desk, get approval first, claim complete and on time, track every claim and reconcile what is paid.',
            'h1' => 'Getting paid by insurers in Uganda',
            'intro' => 'Most claims that are paid late, or not at all, were lost at the desk on the day of the visit, not at the insurer. The fix is a routine that starts before the patient is seen.',
            'shot' => 'cashier',
            'sections' => [
                [
                    'heading' => 'Know each contract',
                    'body' => ['For every insurer you work with, keep the signed contract, the agreed tariff, what needs approval first, and the deadline for sending claims. Put the deadline where the person who sends claims will see it.'],
                    'points' => [],
                ],
                [
                    'heading' => 'At the desk, before the patient is seen',
                    'body' => [],
                    'points' => [
                        'Check the member card and an ID, and that the cover is active today.',
                        'Check what the scheme covers and its limits for this kind of visit.',
                        'Get approval before admissions, procedures and anything over the limit. Write the approval number on the visit.',
                    ],
                ],
                [
                    'heading' => 'A complete claim',
                    'body' => ['Insurers query claims that are missing something. Each claim should carry:'],
                    'points' => [
                        'The member\'s details exactly as on the card.',
                        'The diagnosis, written clearly.',
                        'Every service, test and medicine, at the agreed tariff.',
                        'The approval number where one was needed.',
                        'The patient\'s signature, and the clinician\'s.',
                    ],
                ],
                [
                    'heading' => 'Send on time, then track every claim',
                    'body' => [
                        'Send claims in a weekly batch, well inside each insurer\'s deadline. Then track every claim through submitted, queried, approved and paid.',
                        'Answer queries within days. A claim with no decision after 45 days needs a phone call, not another week of waiting.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'Reconcile what is paid',
                    'body' => ['When money arrives, match the insurer\'s remittance advice against your claims, line by line. Write down every shortfall and its reason, and dispute the ones that are wrong. If an insurer does not resolve a complaint, the Insurance Regulatory Authority of Uganda handles complaints about insurers.'],
                    'points' => [],
                ],
            ],
            'sources' => [
                ['Insurance Regulatory Authority of Uganda', 'https://ira.go.ug'],
            ],
            'product' => ['See insurance claims and insurer floats in True-Doctor', 'features', []],
        ],

        'stopping-money-leaks-at-the-cashier' => [
            'updated' => '2026-10-17',
            'minutes' => 5,
            'title' => 'Stopping money leaks at the cashier: ten checks | True-Doctor',
            'description' => 'Ten checks that stop money leaking from a clinic: every service billed, one price list, discounts with reasons, receipts, mobile money references and a counted drawer.',
            'h1' => 'Stopping money leaks at the cashier: ten checks',
            'intro' => 'Money rarely leaves a clinic in one big theft. It leaves a few thousand at a time: a test not billed, a discount nobody approved, a mobile money payment nobody matched. These ten checks close the common gaps.',
            'shot' => 'closing',
            'sections' => [
                [
                    'heading' => 'Ten checks',
                    'body' => [],
                    'points' => [
                        'Every service, test and medicine goes on the bill as it is done, not from memory at the end.',
                        'No visit closes without an invoice. Look every week for visits that did.',
                        'One price list. Nobody types a price by hand.',
                        'Every discount has a reason and a name. The owner reviews the large ones each week.',
                        'Every payment gets a numbered receipt, including cash.',
                        'Every mobile money payment carries its transaction reference, and the references are matched against the statement.',
                        'The drawer is counted at the end of each shift against what the system says, by method.',
                        'Where there are enough people, the person who bills is not the person who takes the cash.',
                        'Voids and refunds are done by a manager, with a reason written down.',
                        'The owner reads the day\'s figures every morning: money in, what was left unpaid, discounts.',
                    ],
                ],
                [
                    'heading' => 'Why the small checks matter',
                    'body' => [
                        'Studies of workplace fraud find that small organisations lose a larger share of their income than big ones, and that most losses are found by a tip or by a routine check, not by chance. A routine that someone actually follows is the protection.',
                        'Make the checks routine and visible, not an accusation. Staff who know the drawer is counted every evening are rarely tempted, and the honest ones are protected when figures are questioned.',
                    ],
                    'points' => [],
                ],
                [
                    'heading' => 'Receipts and the tax man',
                    'body' => ['Find out from URA whether your clinic must issue electronic receipts through EFRIS. Either way, a numbered receipt for every payment is the first thing an auditor, and an owner, will ask for.'],
                    'points' => [],
                ],
            ],
            'sources' => [
                ['Association of Certified Fraud Examiners: Occupational Fraud, a Report to the Nations', 'https://www.acfe.com'],
                ['Uganda Revenue Authority: EFRIS', 'https://efris.ura.go.ug'],
            ],
            'product' => ['See the cashier\'s day and the debtors list in True-Doctor', 'features', []],
        ],
    ],
];
