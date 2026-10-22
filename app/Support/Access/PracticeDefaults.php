<?php

namespace App\Support\Access;

use App\Models\Hospital;
use App\Support\Practice\Practice;

/**
 * The access a kind of practice starts with, on top of the default matrix
 * (docs/rbac.md, "What a practice starts with").
 *
 * A small clinic shares the work differently from a hospital: in a dental
 * clinic the desk raises the bill, prints the visit report, enters stock and
 * keeps the books, and the dental assistant (the nurse role) enters stock.
 * These are abilities, so they read on the Staff and access screen as the
 * clinic's starting point and the clinic changes them there like any other
 * (a choice it makes is a RoleAbility row, which wins).
 *
 * Nothing is written: a hospital's sector decides, so an existing dental
 * clinic has these at once and a general hospital never does.
 */
final class PracticeDefaults
{
    /** @var array<string, array<string, list<string>>> sector => role => abilities */
    public const SECTORS = [
        'dental_clinic' => [
            'receptionist' => ['take_payments', 'print_report', 'manage_stock', 'see_money', 'record_money'],
            'doctor' => ['see_clinical', 'diagnose_prescribe'],
            'nurse' => ['manage_stock'],
        ],
    ];

    /** @return list<string> the abilities this role starts with in this hospital's practice */
    public static function abilities(?Hospital $hospital, string $role): array
    {
        if ($hospital === null || ! Abilities::roleIsEditable($role)) {
            return [];
        }

        return self::SECTORS[Practice::of($hospital)->sector->key][$role] ?? [];
    }

    /** @return list<string> the permissions those abilities hand out, companions included */
    public static function permissions(?Hospital $hospital, string $role): array
    {
        $out = [];
        foreach (self::abilities($hospital, $role) as $ability) {
            foreach (Abilities::granted($ability) as $p) {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }
}
