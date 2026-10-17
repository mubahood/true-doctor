<?php

/*
 * Text messages to patients (App\Services\Messaging\PatientMessenger), each
 * the clinic's own: :clinic and :clinic_phone ("the clinic" when it has no
 * number set). Short, plain and kind; one message where it can be.
 */
return [
    'appointment_booked' => ':clinic: your appointment with :doctor is on :date at :time. To change it, call :clinic_phone.',
    'appointment_day_before' => ':clinic: a reminder of your visit tomorrow at :time. To change it, call :clinic_phone.',
    'receipt' => ':clinic: thank you. We received :amount for invoice :invoice. Your receipt: :link',
    // Patient links (roadmap 05 §4): the code unlocks the link; so does the date of birth.
    'document_link' => ':clinic: your :document is ready. View it here: :link (code: :code)',
    'dental_recall' => ':clinic: it is six months since your dental check-up. Call :clinic_phone to book your next visit.',

    // Added when the gateway number takes replies (messaging.sms.reply_stop).
    'stop' => 'Reply STOP to stop these messages.',
];
