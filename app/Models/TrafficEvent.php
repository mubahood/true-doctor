<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One hit on the public site — a page read, a redirect, an enquiry sent, a
 * sign-up.
 *
 * The trail. Without it a session says somebody arrived from an advertisement
 * and nothing about whether they read anything — which is the difference
 * between knowing a click happened and knowing whether it was worth paying
 * for. `is_click` marks the hits that were a fresh ad click, as opposed to
 * the same click seen again through a redirect or a reload.
 *
 * Only `created_at`: an event never changes, so a second timestamp column
 * would be a row of duplicated values in every insert.
 */
class TrafficEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** How long an unconverted visitor's trail is kept. Thirteen months: a year-on-year comparison, and a margin. */
    public const KEEP_DAYS = 400;

    /**
     * Click ids that prove a click was bought. Not `fbclid`: Meta adds it
     * to ordinary shared links too, so it proves somebody came from
     * Facebook and nothing about whether anybody paid.
     */
    public const PAID_CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'msclkid'];

    protected $fillable = [
        'traffic_session_id', 'kind', 'path', 'status', 'redirect_to', 'duration_ms',
        'section', 'module', 'plan',
        'is_click', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'keyword',
        'click_id', 'click_id_type', 'ad_params',
        'referrer_host', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'is_click' => 'boolean',
            'status' => 'integer',
            'duration_ms' => 'integer',
            'ad_params' => 'array',
        ];
    }

    /** @return BelongsTo<TrafficSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrafficSession::class, 'traffic_session_id');
    }

    /**
     * Old trails, except the ones that ended in a hospital — the path that
     * led to a customer is worth keeping for as long as the customer is.
     *
     * @return Builder<TrafficEvent>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', Carbon::now()->subDays(self::KEEP_DAYS))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('traffic_sessions')
                ->whereColumn('traffic_sessions.id', 'traffic_events.traffic_session_id')
                ->whereNotNull('traffic_sessions.converted_hospital_id'));
    }
}
