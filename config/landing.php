<?php

/*
|--------------------------------------------------------------------------
| The public pages that are content, not code
|--------------------------------------------------------------------------
|
| One entry per page: its title (at most 60 characters), description (at
| most 155), heading, sections, questions and when it was last changed (the
| sitemap's lastmod). One view per page type draws them (marketing/for,
| marketing/page). Every entry has its own words: never the same text with
| the practice's name swapped in. A point is written here only when the
| product does it today; what is on the way says so.
|
| `practices` are keyed by URL and name the Practice\Sector they sell.
*/

return [

    // When the fixed pages (home, features, pricing, security...) last changed: the sitemap's lastmod.
    'site_updated' => '2026-10-17',

    'practices' => [

        'dental-clinics' => [
            'sector' => 'dental_clinic',
            'name' => 'dental clinics',
            'updated' => '2026-10-17',
            'title' => 'Dental clinic software in Uganda | True-Doctor',
            'description' => 'Patient records, dental price lists with ranges, treatment billing, recalls and mobile money for dental clinics. Works offline. Free 14-day trial.',
            'h1' => 'Software for dental clinics that bills every procedure.',
            'intro' => 'From the front desk to the chair to the cashier: one record per patient, a price list that matches how dentists charge, and a bill that is right before the patient stands up.',
            'sections' => [
                [
                    'heading' => 'Price ranges, the way dentists charge',
                    'body' => 'A difficult extraction is not one price. Put a range on any procedure, for example 50,000 to 80,000, and charge where this patient falls inside it. Anything outside the range is refused before it is written.',
                    'points' => ['Fillings, extractions, root canals, crowns, dentures and braces come ready in the dental price list', 'Dental X-rays are procedures at the chair, not a radiology worklist', 'Change any price; the old bills keep the price they were charged'],
                ],
                [
                    'heading' => 'The dentist who also runs the clinic',
                    'body' => 'In a small clinic the senior dentist is often the administrator too. They keep one account, see patients and manage the clinic, without a second login.',
                    'points' => ['Each dentist has their own diary and queue', 'Nurses and assistants see only what their role allows', 'The owner sees the money: today, this month, and who still owes'],
                ],
                [
                    'heading' => 'Paid before they leave',
                    'body' => 'Every procedure goes on the visit as it is done. The cashier takes cash, MTN Mobile Money, Airtel Money, a card or a prepaid family card, and the receipt prints or goes to the patient by link.',
                    'points' => ['A visit cannot close with money unaccounted for', 'Debtors list with how long each bill has waited', 'The day closes with what each person took, by method'],
                ],
                [
                    'heading' => 'Keeps working when the network does not',
                    'body' => 'Register patients, write notes and record vitals on a phone or tablet with no signal. Everything is kept on the device and sent the moment the network is back. Nothing typed is lost.',
                    'points' => [],
                ],
            ],
            'coming' => ['A tooth chart (FDI notation) with findings per tooth and surface', 'Treatment plans from your price list, with a quote to print', 'Six-month recall messages by SMS and WhatsApp'],
            'faq' => [
                ['Can I use my own price list?', 'Yes. The dental price list is a starting point. Change prices, add your own procedures and set ranges where you charge by difficulty.'],
                ['We have one chair and two people. Is it too much?', 'No. Turn off what you do not use: wards, insurance, the laboratory. The menu shows only what your clinic does.'],
                ['Can we have our own copy on our own server?', 'Yes. A one-off copy runs on your hosting with your name on it, and clinics already run it that way.'],
            ],
            'shots' => ['visit', 'cashier'],
        ],

        'private-clinics' => [
            'sector' => 'medical_clinic',
            'name' => 'private clinics',
            'updated' => '2026-10-17',
            'title' => 'Clinic management software for Uganda | True-Doctor',
            'description' => 'Run a private clinic or health centre: patient records, visits, a small lab and dispensary, mobile money and debtors. Works with no internet.',
            'h1' => 'Clinic software for the busy private clinic.',
            'intro' => 'Forty patients a day, one receptionist, two clinical officers, a small lab and a dispensary. True-Doctor keeps them on one record and makes sure every visit is paid for.',
            'sections' => [
                [
                    'heading' => 'From the front desk to the cashier in one record',
                    'body' => 'Register a patient in seconds, check them in, send them to triage. The clinician sees the vitals, writes the notes, orders a test and prescribes. The lab and the dispensary see their work arrive. The cashier sees what is owed.',
                    'points' => ['Returning patients found by name, phone or number', 'Possible duplicates flagged before a second record is made', 'Allergies shown before anybody prescribes or dispenses'],
                ],
                [
                    'heading' => 'Stop the money leaking',
                    'body' => 'Every test, drug and procedure becomes a charge as it is ordered. A visit cannot be closed while money is unaccounted for, and the debtors list says who owes what, and since when.',
                    'points' => ['Cash, MTN Mobile Money, Airtel Money and cards', 'Prepaid family cards and insurers, with their own balances', 'The cashier\'s day closes with what each person took'],
                ],
                [
                    'heading' => 'A dispensary that knows its shelf',
                    'body' => 'Stock in, stock out, batches and expiry dates. Items running low and medicines about to expire show on the dashboard before they become a problem.',
                    'points' => [],
                ],
                [
                    'heading' => 'No network, no problem',
                    'body' => 'The Android app keeps working with no signal: register, take vitals, write notes, enter results. It sends everything when the network returns.',
                    'points' => [],
                ],
            ],
            'coming' => ['HMIS 105 monthly report from your own records', 'ICD-10 coded diagnoses with a quick picker', 'Appointment reminders and receipts by SMS and WhatsApp'],
            'faq' => [
                ['Do my staff need training?', 'A receptionist can register and check in patients after twenty minutes. We set you up on WhatsApp or a call, free during the trial.'],
                ['What if the internet goes off?', 'The app keeps working and sends everything later. The web back office needs a connection.'],
                ['Is my patients\' information safe?', 'Each clinic sees only its own data, each person only what their role allows, and owners use two-step sign-in. Read the security page for the details.'],
            ],
            'shots' => ['dashboard', 'visit'],
        ],

        'maternity-centres' => [
            'sector' => 'maternity_centre',
            'name' => 'maternity centres',
            'updated' => '2026-10-17',
            'title' => 'Maternity and birth centre software | True-Doctor',
            'description' => 'Antenatal visits, deliveries, admissions and newborn care for maternity centres in Uganda, with mobile money billing and a ward board.',
            'h1' => 'Software for maternity and birth centres.',
            'intro' => 'Mothers come back again and again before they deliver. True-Doctor keeps every visit, every test and every bill on one record, and shows the ward at a glance on the night of a delivery.',
            'sections' => [
                [
                    'heading' => 'Every antenatal visit on one record',
                    'body' => 'Each visit records the mother\'s vitals, notes, tests and what was given. The next clinician sees all of it, from the first contact to the last.',
                    'points' => ['Antenatal care, deliveries, family planning and gynaecology in the maternity price list', 'Laboratory orders and results on the same record', 'Allergies and long-term conditions shown first'],
                ],
                [
                    'heading' => 'The ward on the night of a delivery',
                    'body' => 'Admit to a bed, see who is in which bed, record observations and doses on the round, and hand over to the next shift with notes nobody has to rewrite.',
                    'points' => ['A bed is charged night by night, so the bill is right at discharge', 'Discharge summary to print', 'Shift handover per ward'],
                ],
                [
                    'heading' => 'Paid without a queue at the window',
                    'body' => 'Mobile money, cash and insurers. The bill grows with the stay and closes with the discharge.',
                    'points' => [],
                ],
            ],
            'coming' => ['A pregnancy record with the expected date of delivery and the antenatal contacts', 'An immunisation register and growth charts for the baby', 'Reminders for the next antenatal visit by SMS and WhatsApp'],
            'faq' => [
                ['Can midwives use it on a phone?', 'Yes. The Android app records vitals and notes, and keeps working when the network drops.'],
                ['Can we print documents for the mother?', 'Receipts, invoices, lab results and discharge summaries print on A4 with your clinic\'s name and logo.'],
            ],
            'shots' => ['ward', 'visit'],
        ],

        'hospitals' => [
            'sector' => 'general_hospital',
            'name' => 'hospitals',
            'updated' => '2026-10-17',
            'title' => 'Hospital management system for Uganda | True-Doctor',
            'description' => 'Outpatients, wards, theatre, laboratory, imaging, pharmacy, insurance and double-entry books in one hospital system. Works offline on Android.',
            'h1' => 'A hospital management system that keeps every department on one record.',
            'intro' => 'Outpatients, wards, the laboratory, imaging, the pharmacy, insurance and the books. Each department sees its own work; the hospital sees all of it.',
            'sections' => [
                [
                    'heading' => 'Departments that hand work to each other',
                    'body' => 'An order from the consulting room appears on the lab bench, the imaging worklist or the dispensary queue. Results come back to the same record. Nobody carries paper between buildings.',
                    'points' => ['Laboratory with reference ranges and result release', 'Imaging worklist and reports', 'Pharmacy dispense queue and stock with batches and expiry'],
                ],
                [
                    'heading' => 'Wards that bill themselves',
                    'body' => 'The bed board shows every ward. A stay is charged night by night, so the bill is right the moment a patient is discharged, and the ward round records observations and doses where they are given.',
                    'points' => ['Admit, transfer and discharge', 'Ward round and doses due', 'Shift handover per ward'],
                ],
                [
                    'heading' => 'Insurance and the books',
                    'body' => 'Insurers and schemes keep their own balance, claims follow their own status, and every payment lands in double-entry books with financial years you close.',
                    'points' => ['Insurer floats and claims', 'Prepaid patient cards', 'Statements and reports by department and by month'],
                ],
                [
                    'heading' => 'Nine roles, each with its own screen',
                    'body' => 'Reception, doctors, nurses, the laboratory, imaging, the pharmacy, accounts, records and administrators. Each sees what their work needs and nothing else.',
                    'points' => [],
                ],
            ],
            'coming' => ['HMIS 105 and 108 reports from your own records', 'ICD-10 coded diagnoses', 'EFRIS e-receipts'],
            'faq' => [
                ['How many users can we have?', 'Plans are limited by staff and beds, never by patients. A hospital that grows changes plan; nobody is ever stopped from seeing a patient.'],
                ['Can we run it on our own server?', 'Yes. The one-off copy runs on your hosting, with your name, your data and your backups.'],
                ['Who supports us?', 'We do, on WhatsApp, by phone and by email. A partner who installed your copy can support you instead.'],
            ],
            'shots' => ['ward', 'dashboard'],
        ],
    ],

    'pages' => [

        'walkthrough' => [
            'updated' => '2026-10-17',
            'title' => 'Book a free walkthrough | True-Doctor',
            'description' => 'A free 20-minute walkthrough of True-Doctor for your clinic or hospital, on WhatsApp video or a phone call, at a time that suits you.',
            'h1' => 'See it with your own work in mind.',
            'intro' => 'Twenty minutes, free, on WhatsApp video or a call. Tell us what your clinic does and we will show you that, not a tour of everything.',
            'steps' => [
                ['Tell us about your clinic', 'How many staff, which departments, how you charge, what goes wrong today.'],
                ['We show you your day', 'Registration, the consulting room, the lab, the cashier: the parts you will use, with your kind of patient.'],
                ['You decide', 'Start the free trial the same day, or take your time. No card is needed.'],
            ],
        ],

        'get-app' => [
            'updated' => '2026-10-17',
            'title' => 'The True-Doctor app for Android | True-Doctor',
            'description' => 'The True-Doctor Android app for clinic staff: patients, visits, vitals, lab, pharmacy, wards and payments. Works with no network.',
            'h1' => 'Your clinic on an Android phone.',
            'intro' => 'The same system your team uses on the web, made for a phone and a tablet. It keeps working with no network and sends everything when the signal returns.',
            'points' => [
                ['Works offline', 'Register patients, take vitals, write notes and enter results with no signal.'],
                ['Private on the phone', 'Each person sees only what their role allows. A PIN locks the app, and nothing is saved in the phone\'s backups.'],
                ['Reminders, not noise', 'Appointments and doses due, without patient names on the lock screen.'],
                ['Try it first', 'Open the demo hospital from the app as a doctor, a nurse or a cashier. No sign-up.'],
            ],
        ],

        'about' => [
            'updated' => '2026-10-17',
            'title' => 'About True-Doctor and SOLAVIA GROUP LIMITED',
            'description' => 'True-Doctor is built in Uganda by SOLAVIA GROUP LIMITED for clinics and hospitals in East Africa. Who we are and how to reach us.',
            'h1' => 'Built in Uganda for the clinics we know.',
            'intro' => 'True-Doctor is made in Uganda by SOLAVIA GROUP LIMITED. We build for the clinic that has a busy morning, an unreliable network and patients who pay by mobile money.',
            'sections' => [
                ['What we believe', 'A clinic system must keep working when the network does not, must never lose what somebody typed, and must make sure every service is paid for. The rest is detail.'],
                ['Who builds it', 'Muhindo Mubaraka leads the work. He holds an MSc in Computer Science, has built health and business systems since 2021, and teaches software on YouTube (Learn It With Muhindo).'],
                ['How we work with clinics', 'We set every clinic up ourselves, on WhatsApp or a call, and we answer the same way when something is not right.'],
            ],
        ],

        'demo' => [
            'updated' => '2026-10-17',
            'title' => 'Try the True-Doctor demo hospital | True-Doctor',
            'description' => 'Open a working demo hospital as a doctor, nurse, receptionist, cashier, pharmacist or lab technician. No sign-up, sample data only.',
            'h1' => 'Walk into a working hospital.',
            'intro' => 'Pick a role and you are in: a sample hospital with patients, visits, a lab, a pharmacy and a ward. Nothing here is real, so change anything you like.',
        ],

        'own-your-copy' => [
            'updated' => '2026-10-17',
            'title' => 'Your own copy of True-Doctor, on your server',
            'description' => 'Buy True-Doctor once and run it on your own hosting, with your name, your data and your backups. Updates included for 12 months.',
        ],
    ],
];
