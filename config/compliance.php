<?php

/*
|--------------------------------------------------------------------------
| Data protection (docs/compliance/README.md)
|--------------------------------------------------------------------------
| Uganda's Data Protection and Privacy Act 2019 and its 2021 Regulations.
| The documents in docs/compliance/ read their numbers from here, so the PDF
| a clinic downloads and the system's own behaviour cannot disagree.
*/

return [

    // Who processes the data on the online service (the clinic is the controller).
    'processor' => [
        'name' => env('COMPLIANCE_PROCESSOR_NAME', 'SOLAVIA GROUP LIMITED'),
        'trading_as' => 'True-Doctor',
        // Empty: the platform support email (App\Support\SupportContact).
        'email' => env('COMPLIANCE_PROCESSOR_EMAIL'),
    ],

    // Personal Data Protection Office registration number, shown once issued.
    'pdpo_registration' => env('PDPO_REGISTRATION'),

    // The data processing agreement a clinic accepts on Settings, Data protection.
    // A new version asks every clinic to accept it again.
    'dpa_version' => '2026-10',

    // Days a clinic has to answer a patient's request (the Act asks for an
    // answer without delay; 30 days is the outer limit used here).
    'response_days' => 30,

    // How long a hospital's full export can be downloaded before it is deleted.
    'export_hours' => 48,

    /*
    | The retention schedule: how long each kind of record is kept, and why.
    | Read by the retention schedule PDF. Change a period here, with its
    | source, and the document follows.
    */
    'retention' => [
        ['record' => 'Patient clinical record (visits, notes, diagnoses, prescriptions, results, admissions)', 'keep' => '10 years after the last visit', 'source' => 'Accepted medical practice in Uganda; the Act (section 18) allows keeping records as long as the law or the purpose requires. Confirm with the Uganda Medical and Dental Practitioners Council.'],
        ['record' => 'Clinical record of a child', 'keep' => 'Until the patient turns 25, or 10 years after the last visit, whichever is later', 'source' => 'Accepted medical practice: a child may bring a claim after reaching adulthood.'],
        ['record' => 'Clinical record of a patient who has died', 'keep' => '10 years after the death', 'source' => 'Accepted medical practice.'],
        ['record' => 'Invoices, receipts, payments and the books', 'keep' => '5 years after the end of the financial year', 'source' => 'Tax Procedures Code Act 2014, section 32 (records kept for five years).'],
        ['record' => 'Insurance claims and statements', 'keep' => '5 years after the end of the financial year', 'source' => 'As for financial records; longer if the insurer\'s contract asks.'],
        ['record' => 'Patient consent to messages', 'keep' => 'As long as the patient record', 'source' => 'Proof of consent (the Act, section 7).'],
        ['record' => 'Log of text messages sent to patients', 'keep' => '2 years', 'source' => 'Needed only to answer questions about a message; the Act, section 18 (no longer than necessary).'],
        ['record' => 'Staff accounts', 'keep' => 'Until the member of staff leaves; the trail of what they did stays with the records it touched', 'source' => 'The audit trail is part of the clinical and financial record.'],
        ['record' => 'Audit trail (who changed what)', 'keep' => 'As long as the record it describes', 'source' => 'Accountability (the Act, section 3).'],
        ['record' => 'Requests from patients about their data', 'keep' => '3 years after the request is closed', 'source' => 'To show how each request was answered.'],
        ['record' => 'Full data export files', 'keep' => '48 hours, then deleted automatically', 'source' => 'A copy of everything should not sit on the server.'],
    ],
];
