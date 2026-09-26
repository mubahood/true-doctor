<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One visitor to the public site, and where they came from.
 *
 * Platform data, not a hospital's — so no BelongsToHospital, no global scope,
 * and no hospital_id. It is the only table in this system that is deliberately
 * outside tenancy, because the only person it is for sits above every tenant.
 *
 * @property string $visitor_key
 * @property ?string $gclid
 * @property bool $is_bot
 * @property \Illuminate\Support\Carbon|null $first_seen_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class TrafficSession extends Model
{
    use MassPrunable;

    /** The mediums that mean somebody paid for the click. */
    public const PAID_MEDIA = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search'];

    /** Crawler rows are only kept long enough to see the noise they make. */
    public const KEEP_BOT_DAYS = 90;

    protected $fillable = [
        'uuid', 'visitor_key',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'gclid', 'gbraid', 'wbraid', 'ad_params',
        'landing_path', 'landing_section', 'landing_module', 'landing_plan', 'referrer_host',
        'device', 'browser', 'platform', 'country', 'user_agent', 'ip_hash',
        'is_bot', 'page_views', 'visits', 'clicks', 'first_seen_at', 'last_seen_at', 'last_click_at',
        'viewed_pricing_at', 'opened_signup_at', 'opened_demo_at', 'enquired_at',
        'converted_hospital_id', 'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'page_views' => 'integer',
            'visits' => 'integer',
            'clicks' => 'integer',
            'ad_params' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_click_at' => 'datetime',
            'viewed_pricing_at' => 'datetime',
            'opened_signup_at' => 'datetime',
            'opened_demo_at' => 'datetime',
            'enquired_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /** @return HasMany<TrafficEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TrafficEvent::class);
    }

    /** @return BelongsTo<Hospital, $this> */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'converted_hospital_id');
    }

    /**
     * People, as opposed to crawlers.
     *
     * Every headline figure uses this. The bots stay in the table so the
     * number of them is visible too — a count with the noise silently removed
     * is a count nobody can check.
     *
     * @param  Builder<TrafficSession>  $query
     */
    public function scopeHuman(Builder $query): void
    {
        $query->where('is_bot', false);
    }

    /** @param Builder<TrafficSession> $query */
    public function scopeConverted(Builder $query): void
    {
        $query->whereNotNull('converted_hospital_id');
    }

    /**
     * Arrived through a click somebody paid for. Kept in step with isPaid(),
     * so a count and a row never disagree.
     *
     * @param  Builder<TrafficSession>  $query
     */
    public function scopePaid(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNotNull('gclid')
            ->orWhereNotNull('gbraid')
            ->orWhereNotNull('wbraid')
            ->orWhereIn('utm_medium', self::PAID_MEDIA));
    }

    /** @param Builder<TrafficSession> $query */
    public function scopeUnpaid(Builder $query): void
    {
        $query->whereNull('gclid')->whereNull('gbraid')->whereNull('wbraid')
            ->where(fn ($q) => $q->whereNull('utm_medium')->orWhereNotIn('utm_medium', self::PAID_MEDIA));
    }

    /**
     * What retention removes: crawlers after three months, people who never
     * signed up after the same thirteen months their trail is kept. A visit
     * that became a hospital is kept — it is the only proof of which
     * advertisement earned it.
     *
     * @return Builder<TrafficSession>
     */
    public function prunable(): Builder
    {
        return static::whereNull('converted_hospital_id')
            ->where(fn ($q) => $q
                ->where(fn ($b) => $b->where('is_bot', true)
                    ->where('last_seen_at', '<', Carbon::now()->subDays(self::KEEP_BOT_DAYS)))
                ->orWhere('last_seen_at', '<', Carbon::now()->subDays(TrafficEvent::KEEP_DAYS)));
    }

    /** Arrived through a paid click rather than by typing the address. */
    public function isPaid(): bool
    {
        return $this->googleClickId() !== null
            || in_array(strtolower((string) $this->utm_medium), self::PAID_MEDIA, true);
    }

    /** Anything at all saying where they came from. */
    public function hasAttribution(): bool
    {
        return $this->googleClickId() !== null
            || $this->utm_source !== null || $this->utm_medium !== null || $this->utm_campaign !== null;
    }

    /** The Google click id a conversion is reported against: gclid, else the iOS ones. */
    public function googleClickId(): ?string
    {
        return $this->gclid ?? $this->gbraid ?? $this->wbraid;
    }

    /** How long they were here, first hit to last. */
    public function secondsOnSite(): int
    {
        return $this->first_seen_at && $this->last_seen_at
            ? max(0, (int) $this->first_seen_at->diffInSeconds($this->last_seen_at))
            : 0;
    }

    /** "google / cpc", or "direct" when nothing said otherwise. */
    public function sourceLabel(): string
    {
        if ($this->utm_source === null && $this->referrer_host === null) {
            return $this->googleClickId() !== null ? 'google / cpc' : 'direct';
        }

        if ($this->utm_source === null) {
            return (string) $this->referrer_host;
        }

        return $this->utm_medium === null
            ? (string) $this->utm_source
            : $this->utm_source.' / '.$this->utm_medium;
    }
}
