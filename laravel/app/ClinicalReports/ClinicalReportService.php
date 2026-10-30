<?php

namespace App\ClinicalReports;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\LaboratoryReportIssuance;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ClinicalReportService
{
    private const METADATA_COLUMNS = ['id', 'laboratory_order_id', 'number', 'sequence', 'type', 'issued_by', 'issued_at', 'clinical_revision', 'finalization_id', 'pdf_hash', 'pdf_size', 'format_version'];

    private function metadataQuery(Laboratory $lab)
    {
        return LaboratoryReportIssuance::forLaboratory($lab)->select(self::METADATA_COLUMNS)
            ->selectRaw("jsonb_array_length(clinical_snapshot) AS line_count, identity_snapshot->>'source' AS identity_source, jsonb_array_length(pending_snapshot) AS pending_line_count");
    }

    public function __construct(private ReportAuthority $authority, private ReportSettingsService $settings,
        private ClinicalReportRenderer $renderer, private PrivateReportStorage $storage, private AuditWriter $audit) {}

    public function issue(Laboratory $lab, User $actor, int $orderId, array $input, string $key): LaboratoryReportIssuance
    {
        $this->input($input, $key);
        if (DB::transactionLevel() !== 0 || DB::getDriverName() !== 'pgsql') {
            throw new LogicException('Report issuance requires PostgreSQL and owns its transaction boundary.');
        }
        $keyHash = hash('sha256', 'issue-report:'.$key);
        if (isset($input['line_ids'])) {
            sort($input['line_ids'], SORT_NUMERIC);
        }
        ksort($input);
        $fingerprint = hash('sha256', json_encode(['order_id' => $orderId, 'input' => $input], JSON_THROW_ON_ERROR));
        // Session advisory mutex only for this idempotency operation. No row transaction spans rendering.
        $mutex = (int) hexdec(substr(hash('sha256', $lab->id.':'.$keyHash), 0, 15));
        DB::select('SELECT pg_advisory_lock(?)', [$mutex]);
        $path = null;
        try {
            $prepared = DB::transaction(function () use ($lab, $actor, $orderId, $input, $keyHash, $fingerprint) {
                $order = $this->order($lab, $orderId);
                $this->authority->check($lab, $actor, 'reports.issue');
                $existing = LaboratoryReportIssuance::forLaboratory($lab)->where('idempotency_hash', $keyHash)->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $fingerprint)) {
                        throw new ClinicalConflict('report_idempotency_conflict', 'This key already identifies a different issuance request.');
                    }
                    $this->storage->read($existing->private_path, $existing->pdf_hash);

                    return $existing;
                }
                $lines = $this->evidence($order, $input);
                $identity = $order->report_identity_snapshot;
                if ($identity === null) {
                    if (($input['identity_confirmation'] ?? false) !== true) {
                        throw new ClinicalConflict('report_identity_confirmation_required', 'Explicitly confirm the current patient and branch identity for this older order.');
                    }
                    $patient = Patient::forLaboratory($lab)->whereKey($order->patient_id)->sharedLock()->firstOrFail();
                    $branch = Branch::where('laboratory_id', $lab->id)->whereKey($order->branch_id)->sharedLock()->firstOrFail();
                    $identity = ReportIdentity::snapshot($patient, $branch, 'confirmed_at_issuance', $actor->id);
                }
                [$settingsRevision,$presentation] = $this->settings->snapshot($lab->fresh());
                $pendingLines = $this->pendingEvidence($order, $input, $lines);
                if (strlen(json_encode([$lines, $pendingLines], JSON_THROW_ON_ERROR)) > 8388608) {
                    throw new ClinicalConflict('report_content_limit', 'The report exceeds the technical snapshot size limit.');
                }

                return ['number' => 'REP-'.Str::ulid(), 'type' => $input['type'], 'issued_at' => now()->toISOString(),
                    'order_code' => $order->code, 'ordered_at' => $order->ordered_at->toISOString(), 'identity' => $identity,
                    'format_version' => ($lines[0]['snapshot_version'] ?? 1) === 2 ? 'donqer-clinical-2' : 'donqer-clinical-1',
                    'pending_lines' => $pendingLines,
                    'presentation' => $presentation, 'settings_revision' => $settingsRevision, 'lines' => $lines,
                    'finalization_id' => $order->clinicalFinalization?->id, 'clinical_revision' => $order->clinical_revision];
            });
            if ($prepared instanceof LaboratoryReportIssuance) {
                return $prepared;
            }
            $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $url = rtrim(config('app.url'), '/').'/api/v1/report-verifications/'.$secret;
            try {
                $pdf = $this->renderer->render($prepared, $url);
            } catch (ClinicalConflict $conflict) {
                throw new ClinicalConflict($conflict->errorCode, $conflict->getMessage(), $conflict->errors, $conflict->status);
            } catch (\Throwable) {
                throw new ClinicalConflict('report_generation_unavailable', 'Document generation is temporarily unavailable.', status: 503);
            }
            $hash = hash('sha256', $pdf);
            $path = $this->storage->write($lab->id, $pdf);
            $this->storage->read($path, $hash);

            return DB::transaction(function () use ($lab, $actor, $orderId, $input, $prepared, $path, $hash, $pdf, $secret, $keyHash, $fingerprint) {
                $order = $this->order($lab, $orderId);
                $this->authority->check($lab, $actor, 'reports.issue');
                if ($order->report_identity_snapshot === null) {
                    $currentIdentity = ReportIdentity::snapshot(
                        Patient::forLaboratory($lab)->whereKey($order->patient_id)->sharedLock()->firstOrFail(),
                        Branch::where('laboratory_id', $lab->id)->whereKey($order->branch_id)->sharedLock()->firstOrFail(),
                        'confirmed_at_issuance', $actor->id);
                    if ($currentIdentity['patient'] !== $prepared['identity']['patient'] || $currentIdentity['branch'] !== $prepared['identity']['branch']) {
                        throw new ClinicalConflict('report_identity_conflict', 'The confirmed current identity changed during rendering.');
                    }
                }
                // Never attach bytes prepared for an earlier clinical composition to a later state.
                $lines = $this->evidence($order, $input);
                if ($lines !== $prepared['lines'] || $this->pendingEvidence($order, $input, $lines) !== $prepared['pending_lines']) {
                    throw new ClinicalConflict('report_evidence_conflict', 'Historical evidence changed during rendering.');
                }
                $sequence = LaboratoryReportIssuance::forLaboratory($lab)->where('laboratory_order_id', $orderId)->count() + 1;
                $report = LaboratoryReportIssuance::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId,
                    'number' => $prepared['number'], 'sequence' => $sequence, 'type' => $prepared['type'],
                    'clinical_revision' => $order->clinical_revision, 'finalization_id' => $prepared['finalization_id'],
                    'issued_by' => $actor->id, 'issued_at' => $prepared['issued_at'], 'idempotency_hash' => $keyHash,
                    'request_hash' => $fingerprint, 'verification_hash' => hash('sha256', $secret),
                    'identity_snapshot' => $prepared['identity'] + ['order_code' => $prepared['order_code'], 'ordered_at' => $prepared['ordered_at']],
                    'clinical_snapshot' => $prepared['lines'], 'presentation_snapshot' => ['revision' => $prepared['settings_revision']] + $prepared['presentation'],
                    'private_path' => $path, 'pdf_hash' => $hash, 'pdf_size' => strlen($pdf), 'format_version' => $prepared['format_version']] + ($prepared['format_version'] === 'donqer-clinical-2' ? ['pending_snapshot' => $prepared['pending_lines']] : []));
                foreach ($lines as $line) {
                    DB::table('laboratory_report_issuance_lines')->insert(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId,
                        'report_id' => $report->id, 'laboratory_order_exam_id' => $line['line_id'], 'resolution_id' => $line['resolution']['id'],
                        'snapshot' => json_encode($line, JSON_THROW_ON_ERROR)]);
                }
                $this->audit->record($lab, $actor, new AuditEvent('order_report.issued', 'laboratory_report_issuance', $report->id,
                    newValues: ['number' => $report->number, 'type' => $report->type, 'sequence' => $sequence, 'pdf_hash' => $hash],
                    metadata: ['laboratory_order_id' => $orderId, 'resolution_ids' => array_column(array_column($lines, 'resolution'), 'id'), 'identity_source' => $prepared['identity']['source']]));

                return $report;
            });
        } catch (\Throwable $e) {
            if ($path !== null) {
                try {
                    $this->storage->remove($path);
                } catch (\Throwable) {
                    Log::error('Private report cleanup requires operational reconciliation.');
                }
            }
            if ($e instanceof ClinicalConflict || $e instanceof ValidationException || $e instanceof AuthorizationException || $e instanceof HttpExceptionInterface || $e instanceof ModelNotFoundException) {
                throw $e;
            }
            Log::error('Report issuance failed.', ['error_type' => get_class($e)]);
            throw new ClinicalConflict('report_issuance_failed', 'Report issuance is temporarily unavailable.', status: 503);
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [$mutex]);
        }
    }

    public function history(Laboratory $lab, User $actor, int $orderId): array
    {
        return DB::transaction(function () use ($lab, $actor, $orderId) {
            $this->order($lab, $orderId, false);
            $this->authority->check($lab, $actor, 'reports.view_history');
            $page = $this->metadataQuery($lab)->where('laboratory_order_id', $orderId)->orderByDesc('sequence')->paginate(50);

            return ['data' => $page->map(fn ($r) => $this->metadata($r))->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
        });
    }

    public function lookup(Laboratory $lab, User $actor, int $orderId, int $reportId, bool $download = false): LaboratoryReportIssuance
    {
        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $download) {
            $this->order($lab, $orderId, false);
            $this->authority->check($lab, $actor, $download ? 'reports.download' : 'reports.view_history');

            return $this->metadataQuery($lab)->addSelect($download ? ['private_path'] : [])->where('laboratory_order_id', $orderId)->whereKey($reportId)->firstOrFail();
        });
    }

    public function download(Laboratory $lab, User $actor, int $orderId, int $reportId): array
    {
        $report = $this->lookup($lab, $actor, $orderId, $reportId, true);
        try {
            $bytes = $this->storage->read($report->private_path, $report->pdf_hash);
        } catch (\Throwable) {
            throw new ClinicalConflict('report_storage_unavailable', 'The private document is unavailable or failed its integrity check.', status: 503);
        }
        DB::transaction(function () use ($lab, $actor, $report) {
            $this->authority->check($lab, $actor, 'reports.download');
            $this->audit->record($lab, $actor, new AuditEvent('order_report.downloaded', 'laboratory_report_issuance', $report->id, metadata: ['laboratory_order_id' => $report->laboratory_order_id, 'pdf_hash' => $report->pdf_hash]));
        });

        return [$report, $bytes];
    }

    public function metadata(LaboratoryReportIssuance $r): array
    {
        return ['id' => $r->id, 'laboratory_order_id' => $r->laboratory_order_id, 'number' => $r->number, 'sequence' => $r->sequence,
            'type' => $r->type, 'issued_by' => $r->issued_by, 'issued_at' => $r->issued_at->toISOString(),
            'clinical_revision' => $r->clinical_revision, 'finalization_id' => $r->finalization_id, 'line_count' => $r->getAttribute('line_count') ?? count($r->clinical_snapshot),
            'pending_line_count' => $r->getAttribute('pending_line_count') ?? ($r->pending_snapshot === null ? null : count($r->pending_snapshot)),
            'pdf_hash' => $r->pdf_hash, 'pdf_size' => $r->pdf_size, 'format_version' => $r->format_version, 'identity_source' => $r->getAttribute('identity_source') ?? $r->identity_snapshot['source']];
    }

    private function order(Laboratory $lab, int $id, bool $write = true): LaboratoryOrder
    {
        $query = LaboratoryOrder::forLaboratory($lab)->whereKey($id);

        return ($write ? $query->lockForUpdate() : $query->sharedLock())->firstOrFail()->load('clinicalFinalization');
    }

    private function evidence(LaboratoryOrder $order, array $input): array
    {
        if ($order->clinical_revision !== $input['clinical_revision']) {
            throw new ClinicalConflict('clinical_revision_conflict', 'Reload the current clinical revision.');
        }
        $complete = $input['type'] === 'complete';
        if ($complete && ($order->status !== 'completed' || $order->clinicalFinalization === null)) {
            throw new ClinicalConflict('report_finalization_required', 'Complete issuance requires explicit clinical finalization evidence.');
        }
        if (! $complete && (! in_array($order->status, ['pending', 'in_process'], true) || $order->clinicalFinalization !== null)) {
            throw new ClinicalConflict('report_partial_unavailable', 'A partial report requires an open order.');
        }
        $ids = $complete ? array_column($order->clinicalFinalization->lines_snapshot, 'line_id') : $input['line_ids'];
        sort($ids, SORT_NUMERIC);
        $rows = LaboratoryOrderExam::where('laboratory_id', $order->laboratory_id)->where('laboratory_order_id', $order->id)->whereIn('id', $ids)
            ->orderBy('id')->sharedLock()->with(['clinicalResolution', 'resultCapture.lastValidation'])->get();
        if ($rows->count() !== count($ids)) {
            throw ValidationException::withMessages(['line_ids' => ['Selected lines must belong to this order.']]);
        }
        $snapshots = [];
        foreach ($rows as $line) {
            $r = $line->clinicalResolution;
            if ($r === null || ($r->resolution_type === 'validated_result' && ($line->resultCapture?->status !== 'validated' || $line->resultCapture->last_validation_id !== $r->validation_id || $line->resultCapture->lastValidation?->transition_revision !== $line->resultCapture->revision))) {
                throw new ClinicalConflict('report_line_unresolved', 'Each selected line must have a current definitive resolution.');
            }
            $snapshots[] = json_decode(DB::scalar('SELECT report_line_snapshot(?)', [$line->id]), true, flags: JSON_THROW_ON_ERROR);
        }
        if ($complete && json_decode(DB::scalar('SELECT clinical_closure_snapshot(?)', [$order->id]), true, flags: JSON_THROW_ON_ERROR) !== $order->clinicalFinalization->lines_snapshot) {
            throw new ClinicalConflict('report_evidence_conflict', 'Finalization evidence is inconsistent.');
        }

        return $snapshots;
    }

    private function pendingEvidence(LaboratoryOrder $order, array $input, array $lines): array
    {
        if (($lines[0]['snapshot_version'] ?? 1) !== 2 || $input['type'] !== 'partial') {
            return [];
        }

        return json_decode(DB::scalar('SELECT report_pending_snapshot(?)', [$order->id]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function input(array $input, string $key): void
    {
        if (array_diff(array_keys($input), ['type', 'line_ids', 'clinical_revision', 'identity_confirmation']) !== []) {
            throw ValidationException::withMessages(['input' => ['Unknown issuance fields.']]);
        }
        $integer = fn ($a, $v, $f) => is_int($v) && $v >= 0 ?: $f('A nonnegative JSON integer is required.');
        Validator::make($input, ['type' => ['required', 'in:partial,complete'], 'clinical_revision' => ['required', $integer],
            'identity_confirmation' => ['sometimes', fn ($a, $v, $f) => is_bool($v) ?: $f('A JSON boolean is required.')],
            'line_ids' => ['required_if:type,partial', 'prohibited_if:type,complete', 'array', 'list', 'min:1', 'max:100'],
            'line_ids.*' => ['required', $integer, 'min:1', 'distinct:strict']])->validate();
        if (preg_match('/\A[A-Za-z0-9_.-]{16,100}\z/', $key) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A 16–100 character opaque idempotency key is required.']]);
        }
    }
}
