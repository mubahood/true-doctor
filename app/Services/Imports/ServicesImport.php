<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\OrderItem;
use App\Models\Service;
use App\Support\CatalogueStore;
use RuntimeException;

/**
 * The price list: services and procedures with their prices, saved the way
 * the price list page saves one (CatalogueStore), so a name archived earlier
 * comes back rather than colliding with itself.
 */
class ServicesImport extends ImportHandler
{
    public function fields(): array
    {
        return [
            new ImportField('name', 'Service', 'The name as it should appear on a bill.', 'Tooth extraction', true,
                ['service', 'service name', 'name', 'procedure', 'procedure name', 'item', 'description', 'treatment', 'particulars', 'services']),
            new ImportField('code', 'Code', 'Your own short code, if you use one.', 'EXT-01', false, ['service code', 'item code', 'procedure code']),
            new ImportField('category', 'Category', 'The group it is listed under.', 'Dental', false, ['department', 'group', 'type', 'specialty', 'section']),
            new ImportField('price', 'Price', 'A number, with or without commas.', '50000', true,
                ['amount', 'cost', 'fee', 'fees', 'charge', 'charges', 'rate', 'unit price', 'selling price', 'price ugx', 'ugx', 'tariff']),
            new ImportField('max_price', 'Highest price', 'For a price range only ("50,000 to 80,000"): the top of the range.', '', false,
                ['max price', 'maximum price', 'price to', 'upper price', 'to']),
            new ImportField('tax_exempt', 'Tax exempt', 'Yes if no tax is charged on it. Empty means No.', 'No', false, ['no tax', 'vat exempt', 'exempt', 'zero rated']),
        ];
    }

    public function sheetKey(array $v): ?string
    {
        $name = mb_strtolower(trim($v['name'] ?? ''));

        return $name === '' ? null : $name;
    }

    public function check(array $v): array
    {
        $problems = [];
        $name = trim($v['name'] ?? '');
        if ($name === '') {
            $problems[] = 'There is no service name.';
        } elseif (mb_strlen($name) > 120) {
            $problems[] = 'The name is longer than 120 letters.';
        }

        $price = $this->money($v['price'] ?? '');
        if ($price === null) {
            $problems[] = 'There is no price. Write 0 if it is free.';
        } elseif ($price === false) {
            $problems[] = 'Price "'.$v['price'].'" is not an amount.';
        } elseif (bccomp($price, '0', 2) < 0) {
            $problems[] = 'The price is below zero.';
        }

        $max = $this->money($v['max_price'] ?? '');
        if ($max === false) {
            $problems[] = 'Highest price "'.$v['max_price'].'" is not an amount.';
        } elseif (is_string($max) && is_string($price) && bccomp($max, $price, 2) <= 0) {
            $problems[] = 'The highest price must be more than the price.';
        }

        if (mb_strlen($v['code'] ?? '') > 32) {
            $problems[] = 'The code is longer than 32 characters.';
        }
        if (mb_strlen($v['category'] ?? '') > 60) {
            $problems[] = 'The category is longer than 60 characters.';
        }
        if (($v['tax_exempt'] ?? '') !== '' && $this->yes($v['tax_exempt']) === null) {
            $problems[] = 'Tax exempt "'.$v['tax_exempt'].'" should be Yes or No.';
        }

        $match = null;
        if ($problems === []) {
            $hit = Service::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
            $reason = 'Same name';
            if ($hit === null && ($v['code'] ?? '') !== '') {
                $hit = Service::query()->where('code', $v['code'])->first();
                $reason = 'Same code';
            }
            if ($hit !== null) {
                $match = ['id' => $hit->id, 'reason' => $reason, 'label' => $hit->name.' at '.$hit->priceLabel()];
            }
        }

        return ['problems' => $problems, 'match' => $match];
    }

    public function save(array $v, string $decision, ?int $matchId, ImportBatch $batch, ?int $by): array
    {
        $price = $this->money($v['price'] ?? '');
        $max = $this->money($v['max_price'] ?? '');
        $data = [
            'name' => trim($v['name']),
            'price' => is_string($price) ? $price : '0.00',
        ];
        if (is_string($max)) {
            $data['max_price'] = $max;
        }
        foreach (['code', 'category'] as $key) {
            if (($v[$key] ?? '') !== '') {
                $data[$key] = $v[$key];
            }
        }
        if (($v['tax_exempt'] ?? '') !== '') {
            $data['tax_exempt'] = (bool) $this->yes($v['tax_exempt']);
        }

        if ($decision === 'update' && $matchId !== null) {
            $service = Service::query()->find($matchId) ?? throw new RuntimeException('The price list entry it matched is no longer there.');
            // The name stays as it is on the price list: a match by code
            // must not rename what the bills already call it.
            unset($data['name']);
            CatalogueStore::persist(Service::class, $service, $data);

            return ['status' => ImportRow::UPDATED, 'type' => Service::class, 'id' => $service->id];
        }

        [$service, $state] = CatalogueStore::persist(Service::class, null, $data + ['is_active' => true], ['code', 'category']);

        return ['status' => ImportRow::ADDED, 'type' => Service::class, 'id' => (int) $service->getKey(), 'extra' => ['restored' => $state === 'restored']];
    }

    public function undoBlocker(ImportRow $row): ?string
    {
        $service = Service::query()->find($row->record_id);
        if ($service === null) {
            return null;
        }

        return match (true) {
            OrderItem::query()->where('service_id', $service->id)->exists() => 'Has been charged since.',
            $row->updated_at !== null && $service->updated_at !== null && $service->updated_at->gt($row->updated_at) => 'Was changed since.',
            default => null,
        };
    }

    public function undo(ImportRow $row, ?int $by): void
    {
        Service::query()->find($row->record_id)?->delete();
    }
}
