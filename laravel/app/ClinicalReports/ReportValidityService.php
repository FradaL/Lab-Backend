<?php

namespace App\ClinicalReports;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryReportIssuance;
use App\Models\User;
use App\ReportDelivery\ReportOperationalGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReportValidityService
{
    public function __construct(private ReportAuthority $authority, private PrivateReportStorage $storage, private ReportOperationalGuard $operational, private AuditWriter $audit) {}

    public function change(Laboratory $lab, User $actor, int $orderId, int $reportId, array $input, string $key): array
    {
        if (array_diff(array_keys($input), ['state', 'expected_revision', 'reason', 'replacement_report_id']) !== [] || preg_match('/\A[A-Za-z0-9_.-]{16,100}\z/', $key) !== 1) {
            throw ValidationException::withMessages(['input' => ['Send only validity fields and a 16–100 character Idempotency-Key.']]);
        }
        Validator::make($input, [
            'state' => ['required', 'in:released,suspended,invalidated,superseded'],
            'expected_revision' => ['required', fn ($a, $v, $f) => is_int($v) && $v >= 0 ?: $f('A nonnegative JSON integer is required.')],
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/'],
            'replacement_report_id' => ['required_if:state,superseded', 'prohibited_unless:state,superseded', fn ($a, $v, $f) => is_int($v) && $v > 0 ?: $f('A positive JSON integer is required.')],
        ])->validate();
        ksort($input);
        $requestHash = hash('sha256', json_encode([$orderId, $reportId, $input], JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', 'report-validity:'.$key);

        return DB::transaction(function () use ($lab, $actor, $orderId, $reportId, $input, $requestHash, $keyHash) {
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->authority->check($lab, $actor, 'reports.issue');
            $report = LaboratoryReportIssuance::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($reportId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('report_validity_events')->where('laboratory_id', $lab->id)->where('idempotency_hash', $keyHash)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash) || $existing->actor_id !== $actor->id) {
                    throw new ClinicalConflict('report_validity_idempotency_conflict', 'This key identifies another validity operation.');
                }

                return $this->data($existing);
            }
            $previous = DB::table('report_validity_events')->where('report_id', $reportId)->orderByDesc('revision')->first();
            if (($previous->revision ?? 0) !== $input['expected_revision']) {
                throw new ClinicalConflict('report_validity_revision_conflict', 'Reload the report validity history.');
            }
            if (in_array($previous->state ?? null, ['invalidated', 'superseded'], true) || ($previous->state ?? null) === $input['state']) {
                throw new ClinicalConflict('report_validity_transition_conflict', 'The current validity state cannot make this transition.');
            }
            if ($input['state'] === 'released') {
                $reason = DB::scalar('SELECT report_delivery_evidence_block(?, ?, ?)', [$lab->id, $orderId, $reportId]);
                if ($reason !== null) {
                    throw new ClinicalConflict($reason, 'Release requires current definitive clinical evidence.');
                }
                try {
                    $bytes = $this->storage->read($report->private_path, $report->pdf_hash);
                } catch (\Throwable) {
                    throw new ClinicalConflict('report_storage_unavailable', 'The private report is not intact.', status: 503);
                }
                if (strlen($bytes) !== $report->pdf_size) {
                    throw new ClinicalConflict('report_storage_unavailable', 'The private report is not intact.', status: 503);
                }
            }
            if ($input['state'] === 'superseded') {
                $replacement = LaboratoryReportIssuance::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($input['replacement_report_id'])->lockForUpdate()->firstOrFail();
                $this->operational->assertCurrent($order, $replacement);
                $sourceLines = array_column($report->clinical_snapshot, 'line_id');
                if ($replacement->sequence <= $report->sequence || array_diff($sourceLines, array_column($replacement->clinical_snapshot, 'line_id')) !== []) {
                    throw new ClinicalConflict('report_replacement_invalid', 'Use a newer released report covering all replaced lines.');
                }
            }
            $id = DB::table('report_validity_events')->insertGetId([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'report_id' => $reportId,
                'revision' => $input['expected_revision'] + 1, 'state' => $input['state'],
                'replacement_report_id' => $input['replacement_report_id'] ?? null,
                'reason' => trim($input['reason']), 'actor_id' => $actor->id, 'occurred_at' => now(),
                'idempotency_hash' => $keyHash, 'request_hash' => $requestHash,
            ]);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.validity_changed', 'laboratory_report_issuance', $reportId,
                newValues: ['state' => $input['state'], 'revision' => $input['expected_revision'] + 1],
                metadata: ['laboratory_order_id' => $orderId, 'validity_event_id' => $id, 'replacement_report_id' => $input['replacement_report_id'] ?? null]));

            return $this->data(DB::table('report_validity_events')->where('id', $id)->first());
        });
    }

    public function history(Laboratory $lab, User $actor, int $orderId, int $reportId): array
    {
        $this->authority->check($lab, $actor, 'reports.view_history');
        LaboratoryReportIssuance::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($reportId)->firstOrFail();
        $page = DB::table('report_validity_events')->where('laboratory_id', $lab->id)->where('report_id', $reportId)->orderByDesc('revision')->paginate(50);

        return ['data' => $page->map(fn ($event) => $this->data($event))->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]];
    }

    private function data(object $event): array
    {
        unset($event->idempotency_hash, $event->request_hash);

        return (array) $event;
    }
}
