<?php

namespace App\Services;

use App\Models\StandaloneLicence;
use App\Models\StandaloneOrder;
use App\Models\StandaloneProduct;
use App\Models\StandaloneRelease;
use App\Notifications\StandaloneDownloadReady;
use App\Services\Gateway\GatewayCharge;
use App\Services\Gateway\GatewayVerification;
use App\Services\Gateway\PesapalGateway;
use App\Services\Licensing\Signer;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Selling the standalone edition (docs/proposals/standalone-edition.md §7):
 * a purchase or a renewal is an order, paid through Pesapal, settled only
 * after the payment is verified with Pesapal itself, exactly once however many
 * times the callback and the IPN arrive. A purchase issues a licence; a
 * renewal extends one. Either way the buyer gets an email with a private link
 * to their downloads, and the price they saw at checkout is the price
 * recorded — a later price change does not reach an order already made.
 */
class StandaloneSalesService
{
    public function __construct(private readonly PesapalGateway $gateway, private readonly Signer $signer) {}

    public function signingReady(): bool
    {
        try {
            $this->signer->sign(['probe' => true]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Selling is open: signing works and there is something to download. */
    public function open(): bool
    {
        return Edition::isSaas() && $this->signingReady() && StandaloneRelease::where('status', 'published')->exists();
    }

    /** @return array{0:StandaloneOrder,1:GatewayCharge} */
    public function start(StandaloneProduct $product, string $name, string $email, string $domain): array
    {
        if (! $product->is_active || ! $this->open()) {
            throw new \DomainException('Standalone checkout is not available yet.');
        }

        return $this->charge(StandaloneOrder::create([
            'product_id' => $product->id, 'kind' => 'purchase', 'reference' => $this->reference(),
            'customer_name' => $name, 'customer_email' => mb_strtolower($email),
            'domain' => mb_strtolower($domain), 'amount_ugx' => $product->price_ugx,
            'updates_months' => $product->updates_months, 'limits' => $product->limits,
            'status' => 'pending',
        ]), 'True-Doctor Standalone licence');
    }

    /**
     * Ask the gateway about an order the buyer is waiting on: settled, already
     * settled, still pending, not successful, or no payment started for it.
     */
    public function check(StandaloneOrder $order): string
    {
        if ($order->status === 'paid') {
            return 'already_settled';
        }
        if (blank($order->gateway_tracking_id)) {
            return 'not_started';
        }

        return $this->settle((string) $order->gateway_tracking_id);
    }

    /** @return array{0:StandaloneOrder,1:GatewayCharge} */
    public function startRenewal(StandaloneLicence $licence): array
    {
        $product = $licence->product;
        if ($licence->status !== 'active' || $product === null || $product->renewal_price_ugx === null || ! $this->open()) {
            throw new \DomainException('Online renewal is not available for this licence. Contact us and we will extend it.');
        }

        return $this->charge(StandaloneOrder::create([
            'product_id' => $product->id, 'kind' => 'renewal', 'licence_id' => $licence->id,
            'reference' => $this->reference(), 'customer_name' => $licence->customer_name,
            'customer_email' => $licence->customer_email, 'domain' => $licence->domain(),
            'amount_ugx' => $product->renewal_price_ugx, 'updates_months' => $product->updates_months,
            'limits' => $licence->limits, 'status' => 'pending',
        ]), 'True-Doctor Standalone — '.$product->updates_months.' more months of updates');
    }

    public function settle(string $trackingId): string
    {
        return $this->settleVerified($this->gateway->verify($trackingId));
    }

    public function settleVerified(GatewayVerification $verified): string
    {
        if (! $verified->successful || ! str_starts_with($verified->reference, 'TDL-')) {
            return 'not_successful';
        }

        return DB::transaction(function () use ($verified): string {
            $order = StandaloneOrder::where('reference', $verified->reference)->lockForUpdate()->first();
            if ($order === null) {
                return 'unknown_reference';
            }
            if ($order->status === 'paid') {
                return 'already_settled';
            }
            if (strtoupper($verified->currency) !== 'UGX'
                || bccomp($verified->amount, (string) $order->amount_ugx, 2) < 0) {
                $order->update(['status' => 'mismatch']);

                return 'amount_or_currency_mismatch';
            }
            if ($order->gateway_tracking_id !== null && $order->gateway_tracking_id !== $verified->providerRef) {
                return 'tracking_mismatch';
            }

            if ($order->kind === 'renewal') {
                $licence = StandaloneLicence::whereKey($order->licence_id)->lockForUpdate()->firstOrFail();
                $licence->update(['updates_until' => $this->extended($licence, $order->updates_months), 'status' => 'active']);
                $reason = 'renewed';
            } else {
                $licence = StandaloneLicence::create([
                    'product_id' => $order->product_id, 'customer_name' => $order->customer_name,
                    'customer_email' => $order->customer_email, 'domains' => [$order->domain],
                    'limits' => $order->limits,
                    'updates_until' => now()->addMonthsNoOverflow($order->updates_months)->toDateString(),
                    'status' => 'active', 'source' => 'online', 'reference' => $order->reference,
                ]);
                $reason = 'purchased';
            }

            $order->update([
                'status' => 'paid', 'licence_id' => $licence->id,
                'gateway_tracking_id' => $verified->providerRef, 'paid_at' => now(),
            ]);

            DB::afterCommit(fn () => $this->sendLink($licence, $reason));

            return 'settled';
        });
    }

    /** A licence for a sale made outside checkout — bank transfer, contract, purchase order. */
    public function issueOffline(StandaloneProduct $product, string $name, string $email, string $domain, string $reference): StandaloneLicence
    {
        if (! $this->signingReady()) {
            throw new \DomainException('Configure release signing before issuing a licence.');
        }

        $licence = StandaloneLicence::create([
            'product_id' => $product->id, 'customer_name' => trim($name),
            'customer_email' => mb_strtolower(trim($email)),
            'domains' => [mb_strtolower(trim($domain))], 'limits' => $product->limits,
            'updates_until' => now()->addMonthsNoOverflow($product->updates_months)->toDateString(),
            'status' => 'active', 'source' => 'offline', 'reference' => trim($reference),
        ]);
        $this->sendLink($licence, 'issued');

        return $licence;
    }

    /** Another period of updates, added to whatever is left of this one. */
    public function extend(StandaloneLicence $licence, int $months): StandaloneLicence
    {
        $licence->update(['updates_until' => $this->extended($licence, $months), 'status' => 'active']);

        return $licence->refresh();
    }

    /**
     * Move a licence to another address. A buyer may do it once a month; a
     * super admin at any time. The licence file must be downloaded again.
     */
    public function changeDomain(StandaloneLicence $licence, string $domain, bool $byStaff = false): StandaloneLicence
    {
        $domain = mb_strtolower(trim($domain));
        if (! preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new \DomainException('Enter a domain like hospital.example.com, without https:// or a path.');
        }
        if (! $byStaff && ! $licence->canChangeDomain()) {
            throw new \DomainException('The address was changed recently. It can change again after '
                .$licence->domain_changed_at?->copy()->addDays(StandaloneLicence::DOMAIN_CHANGE_DAYS)->format('d M Y').', or ask us.');
        }

        $licence->update(['domains' => [$domain], 'domain_changed_at' => now()]);

        return $licence->refresh();
    }

    public function sendLink(StandaloneLicence $licence, string $reason = 'link'): void
    {
        try {
            Notification::route('mail', $licence->customer_email)->notify(new StandaloneDownloadReady($licence->id, $reason));
        } catch (\Throwable $exception) {
            Log::error('Standalone download email could not be sent', ['licence_id' => $licence->id, 'error' => get_class($exception)]);
        }
    }

    /** @return array<string,mixed> */
    public function document(StandaloneLicence $licence): array
    {
        $payload = [
            'format' => 1, 'product' => 'standalone-hospital', 'id' => $licence->id,
            'customer' => $licence->customer_name, 'domains' => $licence->domains,
            'updates_until' => $licence->updates_until->toDateString(), 'limits' => $licence->limits ?? [],
        ];

        return ['licence' => $payload] + $this->signer->sign($payload);
    }

    /** @return array{0:StandaloneOrder,1:GatewayCharge} */
    private function charge(StandaloneOrder $order, string $description): array
    {
        $charge = $this->gateway->initialize([
            'tx_ref' => $order->reference, 'amount' => $order->amount_ugx,
            'currency' => 'UGX', 'redirect_url' => route('standalone.checkout.callback'),
            'customer' => ['name' => $order->customer_name, 'email' => $order->customer_email, 'phone' => null],
            'meta' => ['description' => $description],
        ]);
        if (! $charge->ok) {
            $order->update(['status' => 'failed']);
        } elseif ($charge->providerRef !== null) {
            // Kept now, so the buyer's status page can ask the gateway about it
            // even if they never come back through the callback.
            $order->update(['gateway_tracking_id' => $charge->providerRef]);
        }

        return [$order, $charge];
    }

    private function extended(StandaloneLicence $licence, int $months): string
    {
        $from = $licence->updates_until->isFuture() ? $licence->updates_until->copy() : now();

        return $from->addMonthsNoOverflow($months)->toDateString();
    }

    private function reference(): string
    {
        return 'TDL-'.bin2hex(random_bytes(15));
    }
}
