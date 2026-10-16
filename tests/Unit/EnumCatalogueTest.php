<?php

namespace Tests\Unit;

use App\Support\EnumCatalogue;
use PHPUnit\Framework\TestCase;

/** The explicit enum list that replaced the per-request glob must stay complete. */
class EnumCatalogueTest extends TestCase
{
    public function test_every_enum_with_a_label_is_in_the_list(): void
    {
        $onDisk = [];

        foreach (glob(__DIR__.'/../../app/Enums/*.php') ?: [] as $file) {
            $class = 'App\\Enums\\'.basename($file, '.php');

            if (enum_exists($class) && method_exists($class, 'label')) {
                $onDisk[] = $class;
            }
        }

        sort($onDisk);
        $listed = EnumCatalogue::ENUMS;
        sort($listed);

        $this->assertSame($onDisk, $listed, 'Add the new enum to App\Support\EnumCatalogue::ENUMS (or remove the stale one).');
    }

    public function test_tones_map_badge_classes(): void
    {
        $this->assertSame('success', EnumCatalogue::tone('badge-active'));
        $this->assertSame('warn', EnumCatalogue::tone('high'));
        $this->assertSame('neutral', EnumCatalogue::tone('badge-unknown'));
    }
}
