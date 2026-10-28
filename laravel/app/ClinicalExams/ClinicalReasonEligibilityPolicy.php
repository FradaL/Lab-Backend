<?php

namespace App\ClinicalExams;

use App\Authorization\ClinicalReasonTenantAuthority as Authority;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReason;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\LocalClinicalReasonReview;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClinicalReasonEligibilityPolicy
{
    public function __construct(private Authority $authority, private ClinicalReasonReviewStore $reviews) {}

    private const TERMINAL_CLASS = 'terminal_candidate';

    private const REQUEST_FLAGS = ['impact_unclassified', 'clinical_review_pending', 'clinical_review_non_favorable', 'reason_inactive', 'clinical_reason_not_terminal'];

    /** SQL projections are shared by selection reads, filtering and the write policy. */
    public function globalSelections(Laboratory $lab): Builder
    {
        $query = DB::table('clinical_reason_adoptions as a')
            ->join('clinical_reason_adoption_versions as av', 'av.adoption_id', '=', 'a.id')
            ->whereRaw('av.revision=(SELECT max(newer.revision) FROM clinical_reason_adoption_versions newer WHERE newer.adoption_id=a.id)')
            ->join('global_clinical_reason_versions as v', 'v.id', '=', 'av.version_id')
            ->join('global_clinical_reasons as reason', 'reason.id', '=', 'a.reason_id')
            ->join('global_clinical_reason_versions as latest', 'latest.reason_id', '=', 'reason.id')
            ->whereRaw('latest.number=(SELECT max(n.number) FROM global_clinical_reason_versions n WHERE n.reason_id=reason.id)')
            ->where('a.laboratory_id', $lab->id)
            ->selectRaw("'global' AS source, a.reason_id, a.id AS adoption_id, av.id AS adoption_version_id, reason.code, v.id AS version_id, v.number AS version_number, v.name, v.description, v.classification, v.evidence_requirements, v.is_active, v.created_at AS version_created_at, latest.id AS latest_version_id, latest.number AS latest_version_number, latest.is_active AS latest_is_active")
            ->selectRaw("EXISTS(SELECT 1 FROM global_clinical_reason_versions gv WHERE gv.reason_id=a.reason_id AND COALESCE((SELECT i.classification FROM global_clinical_reason_impacts i WHERE i.version_id=gv.id ORDER BY i.revision DESC LIMIT 1),'unclassified')='unclassified') AS impact_unclassified")
            ->selectRaw('(NOT v.is_active OR NOT latest.is_active) AS reason_inactive')
            ->selectRaw('v.classification <> ? AS clinical_reason_not_terminal', [self::TERMINAL_CLASS]);

        return $this->withReviewCounts($query, 'clinical_reason_impact_reviews', 'clinical_reason_impact_resolutions', 'r.adoption_id=a.id', true);
    }

    public function localSelections(Laboratory $lab): Builder
    {
        $query = DB::table('laboratory_clinical_reasons as reason')
            ->join('laboratory_clinical_reason_versions as v', 'v.reason_id', '=', 'reason.id')
            ->whereRaw('v.number=(SELECT max(n.number) FROM laboratory_clinical_reason_versions n WHERE n.reason_id=reason.id)')
            ->where('reason.laboratory_id', $lab->id)
            ->selectRaw("'local' AS source, reason.id AS reason_id, NULL::bigint AS adoption_id, NULL::bigint AS adoption_version_id, reason.code, v.id AS version_id, v.number AS version_number, v.name, v.description, v.classification, v.evidence_requirements, v.is_active, v.created_at AS version_created_at, v.id AS latest_version_id, v.number AS latest_version_number, v.is_active AS latest_is_active")
            ->selectRaw('false AS impact_unclassified, NOT v.is_active AS reason_inactive')
            ->selectRaw('v.classification <> ? AS clinical_reason_not_terminal', [self::TERMINAL_CLASS]);

        return $this->withReviewCounts($query, 'local_clinical_reason_reviews', 'local_clinical_reason_resolutions', 'r.reason_id=reason.id', false);
    }

    private function withReviewCounts(Builder $query, string $reviews, string $resolutions, string $subject, bool $blocksNew): Builder
    {
        // Identifiers/subject are server constants, never request input. FK identities are globally unique.
        $outcome = "(SELECT evaluation.outcome FROM {$resolutions} evaluation WHERE evaluation.review_id=r.id ORDER BY evaluation.revision DESC LIMIT 1)";
        $pending = "(SELECT count(*) FROM {$reviews} r WHERE {$subject} AND {$outcome} IS DISTINCT FROM 'favorable')";
        $nonFavorable = "(SELECT count(*) FROM {$reviews} r WHERE {$subject} AND {$outcome}='non_favorable')";

        return $query->selectRaw("{$pending} AS pending_review_count, {$nonFavorable} AS non_favorable_review_count")
            ->selectRaw(($blocksNew ? "{$pending}>0" : 'false').' AS clinical_review_pending')
            ->selectRaw(($blocksNew ? "{$nonFavorable}>0" : 'false').' AS clinical_review_non_favorable');
    }

    public function selectionQuery(Builder $query): Builder
    {
        return DB::query()->fromSub($query, 'selection')->select('selection.*')
            ->selectRaw('NOT ('.implode(' OR ', self::REQUEST_FLAGS).') AS request_eligible');
    }

    public function selectionStatus(object $row): array
    {
        $codes = array_values(array_filter(self::REQUEST_FLAGS, fn ($flag) => (bool) $row->$flag));

        return ['can_select_for_request' => $codes === [], 'request_blockers' => $codes,
            'pending_review_count' => (int) $row->pending_review_count, 'non_favorable_review_count' => (int) $row->non_favorable_review_count,
            'scope' => 'catalog', 'informational' => true];
    }

    public function referenceFor(object $row): array
    {
        $reference = ['source' => $row->source, 'reason_id' => (int) $row->reason_id, 'version_id' => (int) $row->version_id];

        return $row->source === 'local' ? $reference : $reference + ['adoption_id' => (int) $row->adoption_id, 'adoption_version_id' => (int) $row->adoption_version_id];
    }

    public function assertTerminalCandidate(GlobalClinicalReasonVersion|LaboratoryClinicalReasonVersion $version): void
    {
        if ($version->classification !== self::TERMINAL_CLASS) {
            throw new ClinicalConflict('clinical_reason_not_terminal', 'An incidence-only reason cannot request a definitive exception resolution.');
        }
    }

    /** Pending approval needs the original context; no version/history hydration. */
    private function localApprovalRows(Laboratory $lab): Builder
    {
        $query = DB::table('clinical_exception_local_reason_references as context')
            ->join('laboratory_clinical_reason_versions as original', 'original.id', '=', 'context.version_id')
            ->where('context.laboratory_id', $lab->id)->select('context.exception_request_id', 'context.reason_id', 'context.version_id')
            ->selectRaw('EXISTS(SELECT 1 FROM laboratory_clinical_reason_versions changed JOIN laboratory_clinical_reason_versions prior ON prior.reason_id=changed.reason_id AND prior.number=changed.number-1
                WHERE changed.reason_id=context.reason_id AND changed.number>original.number AND (changed.classification<>prior.classification OR prior.is_active AND NOT changed.is_active)
                AND NOT EXISTS(SELECT 1 FROM local_clinical_reason_reviews required WHERE required.exception_request_id=context.exception_request_id AND required.trigger_version_id=changed.id)) AS review_missing');

        return $this->withReviewCounts($query, 'local_clinical_reason_reviews', 'local_clinical_reason_resolutions', 'r.exception_request_id=context.exception_request_id', true);
    }

    /** Bounded batch projection for a page of immutable original references. */
    public function approvalStatuses(Laboratory $lab, Collection $references): array
    {
        $globalIds = $references->where('source', 'global')->pluck('adoption_id')->unique()->all();
        $localIds = $references->where('source', 'local')->pluck('exception_request_id')->all();
        $globals = $globalIds === [] ? collect() : $this->globalSelections($lab)->whereIn('a.id', $globalIds)->get()->keyBy('adoption_id');
        $locals = $localIds === [] ? collect() : $this->localApprovalRows($lab)->whereIn('context.exception_request_id', $localIds)->get()->keyBy('exception_request_id');
        $result = [];
        foreach ($references as $reference) {
            $row = $reference->source === 'global' ? $globals->get($reference->adoption_id) : $locals->get($reference->exception_request_id);
            abort_if($row === null, 404);
            $codes = [];
            if ($reference->source === 'global' && $row->impact_unclassified) {
                $codes[] = 'impact_unclassified';
            }
            if ($row->clinical_review_pending || ($row->review_missing ?? false)) {
                $codes[] = 'clinical_review_pending';
            }
            if ($row->clinical_review_non_favorable) {
                $codes[] = 'clinical_review_non_favorable';
            }
            if ($reference->version->classification !== self::TERMINAL_CLASS) {
                $codes[] = 'clinical_reason_not_terminal';
            }
            $result[$reference->exception_request_id] = ['can_approve_from_catalog' => $codes === [], 'approval_blockers' => $codes,
                'pending_review_count' => (int) $row->pending_review_count, 'non_favorable_review_count' => (int) $row->non_favorable_review_count,
                'scope' => 'original_request_catalog', 'informational' => true,
                'review_context' => $reference->source === 'global' ? ['source' => 'global', 'adoption_id' => $reference->adoption_id] : ['source' => 'local', 'reason_id' => $reference->reason_id, 'exception_request_id' => $reference->exception_request_id]];
        }

        return $result;
    }

    public function eligibility(Laboratory $lab, ?User $actor, int $adoptionId): array
    {
        return $this->authority->run($lab, $actor, Authority::VIEW, fn () => $this->evaluate($lab, $adoptionId));
    }

    /** Future structured clinical commands must call inside their outer transaction before writing. */
    public function assertAllowed(Laboratory $lab, ?User $actor, int $adoptionId, string $operation): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Clinical authorization must share the transaction of the protected write.');
        }
        if (! in_array($operation, ['request', 'approve'], true)) {
            throw ValidationException::withMessages(['operation' => 'Use request or approve.']);
        }
        $permission = $operation === 'request' ? 'orders.clinical_exceptions.request' : 'orders.clinical_exceptions.approve';
        $this->authority->run($lab, $actor, $permission, function () use ($lab, $adoptionId, $operation): void {
            $status = $this->evaluate($lab, $adoptionId);
            if (! $status['can_'.$operation]) {
                throw new ClinicalConflict('clinical_reason_use_blocked', 'The selected clinical reason has unresolved dependencies.');
            }
        });
    }

    public function assertLocalAllowed(Laboratory $lab, ?User $actor, int $reasonId, int $versionId, string $operation, ?int $requestId = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Clinical authorization must share the transaction of the protected write.');
        }
        if (! in_array($operation, ['request', 'approve'], true)) {
            throw ValidationException::withMessages(['operation' => 'Use request or approve.']);
        }
        $permission = $operation === 'request' ? 'orders.clinical_exceptions.request' : 'orders.clinical_exceptions.approve';

        return $this->authority->run($lab, $actor, $permission, function () use ($lab, $reasonId, $versionId, $operation, $requestId): array {
            $reason = LaboratoryClinicalReason::forLaboratory($lab)->with('latestVersion')->findOrFail($reasonId);
            $version = LaboratoryClinicalReasonVersion::forLaboratory($lab)->where('reason_id', $reasonId)->findOrFail($versionId);
            if ($operation === 'request') {
                if ($reason->latestVersion->id !== $versionId) {
                    throw new ClinicalConflict('clinical_reason_selection_conflict', 'Select the current local version.');
                }
                $selection = $this->localSelections($lab)->where('reason.id', $reasonId)->firstOrFail();
                if ($selection->reason_inactive) {
                    throw new ClinicalConflict('clinical_reason_use_blocked', 'The local clinical reason is inactive.');
                }

                return ['source' => 'local', 'reason_id' => $reasonId, 'version_id' => $versionId, 'review_resolution_ids' => [], 'result' => 'allowed'];
            }
            ClinicalExceptionLocalReasonReference::forLaboratory($lab)->where('reason_id', $reasonId)->where('version_id', $versionId)->where('exception_request_id', $requestId)->firstOrFail();
            $state = $this->localApprovalRows($lab)->where('context.exception_request_id', $requestId)->firstOrFail();
            $reviews = LocalClinicalReasonReview::forLaboratory($lab)->where('exception_request_id', $requestId)->where('reason_id', $reasonId)->with('latestResolution')->get();
            $resolved = $reviews->filter(fn ($review) => $review->latestResolution?->outcome === 'favorable');
            if ($state->review_missing || $state->clinical_review_pending) {
                throw new ClinicalConflict('clinical_reason_use_blocked', 'Local clinical review is pending or not favorable.');
            }

            return ['source' => 'local', 'reason_id' => $reasonId, 'version_id' => $versionId, 'review_ids' => $reviews->pluck('id')->all(),
                'review_resolution_ids' => $resolved->map(fn ($review) => $review->latestResolution->id)->values()->all(), 'result' => 'allowed'];
        });
    }

    private function evaluate(Laboratory $lab, int $adoptionId): array
    {
        $row = $this->globalSelections($lab)->where('a.id', $adoptionId)->firstOrFail();
        $unclassified = DB::table('global_clinical_reason_versions as gv')->where('gv.reason_id', $row->reason_id)
            ->whereRaw("COALESCE((SELECT i.classification FROM global_clinical_reason_impacts i WHERE i.version_id=gv.id ORDER BY i.revision DESC LIMIT 1),'unclassified')='unclassified'")
            ->orderBy('gv.number')->pluck('gv.id')->all();
        $pending = ClinicalReasonImpactReview::forLaboratory($lab)->where('adoption_id', $adoptionId)->whereDoesntHave('latestResolution', fn ($q) => $q->where('outcome', 'favorable'))->orderBy('id')->pluck('id')->all();
        $common = array_values(array_filter(['impact_unclassified', 'clinical_review_pending'], fn ($flag) => (bool) $row->$flag));
        $request = $common;
        if ($row->reason_inactive) {
            $request[] = 'reason_inactive';
        }

        return ['adoption_id' => $adoptionId, 'reason_id' => (int) $row->reason_id, 'adopted_version_id' => (int) $row->version_id,
            'unclassified_version_ids' => $unclassified, 'pending_review_ids' => $pending,
            'can_request' => $request === [], 'can_approve' => $common === [],
            'request_blockers' => $request, 'approval_blockers' => $common,
            'selection' => ['reason_reference' => $this->referenceFor($row), 'eligibility' => $this->selectionStatus($row)]];
    }
}
