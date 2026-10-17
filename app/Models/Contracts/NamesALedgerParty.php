<?php

namespace App\Models\Contracts;

/**
 * A record that caused a stock movement and knows who the other side was.
 *
 * The books post a delivery as "Dr inventory, Cr accounts payable"; without a
 * party nobody can tell from the journal WHICH supplier is owed. A goods
 * received line and a supplier return implement this, and the Bookkeeper
 * copies the answer onto the journal entry.
 */
interface NamesALedgerParty
{
    /** Who the money is with: a supplier's name. */
    public function ledgerParty(): ?string;

    /** The document it came from: a GRN or return number. */
    public function ledgerReference(): ?string;
}
