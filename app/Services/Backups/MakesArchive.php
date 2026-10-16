<?php

namespace App\Services\Backups;

/** Something that writes the whole installation (database, settings, documents) to one zip. */
interface MakesArchive
{
    /** @return array{path:string,size:int} */
    public function create(): array;
}
