<?php

namespace App\Support\Practice\Packs;

use App\Support\Practice\Pack;

/** A casualty department: the patient who cannot wait for the queue. */
final class Emergency extends Pack
{
    public static function key(): string
    {
        return 'emergency';
    }

    public static function label(): string
    {
        return 'Emergency';
    }

    public static function icon(): string
    {
        return 'fa-truck-medical';
    }

    public static function summary(): string
    {
        return 'Casualty, resuscitation, observation and an ambulance.';
    }

    public static function departments(): array
    {
        return [['Emergency', 'ER']];
    }

    public static function services(): array
    {
        return [
            ['Emergency consultation', 40000],
            ['IV fluid administration', 20000],
            ['Oxygen therapy (per hour)', 15000],
            ['Observation (up to six hours)', 50000],
            ['Resuscitation', 100000],
            ['Ambulance service', 120000],
        ];
    }

    public static function stockItems(): array
    {
        return [
            ['Adrenaline 1mg/ml', 'Injectables', 'ampoule', 50, 2000, 5000, 10],
            ['Diazepam 10mg/2ml', 'Injectables', 'ampoule', 50, 1500, 4000, 10],
        ];
    }

    public static function rooms(): array
    {
        return [['Emergency Room', 'consultation', 3]];
    }

    public static function visitReasons(): array
    {
        return ['Emergency', 'Road traffic accident'];
    }

    public static function complaints(): array
    {
        return ['Severe bleeding', 'Loss of consciousness', 'Convulsions', 'Burns'];
    }

    public static function diagnoses(): array
    {
        return ['Soft tissue injury', 'Head injury', 'Severe malaria', 'Dehydration'];
    }
}
