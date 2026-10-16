<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\JournalEntry */
class JournalEntryResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'entry_no' => $this->entry_no,
            'date' => $this->date?->toDateString(),
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'memo' => $this->memo,
            'party' => $this->party,
            'reference' => $this->reference,
            'total' => (string) $this->total,
            'is_reversed' => $this->isReversed(),
            'can_be_reversed' => $this->canBeReversed(),
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'account' => ['id' => $l->ledger_account_id, 'code' => $l->account?->code, 'name' => $l->account?->name],
                'debit' => (string) $l->debit,
                'credit' => (string) $l->credit,
                'memo' => $l->memo,
            ])->values()),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'reverses' => $this->whenLoaded('reverses', fn () => $this->reverses ? ['uuid' => $this->reverses->uuid, 'entry_no' => $this->reverses->entry_no] : null),
            'reversed_by' => $this->whenLoaded('reversedBy', fn () => $this->reversedBy ? ['uuid' => $this->reversedBy->uuid, 'entry_no' => $this->reversedBy->entry_no] : null),
            'source' => $this->whenLoaded('source', fn () => $this->source === null ? null : ['type' => class_basename($this->source), 'id' => $this->source->uuid ?? $this->source->getKey()]),
        ];
    }
}
