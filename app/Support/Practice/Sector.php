<?php

namespace App\Support\Practice;

/**
 * A kind of practice — a dental clinic, a maternity home, an imaging centre —
 * as the handful of specialty packs it is made of.
 *
 * `suggested` are the packs a practice of this kind most often adds to itself
 * (a medical clinic that also runs antenatal care; a maternity home with an
 * ultrasound), offered first where the hospital chooses. `modules` are the
 * parts of the system it uses beyond what its packs switch on.
 */
final readonly class Sector
{
    /**
     * @param  list<string>  $packs
     * @param  list<string>  $suggested
     * @param  list<string>  $modules
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $summary,
        public array $packs,
        public array $suggested = [],
        public array $modules = [],
    ) {}
}
