<?php

namespace App\Support\Access;

/**
 * What a clinic can switch on and off for a role, in the words staff use
 * (Settings, Staff and access; docs/rbac.md).
 *
 * Each ability stands for one or more permission strings, the same ones the
 * Policies, the menu and the API check. The lists never overlap, so ticking
 * one ability can never quietly change another (AccessControlTest guards it).
 *
 * `also` is given with the ability and never taken away with it: a nurse who
 * is allowed to manage stock also needs to open the stock pages, but taking
 * stock management away from a pharmacist must not stop them dispensing.
 *
 * `needs` is another ability that must be on for this one to make sense:
 * somebody who writes a diagnosis has to be able to read the notes.
 */
final class Abilities
{
    /** @var array<string, array{label:string, hint:string, permissions:list<string>, also?:list<string>, needs?:list<string>}> */
    public const ALL = [
        'see_clinical' => [
            'label' => 'See clinical notes',
            'hint' => 'Complaints, diagnoses, notes, prescriptions, the dental chart, lab and X-ray results, the visit report.',
            'permissions' => ['visits.clinical'],
        ],
        'record_vitals' => [
            'label' => 'Record vitals',
            'hint' => 'Temperature, blood pressure, weight and the other observations.',
            'permissions' => ['visits.vitals'],
            'needs' => ['see_clinical'],
        ],
        'diagnose_prescribe' => [
            'label' => 'Diagnose and prescribe',
            'hint' => 'Write the clinical notes and the diagnosis, chart teeth, write prescriptions.',
            'permissions' => ['visits.diagnose', 'prescriptions.prescribe'],
            'needs' => ['see_clinical'],
        ],
        'take_payments' => [
            'label' => 'Take payments',
            'hint' => 'Add charges to a bill, raise the invoice and receive money.',
            'permissions' => ['billing.manage'],
            'also' => ['billing.view'],
        ],
        'give_discounts' => [
            'label' => 'Give discounts',
            'hint' => 'Take an amount or a percentage off a bill, with a reason.',
            'permissions' => ['billing.discount'],
        ],
        'approve_refunds' => [
            'label' => 'Approve refunds',
            'hint' => 'Credit notes and money given back.',
            'permissions' => ['billing.refund'],
        ],
        'delete_visits' => [
            'label' => 'Delete visits',
            'hint' => 'Remove a visit opened by mistake, with its orders and bill, while nothing has been paid on it.',
            'permissions' => ['visits.delete'],
        ],
        'change_prices' => [
            'label' => 'Change prices',
            'hint' => 'The price list: services, procedures and their prices.',
            'permissions' => ['services.manage'],
        ],
        'see_money' => [
            'label' => 'See reports and money',
            'hint' => 'All invoices, the cashier\'s day, takings, debtors, reports and the books.',
            'permissions' => ['billing.view', 'reports.view', 'finance.view'],
        ],
        'manage_stock' => [
            'label' => 'Manage stock',
            'hint' => 'Stock items, receiving, adjusting and counting.',
            'permissions' => ['pharmacy.manage'],
            'also' => ['pharmacy.view'],
        ],
        'manage_staff' => [
            'label' => 'Manage staff and settings',
            'hint' => 'User accounts, staff profiles and the clinic\'s settings.',
            'permissions' => ['manage-users', 'manage-settings', 'staff.manage'],
        ],
    ];

    /**
     * The roles a clinic may change. The hospital admin keeps everything and
     * is not in this list on purpose: that is what makes it impossible to
     * lock the clinic out of its own settings.
     */
    public const ROLES = [
        'doctor', 'nurse', 'receptionist', 'pharmacist', 'lab_technician',
        'radiologist', 'accountant', 'records_officer',
    ];

    /** The role nobody can edit. */
    public const LOCKED_ROLE = 'hospital_admin';

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    public static function label(string $key): string
    {
        return self::ALL[$key]['label'] ?? $key;
    }

    /** @return list<string> */
    public static function permissions(string $key): array
    {
        return self::ALL[$key]['permissions'] ?? [];
    }

    /** The permissions ticking this ability hands out. @return list<string> */
    public static function granted(string $key): array
    {
        return array_values(array_unique([...self::permissions($key), ...(self::ALL[$key]['also'] ?? [])]));
    }

    /** @return list<string> */
    public static function needs(string $key): array
    {
        return self::ALL[$key]['needs'] ?? [];
    }

    /** Abilities that need $key, so switching $key off switches them off too. @return list<string> */
    public static function neededBy(string $key): array
    {
        return array_keys(array_filter(self::ALL, fn (array $a) => in_array($key, $a['needs'] ?? [], true)));
    }

    public static function roleIsEditable(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }
}
