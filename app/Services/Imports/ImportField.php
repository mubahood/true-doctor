<?php

namespace App\Services\Imports;

/**
 * One column an import understands: its heading in our template, the note
 * written under it, an example, and the other headings people give it in
 * their own sheets ("Tel", "Phone No" and "Contact" all mean the phone).
 */
final class ImportField
{
    /** @param list<string> $aliases */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $note,
        public readonly string $example = '',
        public readonly bool $required = false,
        public readonly array $aliases = [],
    ) {}

    /** A heading as compared: lower case, letters and digits only ("Phone No." => "phoneno"). */
    public static function normalise(string $heading): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($heading)));
    }

    /** @return list<string> every normalised heading that means this field */
    public function names(): array
    {
        return array_values(array_unique(array_map(
            fn (string $h) => self::normalise($h),
            array_merge([$this->key, $this->label], $this->aliases),
        )));
    }
}
