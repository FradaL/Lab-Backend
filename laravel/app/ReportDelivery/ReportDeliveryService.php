<?php

namespace App\ReportDelivery;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalReports\PrivateReportStorage;
use App\ClinicalReports\ReportAuthority;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryReportIssuance;
use App\Models\ReportDelivery;
use App\Models\ReportDeliveryException;
use App\Models\ReportDeliveryExceptionAuthorization;
use App\Models\ReportDeliveryPreparation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReportDeliveryService
{
    private const array CHANNELS = ['printed_document', 'manual_digital_file'];

    private const array RECIPIENT_BASES = [
        'patient' => ['identity_verified'],
        'legal_representative' => ['legal_authority_verified'],
        'authorized_person' => ['explicit_patient_authorization'],
        'authorized_professional' => ['professional_referral_verified', 'explicit_patient_authorization'],
        'authorized_entity' => ['entity_authorization_verified', 'explicit_patient_authorization'],
    ];

    public function __construct(private ReportAuthority $authority, private ReportDeliveryPolicyService $policies,
        private ReportCommercialEvaluator $commercial, private PrivateReportStorage $storage, private AuditWriter $audit) {}

    public function eligibility(Laboratory $lab, User $actor, int $orderId, int $reportId): array
    {
        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId) {
            [$order,$report] = $this->context($lab, $orderId, $reportId, false);
            $this->authority->check($lab, $actor, 'reports.delivery.evaluate');
            $policy = $this->policies->snapshot($lab);
            $evaluation = $this->commercial->evaluate($lab, $order, $policy);
            $authorization = $this->activeAuthorization($lab, $orderId, $reportId, false, $policy, $evaluation);

            return ['laboratory_order_id' => $order->id, 'report_id' => $report->id, 'report_number' => $report->number,
                'policy' => $policy, 'evaluation' => $evaluation, 'eligible' => $evaluation['eligible'] || $authorization !== null,
                'eligibility_source' => $evaluation['eligible'] ? 'commercial_policy' : ($authorization === null ? null : 'commercial_exception'),
                'exception_authorization_id' => $authorization?->id];
        });
    }

    public function requestException(Laboratory $lab, User $actor, int $orderId, int $reportId, array $input): array
    {
        $this->validateReason($input, false);

        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $input) {
            [$order,$report] = $this->context($lab, $orderId, $reportId);
            $this->authority->check($lab, $actor, 'reports.delivery.prepare');
            $policy = $this->policies->snapshot($lab);
            $evaluation = $this->commercial->evaluate($lab, $order, $policy);
            if ($evaluation['eligible']) {
                throw new ClinicalConflict('report_delivery_exception_not_required', 'The current policy and commercial evidence already allow delivery.');
            }
            if (ReportDeliveryException::forLaboratory($lab)->where('report_id', $reportId)->whereIn('status', ['pending', 'authorized'])->exists()) {
                throw new ClinicalConflict('report_delivery_exception_active', 'This report already has an active commercial exception.');
            }
            $exception = ReportDeliveryException::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'report_id' => $reportId,
                'revision' => 1, 'status' => 'pending', 'reason' => trim($input['reason']), 'policy_revision' => $policy['revision'],
                'policy_mode' => $policy['mode'], 'evaluation_snapshot' => $evaluation['assessment'], 'requested_by' => $actor->id, 'requested_at' => now()]);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.delivery_exception_requested', 'report_delivery_exception', $exception->id,
                newValues: ['status' => 'pending', 'revision' => 1], metadata: ['laboratory_order_id' => $orderId, 'report_id' => $report->id, 'policy_revision' => $policy['revision'], 'policy_mode' => $policy['mode']]));

            return $this->exceptionData($exception);
        });
    }

    public function authorizeException(Laboratory $lab, User $actor, int $orderId, int $reportId, int $exceptionId, array $input): array
    {
        $this->revisionInput($input, 1);

        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $exceptionId, $input) {
            [$order] = $this->context($lab, $orderId, $reportId);
            $this->authority->check($lab, $actor, 'reports.delivery_exceptions.authorize');
            $exception = ReportDeliveryException::forLaboratory($lab)->where('laboratory_order_id', $orderId)->where('report_id', $reportId)->whereKey($exceptionId)->lockForUpdate()->firstOrFail();
            if ($exception->status !== 'pending' || $exception->revision !== $input['revision']) {
                throw new ClinicalConflict('report_delivery_exception_revision_conflict', 'Reload the commercial exception.');
            }
            $policy = $this->policies->snapshot($lab);
            $evaluation = $this->commercial->evaluate($lab, $order, $policy);
            if ($evaluation['eligible']) {
                throw new ClinicalConflict('report_delivery_exception_not_required', 'The current policy and commercial evidence now allow delivery.');
            }
            if ($policy['revision'] !== $exception->policy_revision || $policy['mode'] !== $exception->policy_mode || $evaluation['assessment']['fingerprint'] !== $exception->evaluation_snapshot['fingerprint']) {
                throw new ClinicalConflict('report_delivery_exception_obsolete', 'Policy or commercial evidence changed after the exception request.');
            }
            $authorization = ReportDeliveryExceptionAuthorization::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'report_id' => $reportId,
                'exception_id' => $exception->id, 'policy_revision' => $policy['revision'], 'policy_mode' => $policy['mode'],
                'evaluation_snapshot' => $evaluation['assessment'], 'authorized_by' => $actor->id, 'authorized_at' => now()]);
            $exception->update(['revision' => 2, 'status' => 'authorized']);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.delivery_exception_authorized', 'report_delivery_exception_authorization', $authorization->id,
                newValues: ['exception_id' => $exception->id, 'status' => 'authorized'], metadata: ['laboratory_order_id' => $orderId, 'report_id' => $reportId, 'policy_revision' => $policy['revision']]));

            return ['exception' => $this->exceptionData($exception->fresh()), 'authorization' => $this->authorizationData($authorization)];
        });
    }

    public function revokeException(Laboratory $lab, User $actor, int $orderId, int $reportId, int $exceptionId, array $input): array
    {
        $this->validateReason($input, true);

        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $exceptionId, $input) {
            $this->context($lab, $orderId, $reportId);
            $this->authority->check($lab, $actor, 'reports.delivery_exceptions.authorize');
            $exception = ReportDeliveryException::forLaboratory($lab)->where('laboratory_order_id', $orderId)->where('report_id', $reportId)->whereKey($exceptionId)->lockForUpdate()->firstOrFail();
            if ($exception->status !== 'authorized' || $exception->revision !== $input['revision']) {
                throw new ClinicalConflict('report_delivery_exception_revision_conflict', 'Only an unused current authorization can be revoked.');
            }
            $authorization = ReportDeliveryExceptionAuthorization::forLaboratory($lab)->where('exception_id', $exception->id)->firstOrFail();
            if (ReportDelivery::forLaboratory($lab)->where('exception_authorization_id', $authorization->id)->exists()) {
                throw new ClinicalConflict('report_delivery_exception_consumed', 'A commercial authorization used by a delivery cannot be revoked.');
            }
            DB::table('report_delivery_exception_revocations')->insert(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'report_id' => $reportId,
                'exception_id' => $exception->id, 'authorization_id' => $authorization->id, 'reason' => trim($input['reason']), 'revoked_by' => $actor->id, 'revoked_at' => now()]);
            $exception->update(['revision' => 3, 'status' => 'revoked']);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.delivery_exception_revoked', 'report_delivery_exception', $exception->id,
                oldValues: ['status' => 'authorized'], newValues: ['status' => 'revoked'], metadata: ['laboratory_order_id' => $orderId, 'report_id' => $reportId, 'reason' => trim($input['reason'])]));

            return $this->exceptionData($exception->fresh());
        });
    }

    public function exceptions(Laboratory $lab, User $actor, int $orderId, int $reportId): array
    {
        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId) {
            $this->context($lab, $orderId, $reportId, false);
            $this->authority->check($lab, $actor, 'reports.delivery_exceptions.view');
            $page = ReportDeliveryException::forLaboratory($lab)->where('laboratory_order_id', $orderId)->where('report_id', $reportId)->orderByDesc('id')->paginate(50);

            return ['data' => $page->map(fn ($exception) => $this->exceptionData($exception))->all(), 'meta' => $this->page($page)];
        });
    }

    public function prepare(Laboratory $lab, User $actor, int $orderId, int $reportId, array $input, string $key): ReportDeliveryPreparation
    {
        $this->validatePreparation($input, $key);
        $keyHash = hash('sha256', 'prepare-report-delivery:'.$key);
        $fingerprint = $this->fingerprint($orderId, $reportId, $input);

        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $input, $keyHash, $fingerprint) {
            [$order,$report] = $this->context($lab, $orderId, $reportId);
            $this->authority->check($lab, $actor, 'reports.delivery.prepare');
            $existing = ReportDeliveryPreparation::forLaboratory($lab)->where('idempotency_hash', $keyHash)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $fingerprint)) {
                    throw new ClinicalConflict('report_delivery_idempotency_conflict', 'This key identifies another delivery preparation.');
                }

                return $existing;
            }
            $policy = $this->policies->snapshot($lab);
            $evaluation = $this->commercial->evaluate($lab, $order, $policy);
            $authorization = $evaluation['eligible'] ? null : $this->requiredAuthorization($lab, $orderId, $reportId, $input['exception_authorization_id'] ?? null, $policy, $evaluation);
            if ($evaluation['eligible'] && array_key_exists('exception_authorization_id', $input)) {
                throw ValidationException::withMessages(['exception_authorization_id' => ['An exception cannot be attached when commercial policy is already satisfied.']]);
            }
            $recipient = $this->recipient($input, $report, $actor);
            $preparedAt = now();
            $preparation = ReportDeliveryPreparation::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'report_id' => $reportId,
                'idempotency_hash' => $keyHash, 'request_hash' => $fingerprint, 'channel' => $input['channel'], 'recipient_snapshot' => $recipient,
                'policy_revision' => $policy['revision'], 'policy_mode' => $policy['mode'], 'evaluation_snapshot' => $evaluation['assessment'],
                'exception_authorization_id' => $authorization?->id, 'prepared_by' => $actor->id, 'prepared_at' => $preparedAt, 'expires_at' => $preparedAt->copy()->addMinutes(15)]);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.delivery_prepared', 'report_delivery_preparation', $preparation->id,
                newValues: ['channel' => $preparation->channel, 'expires_at' => $preparation->expires_at], metadata: ['laboratory_order_id' => $orderId, 'report_id' => $reportId, 'policy_revision' => $policy['revision'], 'exception_authorization_id' => $authorization?->id]));

            return $preparation;
        });
    }

    public function downloadPreparation(Laboratory $lab, User $actor, int $orderId, int $reportId, int $preparationId): array
    {
        [$report,$preparation] = DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $preparationId) {
            [$order,$report] = $this->context($lab, $orderId, $reportId);
            $this->authority->check($lab, $actor, 'reports.delivery.prepare');
            $preparation = $this->preparation($lab, $order, $reportId, $preparationId);
            $this->assertPreparationCurrent($lab, $order, $preparation, false);

            return [$report, $preparation];
        });
        try {
            $bytes = $this->storage->read($report->private_path, $report->pdf_hash);
        } catch (\Throwable) {
            throw new ClinicalConflict('report_storage_unavailable', 'The private document is unavailable or failed its integrity check.', status: 503);
        }
        $preparation = DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $preparationId) {
            [$order] = $this->context($lab, $orderId, $reportId);
            $this->authority->check($lab, $actor, 'reports.delivery.prepare');
            $preparation = $this->preparation($lab, $order, $reportId, $preparationId);
            $this->assertPreparationCurrent($lab, $order, $preparation, false);
            $preparation->update(['downloaded_by' => $actor->id, 'downloaded_at' => now()]);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.delivery_copy_downloaded', 'report_delivery_preparation', $preparation->id,
                newValues: ['downloaded_at' => $preparation->downloaded_at], metadata: ['laboratory_order_id' => $orderId, 'report_id' => $reportId, 'channel' => $preparation->channel]));

            return $preparation->fresh();
        });

        return [$report, $preparation, $bytes];
    }

    public function deliver(Laboratory $lab, User $actor, int $orderId, int $reportId, array $input, string $key): ReportDelivery
    {
        $this->validateDelivery($input, $key);
        $keyHash = hash('sha256', 'confirm-report-delivery:'.$key);
        $fingerprint = $this->fingerprint($orderId, $reportId, $input);

        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $input, $keyHash, $fingerprint) {
            [$order] = $this->context($lab, $orderId, $reportId);
            $this->authority->check($lab, $actor, 'reports.delivery.confirm');
            $existing = ReportDelivery::forLaboratory($lab)->where('idempotency_hash', $keyHash)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $fingerprint)) {
                    throw new ClinicalConflict('report_delivery_idempotency_conflict', 'This key identifies another confirmed delivery.');
                }

                return $existing;
            }
            $preparation = $this->preparation($lab, $order, $reportId, $input['preparation_id']);
            $this->assertPreparationCurrent($lab, $order, $preparation, true);
            $delivery = ReportDelivery::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'report_id' => $reportId,
                'preparation_id' => $preparation->id, 'idempotency_hash' => $keyHash, 'request_hash' => $fingerprint,
                'channel' => $preparation->channel, 'recipient_snapshot' => $preparation->recipient_snapshot, 'policy_revision' => $preparation->policy_revision,
                'policy_mode' => $preparation->policy_mode, 'evaluation_snapshot' => $preparation->evaluation_snapshot,
                'exception_authorization_id' => $preparation->exception_authorization_id, 'notes' => isset($input['notes']) ? trim($input['notes']) : null,
                'delivered_by' => $actor->id, 'delivered_at' => now()]);
            if ($preparation->exception_authorization_id !== null) {
                $exceptionId = ReportDeliveryExceptionAuthorization::forLaboratory($lab)->whereKey($preparation->exception_authorization_id)->value('exception_id');
                ReportDeliveryException::forLaboratory($lab)->whereKey($exceptionId)->where('status', 'authorized')->update(['revision' => 3, 'status' => 'consumed']);
            }
            $this->audit->record($lab, $actor, new AuditEvent('order_report.delivered', 'report_delivery', $delivery->id,
                newValues: ['channel' => $delivery->channel, 'delivered_at' => $delivery->delivered_at], metadata: ['laboratory_order_id' => $orderId, 'report_id' => $reportId, 'preparation_id' => $preparation->id, 'policy_revision' => $delivery->policy_revision, 'exception_authorization_id' => $delivery->exception_authorization_id]));

            return $delivery;
        });
    }

    public function deliveries(Laboratory $lab, User $actor, int $orderId, int $reportId): array
    {
        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId) {
            $this->context($lab, $orderId, $reportId, false);
            $this->authority->check($lab, $actor, 'reports.delivery_history.view');
            $page = ReportDelivery::forLaboratory($lab)->where('laboratory_order_id', $orderId)->where('report_id', $reportId)->orderByDesc('id')->paginate(50);

            return ['data' => $page->map(fn ($delivery) => $this->deliveryData($delivery))->all(), 'meta' => $this->page($page)];
        });
    }

    public function preparationData(ReportDeliveryPreparation $preparation): array
    {
        return ['id' => $preparation->id, 'laboratory_order_id' => $preparation->laboratory_order_id, 'report_id' => $preparation->report_id,
            'channel' => $preparation->channel, 'recipient' => $preparation->recipient_snapshot, 'policy_revision' => $preparation->policy_revision,
            'policy_mode' => $preparation->policy_mode, 'evaluation' => $preparation->evaluation_snapshot,
            'exception_authorization_id' => $preparation->exception_authorization_id, 'prepared_by' => $preparation->prepared_by,
            'prepared_at' => $preparation->prepared_at->toISOString(), 'expires_at' => $preparation->expires_at->toISOString(),
            'downloaded_by' => $preparation->downloaded_by, 'downloaded_at' => $preparation->downloaded_at?->toISOString()];
    }

    public function deliveryData(ReportDelivery $delivery): array
    {
        return ['id' => $delivery->id, 'laboratory_order_id' => $delivery->laboratory_order_id, 'report_id' => $delivery->report_id,
            'preparation_id' => $delivery->preparation_id, 'channel' => $delivery->channel, 'recipient' => $delivery->recipient_snapshot,
            'policy_revision' => $delivery->policy_revision, 'policy_mode' => $delivery->policy_mode, 'evaluation' => $delivery->evaluation_snapshot,
            'exception_authorization_id' => $delivery->exception_authorization_id, 'notes' => $delivery->notes,
            'delivered_by' => $delivery->delivered_by, 'delivered_at' => $delivery->delivered_at->toISOString()];
    }

    private function context(Laboratory $lab, int $orderId, int $reportId, bool $write = true): array
    {
        $orderQuery = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId);
        $order = ($write ? $orderQuery->lockForUpdate() : $orderQuery->sharedLock())->firstOrFail();
        $reportQuery = LaboratoryReportIssuance::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($reportId);
        $report = ($write ? $reportQuery->lockForUpdate() : $reportQuery->sharedLock())->firstOrFail();

        return [$order, $report];
    }

    private function activeAuthorization(Laboratory $lab, int $orderId, int $reportId, bool $write, array $policy, array $evaluation): ?ReportDeliveryExceptionAuthorization
    {
        $query = ReportDeliveryExceptionAuthorization::forLaboratory($lab)->where('laboratory_order_id', $orderId)->where('report_id', $reportId)
            ->where('policy_revision', $policy['revision'])
            ->where('policy_mode', $policy['mode'])
            ->where('evaluation_snapshot->fingerprint', $evaluation['assessment']['fingerprint'])
            ->whereHas('unusedAuthorizedException');

        return ($write ? $query->lockForUpdate() : $query->sharedLock())->first();
    }

    private function requiredAuthorization(Laboratory $lab, int $orderId, int $reportId, mixed $authorizationId, array $policy, array $evaluation): ReportDeliveryExceptionAuthorization
    {
        if (! is_int($authorizationId) || $authorizationId < 1) {
            throw new ClinicalConflict('report_delivery_not_eligible', 'Commercial policy is not satisfied and an exact authorized exception is required.');
        }
        $authorization = ReportDeliveryExceptionAuthorization::forLaboratory($lab)->where('laboratory_order_id', $orderId)->where('report_id', $reportId)->whereKey($authorizationId)->lockForUpdate()->firstOrFail();
        $exception = ReportDeliveryException::forLaboratory($lab)->whereKey($authorization->exception_id)->lockForUpdate()->firstOrFail();
        if ($exception->status !== 'authorized' || $authorization->policy_revision !== $policy['revision'] || $authorization->policy_mode !== $policy['mode'] || $authorization->evaluation_snapshot['fingerprint'] !== $evaluation['assessment']['fingerprint']) {
            throw new ClinicalConflict('report_delivery_exception_unavailable', 'The commercial exception is obsolete, revoked or already consumed.');
        }

        return $authorization;
    }

    private function preparation(Laboratory $lab, LaboratoryOrder $order, int $reportId, int $preparationId): ReportDeliveryPreparation
    {
        return ReportDeliveryPreparation::forLaboratory($lab)->where('laboratory_order_id', $order->id)->where('report_id', $reportId)->whereKey($preparationId)->lockForUpdate()->firstOrFail();
    }

    private function assertPreparationCurrent(Laboratory $lab, LaboratoryOrder $order, ReportDeliveryPreparation $preparation, bool $requireDownloaded): void
    {
        if ($preparation->expires_at->isPast()) {
            throw new ClinicalConflict('report_delivery_preparation_expired', 'Create a new delivery preparation.');
        }
        if (($preparation->downloaded_at !== null) !== $requireDownloaded) {
            throw new ClinicalConflict($requireDownloaded ? 'report_delivery_copy_required' : 'report_delivery_preparation_consumed', $requireDownloaded ? 'Download the prepared copy before confirming delivery.' : 'This prepared copy was already downloaded.');
        }
        if (ReportDelivery::forLaboratory($lab)->where('preparation_id', $preparation->id)->exists()) {
            throw new ClinicalConflict('report_delivery_preparation_consumed', 'This preparation already belongs to a confirmed delivery.');
        }
        $policy = $this->policies->snapshot($lab);
        $evaluation = $this->commercial->evaluate($lab, $order, $policy);
        if ($policy['revision'] !== $preparation->policy_revision || $policy['mode'] !== $preparation->policy_mode || $evaluation['assessment']['fingerprint'] !== $preparation->evaluation_snapshot['fingerprint']) {
            throw new ClinicalConflict('report_delivery_evidence_changed', 'Policy or commercial evidence changed after preparation.');
        }
        if (! $evaluation['eligible']) {
            $this->requiredAuthorization($lab, $order->id, $preparation->report_id, $preparation->exception_authorization_id, $policy, $evaluation);
        }
    }

    private function recipient(array $input, LaboratoryReportIssuance $report, User $actor): array
    {
        $type = $input['recipient_type'];
        $name = $type === 'patient'
            ? trim($report->identity_snapshot['patient']['first_names'].' '.$report->identity_snapshot['patient']['last_names'])
            : trim((string) ($input['recipient_name'] ?? ''));
        if ($type === 'patient' && array_key_exists('recipient_name', $input)) {
            throw ValidationException::withMessages(['recipient_name' => ['Patient identity comes from the immutable report snapshot.']]);
        }
        if ($name === '') {
            throw ValidationException::withMessages(['recipient_name' => ['Recipient name is required for this recipient type.']]);
        }
        if (! in_array($input['authorization_basis'], self::RECIPIENT_BASES[$type], true)) {
            throw ValidationException::withMessages(['authorization_basis' => ['Authorization basis is incompatible with recipient type.']]);
        }

        return ['type' => $type, 'name' => $name, 'authorization_basis' => $input['authorization_basis'],
            'authorization_evidence' => trim($input['authorization_evidence']), 'verified_by' => $actor->id, 'verified_at' => now()->toISOString()];
    }

    private function validatePreparation(array $input, string $key): void
    {
        $allowed = ['channel', 'recipient_type', 'recipient_name', 'authorization_basis', 'authorization_evidence', 'authorization_confirmed', 'exception_authorization_id'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['input' => ['Unknown delivery preparation fields.']]);
        }
        Validator::make($input, ['channel' => ['required', 'in:'.implode(',', self::CHANNELS)], 'recipient_type' => ['required', 'in:'.implode(',', array_keys(self::RECIPIENT_BASES))],
            'recipient_name' => ['sometimes', 'string', 'max:150'], 'authorization_basis' => ['required', 'string', 'max:60'],
            'authorization_evidence' => ['required', 'string', 'max:500', fn ($a, $v, $f) => trim($v) !== '' ?: $f('Explicit authorization evidence is required.')],
            'authorization_confirmed' => ['required', fn ($a, $v, $f) => $v === true ?: $f('Explicit JSON true confirmation is required.')],
            'exception_authorization_id' => ['sometimes', fn ($a, $v, $f) => is_int($v) && $v > 0 ?: $f('A positive JSON integer is required.')]])->validate();
        $this->key($key);
    }

    private function validateDelivery(array $input, string $key): void
    {
        if (array_diff(array_keys($input), ['preparation_id', 'delivery_confirmed', 'notes']) !== []) {
            throw ValidationException::withMessages(['input' => ['Unknown delivery confirmation fields.']]);
        }
        Validator::make($input, ['preparation_id' => ['required', fn ($a, $v, $f) => is_int($v) && $v > 0 ?: $f('A positive JSON integer is required.')],
            'delivery_confirmed' => ['required', fn ($a, $v, $f) => $v === true ?: $f('Explicit JSON true confirmation is required.')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000']])->validate();
        $this->key($key);
    }

    private function validateReason(array $input, bool $revision): void
    {
        $allowed = $revision ? ['revision', 'reason'] : ['reason'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['input' => ['Unknown commercial exception fields.']]);
        }
        $rules = ['reason' => ['required', 'string', 'max:1000', fn ($a, $v, $f) => trim($v) !== '' ?: $f('A nonempty reason is required.')]];
        if ($revision) {
            $rules['revision'] = ['required', fn ($a, $v, $f) => is_int($v) && $v === 2 ?: $f('Current JSON integer revision 2 is required.')];
        }
        Validator::make($input, $rules)->validate();
    }

    private function revisionInput(array $input, int $expected): void
    {
        if (array_keys($input) !== ['revision']) {
            throw ValidationException::withMessages(['input' => ['Only revision is accepted.']]);
        }
        Validator::make($input, ['revision' => ['required', fn ($a, $v, $f) => is_int($v) && $v === $expected ?: $f("Current JSON integer revision {$expected} is required.")]])->validate();
    }

    private function key(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9_.-]{16,100}\z/', $key) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A 16–100 character opaque idempotency key is required.']]);
        }
    }

    private function fingerprint(int $orderId, int $reportId, array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode(['order_id' => $orderId, 'report_id' => $reportId, 'input' => $input], JSON_THROW_ON_ERROR));
    }

    private function exceptionData(ReportDeliveryException $exception): array
    {
        return ['id' => $exception->id, 'laboratory_order_id' => $exception->laboratory_order_id, 'report_id' => $exception->report_id,
            'revision' => $exception->revision, 'status' => $exception->status, 'reason' => $exception->reason,
            'policy_revision' => $exception->policy_revision, 'policy_mode' => $exception->policy_mode, 'evaluation' => $exception->evaluation_snapshot,
            'requested_by' => $exception->requested_by, 'requested_at' => $exception->requested_at->toISOString()];
    }

    private function authorizationData(ReportDeliveryExceptionAuthorization $authorization): array
    {
        return ['id' => $authorization->id, 'exception_id' => $authorization->exception_id, 'policy_revision' => $authorization->policy_revision,
            'policy_mode' => $authorization->policy_mode, 'evaluation' => $authorization->evaluation_snapshot,
            'authorized_by' => $authorization->authorized_by, 'authorized_at' => $authorization->authorized_at->toISOString()];
    }

    private function page($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()];
    }
}
