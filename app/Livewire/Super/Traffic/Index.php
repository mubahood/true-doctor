<?php

namespace App\Livewire\Super\Traffic;

use App\Models\TrafficEvent;
use App\Models\TrafficSession;
use App\Support\LandingIntent;
use App\Support\PlatformCurrency;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where the traffic came from, and what it was worth.
 *
 * The question this screen exists to answer is not "how many visitors" — it
 * is "which advertisement brought a hospital that is still here". So every
 * breakdown carries the steps between a click and a customer beside the
 * click count, and the tables are sorted by what they produced rather than
 * by volume. A sitelink with a thousand clicks and no sign-ups is one losing
 * money, and a screen that sorts by clicks puts it proudly at the top.
 *
 * TWO GRAINS, never mixed in one table. The ad tables count CLICKS (what
 * Google bills for) and the people behind them; a visitor who clicked two
 * sitelinks appears under both, because both earned a share of them. The
 * funnel and the audience tables count PEOPLE, once each, by first touch.
 *
 * Crawlers are excluded from every figure and counted separately, so the
 * number of them is visible. A count with the noise silently removed is a
 * count nobody can check. The one exception is landing-URL health, which is
 * about whether a URL answers — and Google's own checker is a crawler.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    private const TTL = 60;

    /** The names the Google Ads conversion actions must be created under. */
    public const CONVERSION_TRIAL = 'Trial signup';

    public const CONVERSION_PAID = 'Paid subscription';

    /** A landing URL slower than this on average is flagged. */
    public const SLOW_MS = 1000;

    /** Hospital has paid at least once. Correlated on traffic_sessions `s`. */
    private const PAID_EXISTS = 'exists (select 1 from subscriptions sub '
        .'inner join subscription_payments sp on sp.subscription_id = sub.id '
        .'where sub.hospital_id = s.converted_hospital_id)';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    /** all · paid · unpaid */
    #[Url(history: true)]
    public string $channel = 'all';

    /** A click id, visit id, campaign or hospital name — across all time. */
    #[Url(history: true, except: '')]
    public string $search = '';

    /** Trail of one visitor, opened from the table. */
    public ?int $trail = null;

    public function mount(): void
    {
        $this->authorise();
        $this->normalise();
    }

    private function authorise(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }

    private function normalise(): void
    {
        $this->from = $this->parse($this->from, fn () => Carbon::now()->subDays(29)->startOfDay());
        $this->to = $this->parse($this->to, fn () => Carbon::now()->endOfDay());

        // Backwards is not an error, just typed in the wrong order.
        if ($this->from > $this->to) {
            [$this->from, $this->to] = [$this->to, $this->from];
        }

        if (! array_key_exists($this->channel, $this->channels())) {
            $this->channel = 'all';
        }

        $this->search = mb_substr(trim($this->search), 0, 191);
    }

    private function parse(string $value, \Closure $default): string
    {
        if ($value === '') {
            return $default()->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default()->toDateString();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'channel', 'search'], true)) {
            $this->normalise();
        }
    }

    /** @return array<string,string> */
    public function presets(): array
    {
        return ['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    /** @return array<string,string> */
    public function channels(): array
    {
        return ['all' => 'All traffic', 'paid' => 'Paid clicks', 'unpaid' => 'Everything else'];
    }

    public function usePreset(string $key): void
    {
        $now = Carbon::now();

        $from = match ($key) {
            'today' => $now->copy(),
            '7' => $now->copy()->subDays(6),
            '90' => $now->copy()->subDays(89),
            default => $now->copy()->subDays(29),
        };

        $this->from = $from->toDateString();
        $this->to = $now->toDateString();
    }

    public function useChannel(string $key): void
    {
        $this->channel = $key;
        $this->normalise();
    }

    private function start(): Carbon
    {
        return Carbon::parse($this->from)->startOfDay();
    }

    private function end(): Carbon
    {
        return Carbon::parse($this->to)->endOfDay();
    }

    private function remember(string $key, \Closure $fn): mixed
    {
        return cache()->remember("traffic:{$key}:{$this->from}:{$this->to}:{$this->channel}", self::TTL, $fn);
    }

    // ── The two grains ───────────────────────────────────────────────────

    /**
     * People who first arrived in the range, crawlers out, channel applied.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TrafficSession>
     */
    private function people()
    {
        $query = TrafficSession::query()
            ->from('traffic_sessions as s')
            ->where('s.is_bot', false)
            ->whereBetween('s.first_seen_at', [$this->start(), $this->end()]);

        return match ($this->channel) {
            'paid' => $query->where(fn ($q) => $q->whereNotNull('s.gclid')->orWhereNotNull('s.gbraid')
                ->orWhereNotNull('s.wbraid')->orWhereIn('s.utm_medium', TrafficSession::PAID_MEDIA)),
            'unpaid' => $query->whereNull('s.gclid')->whereNull('s.gbraid')->whereNull('s.wbraid')
                ->where(fn ($q) => $q->whereNull('s.utm_medium')->orWhereNotIn('s.utm_medium', TrafficSession::PAID_MEDIA)),
            default => $query,
        };
    }

    /** Fresh ad clicks made in the range, by people, channel applied. */
    private function clicks(): QueryBuilder
    {
        $query = DB::table('traffic_events as e')
            ->join('traffic_sessions as s', 's.id', '=', 'e.traffic_session_id')
            ->where('e.is_click', true)
            ->where('s.is_bot', false)
            ->whereBetween('e.created_at', [$this->start(), $this->end()]);

        return match ($this->channel) {
            'paid' => $query->where(fn ($q) => $q->whereIn('e.click_id_type', TrafficEvent::PAID_CLICK_IDS)
                ->orWhereIn('e.utm_medium', TrafficSession::PAID_MEDIA)),
            'unpaid' => $query->where(fn ($q) => $q->whereNull('e.click_id_type')->orWhereNotIn('e.click_id_type', TrafficEvent::PAID_CLICK_IDS))
                ->where(fn ($q) => $q->whereNull('e.utm_medium')->orWhereNotIn('e.utm_medium', TrafficSession::PAID_MEDIA)),
            default => $query,
        };
    }

    /**
     * Seconds between two timestamps, in whichever SQL this is running on.
     * The tests run on SQLite and production on MySQL, and they share no
     * spelling for it.
     */
    private function secondsBetween(string $from, string $to): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "(julianday({$to}) - julianday({$from})) * 86400"
            : "TIMESTAMPDIFF(SECOND, {$from}, {$to})";
    }

    // ── The headline, and the funnel under it ────────────────────────────

    /**
     * @return array{visitors:int,views:int,clicks:int,engaged:int,pricing:int,form:int,demo:int,enquiries:int,
     *               trials:int,customers:int,rate:float,bounce:float,avg_pages:float,avg_seconds:int,returning:int,bots:int}
     */
    #[Computed]
    public function totals(): array
    {
        return $this->remember('totals', function (): array {
            $row = $this->people()
                ->selectRaw('count(*) as visitors')
                ->selectRaw('coalesce(sum(s.page_views), 0) as views')
                ->selectRaw('sum(case when s.page_views >= 2 then 1 else 0 end) as engaged')
                ->selectRaw('sum(case when s.page_views <= 1 then 1 else 0 end) as bounced')
                ->selectRaw('sum(case when s.visits >= 2 then 1 else 0 end) as came_back')
                ->selectRaw('sum(case when s.viewed_pricing_at is not null then 1 else 0 end) as pricing')
                ->selectRaw('sum(case when s.opened_signup_at is not null then 1 else 0 end) as form')
                ->selectRaw('sum(case when s.opened_demo_at is not null then 1 else 0 end) as demo')
                ->selectRaw('sum(case when s.enquired_at is not null then 1 else 0 end) as enquiries')
                ->selectRaw('sum(case when s.converted_hospital_id is not null then 1 else 0 end) as trials')
                ->selectRaw('sum(case when '.self::PAID_EXISTS.' then 1 else 0 end) as customers')
                // Time on site for people who opened more than one page. A
                // single-page visit has no second timestamp, and averaging
                // its zero in would say nothing true about anybody.
                ->selectRaw('avg(case when s.page_views >= 2 then '.$this->secondsBetween('s.first_seen_at', 's.last_seen_at').' end) as avg_seconds')
                ->toBase()
                ->first();

            $visitors = (int) ($row->visitors ?? 0);
            $pct = fn ($n) => $visitors > 0 ? round((int) $n / $visitors * 100, 1) : 0.0;

            return [
                'visitors' => $visitors,
                'views' => (int) ($row->views ?? 0),
                'clicks' => (int) $this->clicks()->count(),
                'engaged' => (int) ($row->engaged ?? 0),
                'pricing' => (int) ($row->pricing ?? 0),
                'form' => (int) ($row->form ?? 0),
                'demo' => (int) ($row->demo ?? 0),
                'enquiries' => (int) ($row->enquiries ?? 0),
                'trials' => (int) ($row->trials ?? 0),
                'customers' => (int) ($row->customers ?? 0),
                'returning' => (int) ($row->came_back ?? 0),
                'rate' => $pct($row->trials ?? 0),
                'bounce' => $pct($row->bounced ?? 0),
                'avg_pages' => $visitors > 0 ? round((int) ($row->views ?? 0) / $visitors, 1) : 0.0,
                'avg_seconds' => (int) round((float) ($row->avg_seconds ?? 0)),
                'bots' => TrafficSession::where('is_bot', true)
                    ->whereBetween('first_seen_at', [$this->start(), $this->end()])->count(),
            ];
        });
    }

    /**
     * From arriving to paying, in order.
     *
     * Each step is a share of everybody who arrived, not of the step
     * before, so a reader can compare any two steps without doing sums.
     *
     * @return list<array{label:string,count:int,pct:float}>
     */
    #[Computed]
    public function funnel(): array
    {
        $t = $this->totals;
        $of = fn (int $n) => $t['visitors'] > 0 ? round($n / $t['visitors'] * 100, 1) : 0.0;

        return array_map(fn ($step) => ['label' => $step[0], 'count' => $step[1], 'pct' => $of($step[1])], [
            ['Arrived', $t['visitors']],
            ['Opened a second page', $t['engaged']],
            ['Read the pricing', $t['pricing']],
            ['Opened the sign-up form', $t['form']],
            ['Started a trial', $t['trials']],
            ['Paid', $t['customers']],
        ]);
    }

    // ── What the advertising bought ──────────────────────────────────────

    /**
     * Clicks grouped by some expression, with every step after the click.
     *
     * One query per table: the figures on a row come from the same instant
     * and cannot disagree with each other.
     *
     * @param  array<string,string>  $dims  alias => SQL expression
     * @return list<array<string,mixed>>
     */
    private function clickReport(string $key, array $dims, int $limit = 25): array
    {
        return $this->remember('clicks-'.$key, function () use ($dims, $limit): array {
            $query = $this->clicks();

            foreach ($dims as $alias => $expr) {
                $query->selectRaw("{$expr} as {$alias}")->groupByRaw($expr);
            }

            $rows = $query
                ->selectRaw('count(*) as clicks')
                ->selectRaw('count(distinct s.id) as visitors')
                ->selectRaw('count(distinct case when s.page_views >= 2 then s.id end) as engaged')
                ->selectRaw('count(distinct case when s.opened_signup_at is not null then s.id end) as form')
                ->selectRaw('count(distinct case when s.converted_hospital_id is not null then s.id end) as trials')
                ->selectRaw('count(distinct case when '.self::PAID_EXISTS.' then s.id end) as customers')
                ->orderByDesc('customers')
                ->orderByDesc('trials')
                ->orderByDesc('clicks')
                ->limit($limit)
                ->get();

            return $rows->map(function ($row) use ($dims): array {
                $visitors = (int) $row->visitors;
                $out = [];

                foreach (array_keys($dims) as $alias) {
                    $out[$alias] = $row->{$alias};
                }

                return $out + [
                    'clicks' => (int) $row->clicks,
                    'visitors' => $visitors,
                    'engaged' => (int) $row->engaged,
                    'form' => (int) $row->form,
                    'trials' => (int) $row->trials,
                    'customers' => (int) $row->customers,
                    'bounce' => $visitors > 0 ? round(($visitors - (int) $row->engaged) / $visitors * 100, 1) : 0.0,
                    'rate' => $visitors > 0 ? round((int) $row->trials / $visitors * 100, 1) : 0.0,
                ];
            })->all();
        });
    }

    /**
     * Every asset in the campaign — the main ad, twelve sitelinks, three
     * price items — by the name Google Ads shows for it.
     *
     * The key is built in SQL so each asset is exactly one group: a module
     * only counts when the section was `modules`, a plan only when it was
     * `pricing`, the same rule assetName() applies.
     */
    #[Computed]
    public function byAsset(): array
    {
        $rows = $this->clickReport('asset', [
            'a_section' => 'e.section',
            'a_module' => "case when e.section = 'modules' then e.module end",
            'a_plan' => "case when e.section = 'pricing' then e.plan end",
        ]);

        return array_map(fn ($row) => ['label' => LandingIntent::assetName($row['a_section'], $row['a_module'], $row['a_plan'])] + $row, $rows);
    }

    #[Computed]
    public function byCampaign(): array
    {
        return $this->labelled($this->clickReport('campaign', ['v' => 'e.utm_campaign']), '(untagged)');
    }

    /** utm_content: which ad (the suffix sends `rsa-hms` for the responsive search ad). */
    #[Computed]
    public function byAd(): array
    {
        return $this->labelled($this->clickReport('ad', ['v' => 'e.utm_content']), '(untagged)');
    }

    /**
     * What they searched for. Only filled in if the tracking suffix carries
     * `utm_term={keyword}` or the URL carries `keyword={keyword}` — the
     * empty state says so, rather than looking like nobody searched.
     */
    #[Computed]
    public function byKeyword(): array
    {
        return array_values(array_filter(
            $this->labelled($this->clickReport('keyword', ['v' => 'e.keyword']), ''),
            fn ($row) => $row['label'] !== '',
        ));
    }

    /** @return list<array<string,mixed>> */
    private function labelled(array $rows, string $fallback): array
    {
        return array_map(fn ($row) => ['label' => (string) ($row['v'] ?? $fallback)] + $row, $rows);
    }

    // ── Who they were ────────────────────────────────────────────────────

    /**
     * People grouped by one of their own columns.
     *
     * @return list<array{label:string,sessions:int,signups:int,rate:float}>
     */
    private function breakdown(string $column, string $fallback): array
    {
        return $this->remember('by-'.$column, function () use ($column, $fallback): array {
            return $this->people()
                ->select("s.{$column}")
                ->selectRaw('count(*) as sessions')
                ->selectRaw('sum(case when s.converted_hospital_id is null then 0 else 1 end) as signups')
                ->groupBy("s.{$column}")
                ->orderByDesc('signups')
                ->orderByDesc('sessions')
                ->limit(15)
                ->toBase()
                ->get()
                ->map(function ($row) use ($column, $fallback): array {
                    $sessions = (int) $row->sessions;
                    $signups = (int) $row->signups;

                    return [
                        'label' => (string) ($row->{$column} ?? $fallback),
                        'sessions' => $sessions,
                        'signups' => $signups,
                        'rate' => $sessions > 0 ? round($signups / $sessions * 100, 1) : 0.0,
                    ];
                })->all();
        });
    }

    #[Computed]
    public function bySource(): array
    {
        return $this->breakdown('utm_source', 'untagged');
    }

    /** Where untagged people came from — search engines, links, nothing. */
    #[Computed]
    public function byReferrer(): array
    {
        return $this->breakdown('referrer_host', 'none (typed or bookmarked)');
    }

    #[Computed]
    public function byCountry(): array
    {
        return $this->breakdown('country', 'unknown');
    }

    #[Computed]
    public function byDevice(): array
    {
        return $this->breakdown('device', 'unknown');
    }

    /** People and ad clicks per day, empty days included. */
    #[Computed]
    public function daily(): array
    {
        return $this->remember('daily', function (): array {
            $people = $this->people()
                ->selectRaw('date(s.first_seen_at) as day, count(*) as n')
                ->groupByRaw('date(s.first_seen_at)')
                ->toBase()->get()->pluck('n', 'day');

            $out = [];
            $cursor = $this->start()->copy();

            // A chart that skips quiet days makes a gap look like activity.
            while ($cursor->lte($this->end())) {
                $out[$cursor->toDateString()] = (int) ($people[$cursor->toDateString()] ?? 0);
                $cursor->addDay();
            }

            return $out;
        });
    }

    // ── Whether the landing URLs work ────────────────────────────────────

    /**
     * Every public URL that was hit, with how often it failed and how slow
     * it was.
     *
     * The campaign spec's hard rule — every landing URL answers 200, fast —
     * checked against what actually happened rather than what was tested.
     * Crawlers are INCLUDED here: AdsBot is how Google decides whether an
     * ad's URL works, and its hits are the ones that matter most.
     *
     * @return list<array{path:string,hits:int,redirects:int,errors:int,avg_ms:int,max_ms:int,slow:bool}>
     */
    #[Computed]
    public function health(): array
    {
        return $this->remember('health', function (): array {
            return DB::table('traffic_events')
                ->whereBetween('created_at', [$this->start(), $this->end()])
                ->whereIn('kind', ['view', 'redirect'])
                ->select('path')
                ->selectRaw('count(*) as hits')
                ->selectRaw("sum(case when kind = 'redirect' then 1 else 0 end) as redirects")
                ->selectRaw('sum(case when status >= 400 then 1 else 0 end) as errors')
                ->selectRaw('avg(duration_ms) as avg_ms')
                ->selectRaw('max(duration_ms) as max_ms')
                ->groupBy('path')
                ->orderByDesc('errors')
                ->orderByDesc('hits')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'path' => (string) $row->path,
                    'hits' => (int) $row->hits,
                    'redirects' => (int) $row->redirects,
                    'errors' => (int) $row->errors,
                    'avg_ms' => (int) round((float) $row->avg_ms),
                    'max_ms' => (int) $row->max_ms,
                    'slow' => (float) $row->avg_ms > self::SLOW_MS,
                ])->all();
        });
    }

    // ── One visitor at a time ────────────────────────────────────────────

    /**
     * The most recent arrivals — or, with a search, every visit that
     * matches it, however long ago. A trace has to find the click the
     * hospital owner remembers, not only the ones in this month.
     */
    #[Computed]
    public function recent(): Collection
    {
        if ($this->search !== '') {
            $term = $this->search;
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

            return TrafficSession::query()
                ->with('hospital:id,name')
                ->where(fn ($q) => $q
                    ->where('uuid', $term)
                    ->orWhere('gclid', $term)
                    ->orWhere('gbraid', $term)
                    ->orWhere('wbraid', $term)
                    ->orWhere('utm_campaign', $term)
                    ->orWhere('utm_content', $term)
                    ->orWhere('utm_term', $term)
                    ->orWhereHas('events', fn ($e) => $e->where('click_id', $term))
                    ->orWhereHas('hospital', fn ($h) => $h->where('name', 'like', $like)))
                ->orderByDesc('last_seen_at')
                ->limit(60)
                ->get();
        }

        return $this->people()
            ->select('s.*')
            ->with('hospital:id,name')
            ->orderByDesc('s.last_seen_at')
            ->limit(60)
            ->get();
    }

    #[Computed]
    public function trailSession(): ?TrafficSession
    {
        return $this->trail === null ? null : TrafficSession::with('hospital:id,name')->find($this->trail);
    }

    /** Everything one visitor did, in order. */
    #[Computed]
    public function trailEvents(): Collection
    {
        if ($this->trail === null) {
            return collect();
        }

        return TrafficEvent::where('traffic_session_id', $this->trail)
            ->orderBy('id')
            ->limit(300)
            ->get();
    }

    public function showTrail(int $id): void
    {
        $this->authorise();
        $this->trail = $id;
    }

    public function closeTrail(): void
    {
        $this->trail = null;
    }

    // ── Back to Google ───────────────────────────────────────────────────

    /**
     * Conversions with their Google click id, for Google Ads offline
     * conversion import.
     *
     * This is what turns the screen from interesting into useful: Google can
     * only optimise a campaign towards conversions it knows about, and a
     * trial that started days after the click is invisible to it until this
     * file is uploaded. Two conversions per hospital, so bidding can learn
     * the difference between a trial and a customer:
     *
     *   - "Trial signup", value 0, when the hospital was created;
     *   - "Paid subscription", valued at what was paid, for every payment.
     *
     * Read from the hospital, not from the traffic tables, so it still works
     * after old visits are pruned. Only a gclid of the shape Google issues is
     * exported: the value came from a URL anybody can type, and a cell
     * starting with "=" is a formula to the spreadsheet somebody opens this
     * in on the way to uploading it.
     *
     * The column names and the time format are Google's, not ours — that is
     * what its importer expects.
     */
    public function exportConversions(): StreamedResponse
    {
        $this->authorise();

        $trials = DB::table('hospitals')
            ->whereNull('deleted_at')
            ->whereNotNull('gclid')
            ->whereBetween('attributed_at', [$this->start(), $this->end()])
            ->orderBy('attributed_at')
            ->get(['gclid', 'attributed_at as at'])
            ->map(fn ($r) => [$r->gclid, self::CONVERSION_TRIAL, $r->at, '0']);

        $payments = DB::table('subscription_payments as sp')
            ->join('subscriptions as sub', 'sub.id', '=', 'sp.subscription_id')
            ->join('hospitals as h', 'h.id', '=', 'sub.hospital_id')
            ->whereNull('h.deleted_at')
            ->whereNotNull('h.gclid')
            ->whereBetween('sp.paid_at', [$this->start(), $this->end()])
            ->orderBy('sp.paid_at')
            ->get(['h.gclid', 'sp.paid_at as at', 'sp.amount'])
            ->map(fn ($r) => [$r->gclid, self::CONVERSION_PAID, $r->at, number_format((float) $r->amount, 2, '.', '')]);

        $rows = $trials->concat($payments)
            ->filter(fn ($r) => is_string($r[0]) && preg_match('/^[A-Za-z0-9_-]{10,191}$/', $r[0]) === 1)
            ->sortBy(fn ($r) => (string) $r[2])
            ->values();

        $filename = 'google-ads-conversions-'.$this->from.'-to-'.$this->to.'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');

            // Google's own header row, spelled its way.
            fputcsv($out, ['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency']);

            foreach ($rows as [$gclid, $name, $at, $value]) {
                fputcsv($out, [
                    $gclid,
                    $name,
                    // "yyyy-MM-dd HH:mm:ss+|-HH:mm" — the format its importer
                    // insists on, offset included.
                    Carbon::parse($at)->format('Y-m-d H:i:sP'),
                    $value,
                    PlatformCurrency::CHARGE,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $this->authorise();

        return view('livewire.super.traffic.index')->title('Traffic & campaigns');
    }
}
