<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowing which advertisement brought a hospital, and what it did before it
 * signed up.
 *
 * Two tables and a set of columns on `hospitals`. None of them carry a
 * hospital_id and none are tenant-scoped, deliberately: this is the
 * PLATFORM's own data about its own marketing, not a hospital's record of its
 * patients. A tenant scope on it would make it invisible to the only person
 * who needs it.
 *
 * TWO GRAINS, ON PURPOSE. A session is a PERSON (first touch, milestones,
 * what they became). An event is a HIT, and the hits that are fresh ad clicks
 * are flagged — because Google bills per click, not per person, and somebody
 * who clicks "Pricing" on Monday and "Start Free Trial" on Thursday has been
 * paid for twice and has told us two different things.
 *
 * WHAT IS DELIBERATELY NOT STORED. No raw IP address — a keyed hash, so two
 * visits can be recognised as the same visitor without the address ever being
 * recoverable from the table or from a backup of it. No name, no email, and
 * nothing a visitor typed. Everything here is either something the ad put in
 * the URL or something the browser volunteered in a header.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── One row per visitor, holding the FIRST touch ─────────────────
        // First touch, not last: the advertisement that introduced somebody
        // to the product is the one that earned the sign-up, and a visitor
        // who comes back three times by typing the address has not been
        // introduced three times. Every later click is on traffic_events.
        Schema::create('traffic_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // The cookie value, hashed. Identifies a returning visitor
            // without the cookie itself being readable out of the table.
            $table->string('visitor_key', 64)->unique();

            // What the campaign said.
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->string('utm_content', 160)->nullable();
            $table->string('utm_term', 160)->nullable();

            // Google's click ids. `gclid` is the ordinary one; `gbraid` and
            // `wbraid` are what Google sends INSTEAD of it for many iOS
            // clicks, and a session that kept only gclid would file those
            // paid clicks as direct traffic. These are the values that go
            // back to Google as offline conversions.
            $table->string('gclid', 191)->nullable()->index();
            $table->string('gbraid', 191)->nullable();
            $table->string('wbraid', 191)->nullable();

            // Anything else the ad's tracking template said (keyword,
            // matchtype, network, gad_campaignid …), from a fixed allow-list.
            $table->json('ad_params')->nullable();

            // What the ad asked us to show.
            $table->string('landing_path', 191)->nullable();
            $table->string('landing_section', 40)->nullable();
            $table->string('landing_module', 40)->nullable();
            $table->string('landing_plan', 40)->nullable();
            $table->string('referrer_host', 191)->nullable();

            // Who arrived, in the only terms worth keeping.
            $table->string('device', 16)->nullable();     // desktop / mobile / tablet
            $table->string('browser', 32)->nullable();
            $table->string('platform', 32)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_hash', 64)->nullable();

            // Crawlers are recorded rather than dropped, so the headline
            // figures can exclude them AND somebody can see how much noise
            // was excluded. A number with the bots silently removed is a
            // number nobody can check.
            $table->boolean('is_bot')->default(false)->index();

            // How much of them there was. `visits` counts returns after half
            // an hour away; `clicks` counts paid clicks, which is what the
            // campaign actually spent on this person.
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('visits')->default(1);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_click_at')->nullable();

            // The milestones between arriving and signing up — the first
            // time each happened. They are what turn "38 clicks, 1 sign-up"
            // into "38 clicks, 30 read the pricing, 4 opened the form, 1
            // finished it", which says where the money is leaking.
            $table->timestamp('viewed_pricing_at')->nullable();
            $table->timestamp('opened_signup_at')->nullable();
            $table->timestamp('opened_demo_at')->nullable();
            $table->timestamp('enquired_at')->nullable();

            // What it came to. Null until they sign up.
            $table->foreignId('converted_hospital_id')->nullable()
                ->constrained('hospitals')->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->index();

            $table->timestamps();

            // The two questions the reporting screen asks constantly.
            $table->index(['utm_source', 'utm_campaign']);
            $table->index(['is_bot', 'first_seen_at']);
        });

        // ── One row per hit ──────────────────────────────────────────────
        // The trail. Without it the session says somebody arrived from an
        // advertisement and nothing about whether they read anything.
        Schema::create('traffic_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traffic_session_id')->constrained()->cascadeOnDelete();

            // view · redirect · enquiry · signup. A redirect is not a page
            // somebody read, and counting it as one doubles every sitelink.
            $table->string('kind', 16)->default('view');
            $table->string('path', 191);
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('redirect_to', 191)->nullable();

            // How long the server took. The campaign spec is blunt about it:
            // every landing URL must be 200 and fast, or Google stops
            // showing the ad. This is how anybody finds out it is not.
            $table->unsignedInteger('duration_ms')->nullable();

            // Repeated per hit, not only on the session: a visitor who comes
            // back through a second sitelink has told us something, and the
            // session's first-touch columns cannot hold it.
            $table->string('section', 40)->nullable();
            $table->string('module', 40)->nullable();
            $table->string('plan', 40)->nullable();

            // This hit's own attribution, so last-touch, per-ad and
            // per-keyword reports are one GROUP BY away.
            $table->boolean('is_click')->default(false);
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->string('utm_content', 160)->nullable();
            $table->string('keyword', 160)->nullable();
            $table->string('click_id', 191)->nullable()->index();
            $table->string('click_id_type', 8)->nullable();   // gclid · gbraid · wbraid · msclkid · fbclid
            $table->json('ad_params')->nullable();

            $table->string('referrer_host', 191)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['traffic_session_id', 'created_at']);
            $table->index(['path', 'created_at']);
            $table->index(['is_click', 'created_at']);
        });

        // ── What a hospital was brought here by ──────────────────────────
        // Copied onto the hospital rather than only linked, so the answer
        // survives the traffic tables being pruned, and so a hospital's own
        // record says where it came from.
        Schema::table('hospitals', function (Blueprint $table) {
            $table->string('utm_source', 120)->nullable()->after('status');
            $table->string('utm_medium', 120)->nullable()->after('utm_source');
            $table->string('utm_campaign', 160)->nullable()->after('utm_medium');
            $table->string('utm_content', 160)->nullable()->after('utm_campaign');
            $table->string('utm_term', 160)->nullable()->after('utm_content');
            $table->string('gclid', 191)->nullable()->after('utm_term');
            $table->string('gbraid', 191)->nullable()->after('gclid');
            $table->string('wbraid', 191)->nullable()->after('gbraid');
            $table->string('landing_section', 40)->nullable()->after('wbraid');
            $table->string('landing_module', 40)->nullable()->after('landing_section');
            $table->string('landing_plan', 40)->nullable()->after('landing_module');
            $table->string('landing_referrer', 191)->nullable()->after('landing_plan');
            $table->timestamp('attributed_at')->nullable()->after('landing_referrer');

            $table->index('gclid');
            $table->index(['utm_source', 'utm_campaign']);
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropIndex(['utm_source', 'utm_campaign']);
            $table->dropIndex(['gclid']);
            $table->dropColumn([
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                'gclid', 'gbraid', 'wbraid', 'landing_section', 'landing_module', 'landing_plan',
                'landing_referrer', 'attributed_at',
            ]);
        });

        Schema::dropIfExists('traffic_events');
        Schema::dropIfExists('traffic_sessions');
    }
};
