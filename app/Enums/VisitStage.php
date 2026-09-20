<?php

namespace App\Enums;

/**
 * What is being done to this visit right now — see docs/visits.md.
 *
 * Nobody picks one of these off a list. Each has a GATE that the system
 * evaluates, and the control to move only appears once its gate is open:
 *
 *     Ongoing ── every order finished ──▶ Billing
 *     Billing ── an invoice exists    ──▶ Payment
 *     Payment ── balance is zero      ──▶ Completed   (by itself)
 *
 * Where the patient physically is — the triage bench, the doctor's room — is
 * not here. That is answered by their orders, which say who is doing what and
 * where it has got to; keeping a second copy in the visit's own state meant
 * the two could disagree.
 */
enum VisitStage: string
{
    case Ongoing = 'ongoing';
    case Billing = 'billing';
    case Payment = 'payment';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Ongoing => 'Ongoing',
            self::Billing => 'Billing',
            self::Payment => 'Payment',
            self::Completed => 'Completed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Ongoing => 'badge-info',
            self::Billing, self::Payment => 'badge-warn',
            self::Completed => 'badge-success',
        };
    }

    /** How far along the run this stage is, so a move can say which way it goes. */
    public function step(): int
    {
        return match ($this) {
            self::Ongoing => 1,
            self::Billing => 2,
            self::Payment => 3,
            self::Completed => 4,
        };
    }

    /** The one stage that follows this one, if any. There are never two. */
    public function next(): ?self
    {
        return match ($this) {
            self::Ongoing => self::Billing,
            self::Billing => self::Payment,
            self::Payment => self::Completed,
            self::Completed => null,
        };
    }

    /**
     * What the reader presses to get to the next one.
     *
     * The last move has no wording because nobody presses it — a visit
     * completes itself the moment the balance reaches zero.
     */
    public function advanceLabel(): ?string
    {
        return match ($this) {
            self::Ongoing => 'Ready for billing',
            self::Billing => 'Ready for payment',
            self::Payment, self::Completed => null,
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(self::cases(), fn ($c, $s) => $c + [$s->value => $s->label()], []);
    }
}
