<?php

namespace App\Services;

use App\Enums\BedStatus;
use App\Models\Bed;
use App\Models\Ward;
use App\Support\PlanLimit;

/**
 * The rules around the physical structure of a hospital that used to live
 * inside the Wards and Beds pages, so the web and the API refuse the same
 * things for the same reasons.
 */
class HospitalStructureService
{
    public function __construct(private readonly PlanLimit $planLimit) {}

    /**
     * The subscription plan caps how many beds a hospital may own.
     *
     * @throws \App\Exceptions\PlanLimitExceededException
     */
    public function assertBedCreatable(): void
    {
        $this->planLimit->assertCanCreate('beds');
    }

    /**
     * Never let an edit flip an occupied bed's status by hand.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function bedAttributes(?Bed $bed, array $data): array
    {
        if ($bed !== null && $bed->status === BedStatus::Occupied) {
            unset($data['status']);
        }

        return $data;
    }

    /** @throws \DomainException */
    public function assertBedDeletable(Bed $bed): void
    {
        if ($bed->status === BedStatus::Occupied) {
            throw new \DomainException('An occupied bed cannot be deleted.');
        }
    }

    /** A ward that still holds beds may not be removed. @throws \DomainException */
    public function assertWardDeletable(Ward $ward): void
    {
        if ($ward->beds()->exists()) {
            throw new \DomainException('This ward has beds — remove them first.');
        }
    }
}
