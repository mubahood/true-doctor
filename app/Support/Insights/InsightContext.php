<?php

namespace App\Support\Insights;

use App\Models\Hospital;
use App\Models\User;
use App\Support\Edition;
use App\Support\Practice\Practice;
use Illuminate\Support\Carbon;

/**
 * Who is asking, for which hospital and practice, and when - plus a memo so
 * rules that need the same figure (stock alerts, unpaid invoices) share one
 * set of queries within a request.
 */
final class InsightContext
{
    /** @var array<string,mixed> */
    private array $memo = [];

    public function __construct(
        public readonly User $user,
        public readonly Hospital $hospital,
        public readonly Practice $practice,
        public readonly Carbon $now,
    ) {}

    /** A permission the person has AND the practice does the work for. */
    public function can(string $permission): bool
    {
        return $this->user->can($permission) && $this->practice->allows($permission);
    }

    public function uses(string $module): bool
    {
        return $this->practice->uses($module);
    }

    public function edition(): string
    {
        return Edition::name();
    }

    /**
     * @template T
     *
     * @param  callable():T  $compute
     * @return T
     */
    public function memo(string $key, callable $compute): mixed
    {
        return array_key_exists($key, $this->memo) ? $this->memo[$key] : $this->memo[$key] = $compute();
    }
}
