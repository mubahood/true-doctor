<?php

namespace App\Livewire\Concerns;

/**
 * A field that holds a LIST written as prose.
 *
 * "Fever, headache, joint pain" is one sentence and three findings. Clicking a
 * second suggestion used to wipe the first, which made the pills useful for
 * exactly one answer — and a patient almost never presents with exactly one
 * thing.
 *
 * So a phrase is added to what is there, and clicking it again takes it out.
 * The pill's own state is the reading: lit means it is in the field.
 *
 * Case is handled the way somebody writing it would: the first item keeps its
 * capital, the ones after it do not, and anything TYPED by hand is never
 * re-cased — the field belongs to whoever is writing in it, and these are
 * offers rather than a form of input.
 */
trait ChoosesPhrases
{
    /**
     * Add the phrase to a field, or take it out if it is already there.
     *
     * Whitelisted by the host: a field name arriving from the wire can only
     * ever be one the form actually writes.
     */
    public function togglePhrase(string $field, string $phrase): void
    {
        if (! in_array($field, $this->phraseFields(), true)) {
            return;
        }

        $phrase = trim($phrase);

        if ($phrase === '') {
            return;
        }

        $items = $this->phraseItems($field);
        $found = null;

        foreach ($items as $i => $item) {
            if (mb_strtolower($item) === mb_strtolower($phrase)) {
                $found = $i;
                break;
            }
        }

        if ($found === null) {
            // Appended as it would be said: "Fever, headache".
            $current = trim((string) ($this->{$field} ?? ''));
            $this->{$field} = $current === '' ? $phrase : $current.', '.mb_strtolower(mb_substr($phrase, 0, 1)).mb_substr($phrase, 1);

            return;
        }

        unset($items[$found]);
        $items = array_values($items);

        if ($items === []) {
            $this->{$field} = null;

            return;
        }

        // Whatever is left keeps the words it had; only the item that is now
        // first gets its capital back, because it is now the start of a
        // sentence and nothing else about it has changed.
        $items[0] = mb_strtoupper(mb_substr($items[0], 0, 1)).mb_substr($items[0], 1);

        $this->{$field} = implode(', ', $items);
    }

    /** Is this phrase one of the things the field currently says? */
    public function hasPhrase(string $field, string $phrase): bool
    {
        $phrase = mb_strtolower(trim($phrase));

        foreach ($this->phraseItems($field) as $item) {
            if (mb_strtolower($item) === $phrase) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the field says, as a list.
     *
     * @return list<string>
     */
    private function phraseItems(string $field): array
    {
        $value = trim((string) ($this->{$field} ?? ''));

        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $item) => $item !== '',
        ));
    }

    /**
     * The fields this form lets a phrase be written into.
     *
     * @return list<string>
     */
    abstract protected function phraseFields(): array;
}
