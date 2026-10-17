<?php

namespace App\Http\Controllers;

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use App\Services\Messaging\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * What the SMS gateway (Africa's Talking) tells us after a send.
 *
 * Africa's Talking does not sign its callbacks, so each URL carries a secret
 * (`messaging.sms.webhook_token`, compared in constant time) that only our
 * gateway account knows; without it, or with the wrong one, nothing is read.
 *
 *  - delivery: a message reached the phone, or did not.
 *  - inbound: somebody replied. "STOP" (and its usual cousins) stops text
 *    messages to that number at once, in every clinic.
 */
class SmsWebhookController extends Controller
{
    private const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT'];

    public function delivery(Request $request, string $token): Response
    {
        $this->assertToken($token);

        $id = (string) $request->input('id', '');
        $status = strtolower((string) $request->input('status', ''));
        if ($id === '') {
            return response('', 200);
        }

        $message = PatientMessage::query()->withoutGlobalScopes()->where('provider_id', $id)->first();
        if ($message === null || in_array($message->status, [MessageStatus::Delivered, MessageStatus::Skipped], true)) {
            return response('', 200);
        }

        if ($status === 'success') {
            $message->forceFill(['status' => MessageStatus::Delivered, 'delivered_at' => Carbon::now(), 'error' => null])->save();
        } elseif (in_array($status, ['failed', 'rejected', 'absentsubscriber', 'expired'], true)) {
            $reason = (string) $request->input('failureReason', '');
            $message->forceFill(['status' => MessageStatus::Failed, 'error' => 'Not delivered'.($reason !== '' ? ': '.$reason : '').'.'])->save();
        }

        return response('', 200);
    }

    public function inbound(Request $request, string $token, ConsentService $consents): Response
    {
        $this->assertToken($token);

        $word = strtoupper(trim((string) preg_replace('/[^A-Za-z]/', '', (string) $request->input('text', ''))));
        $from = (string) $request->input('from', '');

        if ($from !== '' && in_array($word, self::STOP_WORDS, true)) {
            $consents->optOutPhone($from, 'reply');
        }

        return response('', 200);
    }

    private function assertToken(string $token): void
    {
        $expected = (string) config('messaging.sms.webhook_token');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    }
}
