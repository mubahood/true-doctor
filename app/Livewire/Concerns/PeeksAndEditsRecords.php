<?php

namespace App\Livewire\Concerns;

/**
 * A quick view on a list whose rows are also editable in place.
 *
 * Almost every catalogue screen is this shape: a table, a create/edit dialog
 * (CrudModal) and, now, a dialog that READS one row. The only thing the pair
 * needs on top of the two traits is the hand-off between them — and it has to
 * be a hand-off rather than a stack, because two dialogs open at once is how
 * somebody ends up editing one record while looking at another.
 *
 * Components that only read use PeeksRecords on its own.
 */
trait PeeksAndEditsRecords
{
    use PeeksRecords;

    abstract public function edit(int $id): void;

    public function editPeeked(): void
    {
        $id = $this->peekId;
        $this->closePeek();

        if ($id !== null) {
            $this->edit($id);
        }
    }
}
