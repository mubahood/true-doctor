<?php

/*
|--------------------------------------------------------------------------
| Who sells True-Doctor
|--------------------------------------------------------------------------
|
| The company facts printed in the site's footer, on /contact, in the
| Organization structured data and on SOLAVIA's own invoices and receipts.
| Each is printed only when it is set: a placeholder registration number is
| worse than none.
*/

return [
    'legal_name' => env('COMPANY_LEGAL_NAME', 'SOLAVIA GROUP LIMITED'),
    'registration_no' => env('COMPANY_REGISTRATION_NO'),
    'tin' => env('COMPANY_TIN'),
    'address' => env('COMPANY_ADDRESS'),
    'po_box' => env('COMPANY_PO_BOX'),
    'country' => env('COMPANY_COUNTRY', 'Uganda'),

    // Where the online service's servers are, said on /security (roadmap 07 §4).
    'hosting_location' => env('COMPANY_HOSTING_LOCATION'),

    // The Personal Data Protection Office registration, once issued (roadmap 07 §4).
    'pdpo_registration' => env('COMPANY_PDPO_REGISTRATION'),
];
