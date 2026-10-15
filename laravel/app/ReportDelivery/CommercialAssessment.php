<?php

namespace App\ReportDelivery;

use InvalidArgumentException;

final readonly class CommercialAssessment
{
    public const array STATUSES = ['satisfied', 'not_satisfied', 'unavailable'];

    public const array EVIDENCE_TYPES = ['full_payment', 'authorized_credit', 'none'];

    public function __construct(
        public string $status,
        public string $evidenceType,
        public string $provider,
        public ?string $evidenceReference,
        public ?string $evidenceRevision,
    ) {
        if (! in_array($status, self::STATUSES, true) || ! in_array($evidenceType, self::EVIDENCE_TYPES, true)) {
            throw new InvalidArgumentException('Invalid commercial assessment.');
        }
        if ($provider === '' || ($status !== 'satisfied' && $evidenceType !== 'none')) {
            throw new InvalidArgumentException('Commercial evidence must be explicit and consistent.');
        }
    }

    public function snapshot(): array
    {
        $evidence = ['status' => $this->status, 'evidence_type' => $this->evidenceType, 'provider' => $this->provider,
            'evidence_reference' => $this->evidenceReference, 'evidence_revision' => $this->evidenceRevision];

        return $evidence + ['fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)), 'checked_at' => now()->toISOString()];
    }
}
