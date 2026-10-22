<?php

namespace App\Enums;

/** What a spreadsheet brought in through Settings, Import data (docs/imports.md). */
enum ImportKind: string
{
    case Patients = 'patients';
    case Services = 'services';
    case Stock = 'stock';
    case Appointments = 'appointments';
    case Visits = 'visits';

    public function label(): string
    {
        return match ($this) {
            self::Patients => 'Patients',
            self::Services => 'Price list',
            self::Stock => 'Stock items',
            self::Appointments => 'Appointments',
            self::Visits => 'Visits',
        };
    }

    /** One line under the name on the import page. */
    public function hint(): string
    {
        return match ($this) {
            self::Patients => 'Names, sex, date of birth or age, phone, next of kin, allergies, conditions, the old file number and any balance owed.',
            self::Services => 'Services and procedures with their prices.',
            self::Stock => 'Items with their unit, quantity on the shelf, cost, selling price and expiry.',
            self::Appointments => 'Appointments already booked, with the patient, the doctor and the time.',
            self::Visits => 'Past visits: the date, the patient, the dentist, the complaint, diagnosis, treatment, and what was billed and paid.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Patients => 'fa-users',
            self::Services => 'fa-tags',
            self::Stock => 'fa-boxes-stacked',
            self::Appointments => 'fa-calendar-check',
            self::Visits => 'fa-stethoscope',
        };
    }

    /** "patient", "price", "stock item", "appointment", "visit": for counts in sentences. */
    public function noun(): string
    {
        return match ($this) {
            self::Patients => 'patient',
            self::Services => 'price',
            self::Stock => 'stock item',
            self::Appointments => 'appointment',
            self::Visits => 'visit',
        };
    }
}
