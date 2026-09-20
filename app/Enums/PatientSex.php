<?php

namespace App\Enums;

enum PatientSex: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Other => 'Other',
        };
    }

    /** @return array<string,string> value => label, for form selects. */
    public static function options(): array
    {
        return array_reduce(self::cases(), function (array $c, self $s) {
            $c[$s->value] = $s->label();

            return $c;
        }, []);
    }
}
