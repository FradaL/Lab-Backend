<?php

namespace App\ClinicalExams;

use App\Authorization\ClinicalReasonTenantAuthority as Authority;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClinicalReasonReadService
{
    public function __construct(private Authority $authority, private ClinicalReasonEligibilityPolicy $policy) {}

    public function index(Laboratory $lab, ?User $actor, array $input): array
    {
        return $this->authority->run($lab, $actor, [Authority::VIEW, Authority::LOCAL_VIEW], function () use ($lab, $actor, $input): array {
            $rules = ['page' => ['sometimes', 'integer', 'min:1'], 'search' => ['sometimes', 'nullable', 'string', 'max:200'],
                'source' => ['sometimes', Rule::in(['global', 'local'])], 'classification' => ['sometimes', Rule::in(['incidence_only', 'terminal_candidate'])],
                'active' => ['sometimes', Rule::in(['true', 'false'])], 'eligible' => ['sometimes', Rule::in(['true', 'false'])]];
            Validator::make($input, $rules)->validate();
            if (array_diff(array_keys($input), array_keys($rules)) !== []) {
                throw ValidationException::withMessages(['query' => 'Only documented query parameters are accepted.']);
            }
            $origins = array_values(array_filter(['global', 'local'], fn ($origin) => $actor->can($origin === 'global' ? Authority::VIEW : Authority::LOCAL_VIEW)));
            if (isset($input['source'])) {
                abort_unless(in_array($input['source'], $origins, true), 403);
                $origins = [$input['source']];
            }
            $candidates = null;
            foreach ($origins as $origin) {
                $query = $origin === 'global' ? $this->policy->globalSelections($lab) : $this->policy->localSelections($lab);
                if ($candidates === null) {
                    $candidates = $query;
                } else {
                    $candidates->unionAll($query);
                }
            }
            $query = DB::query()->fromSub($this->policy->selectionQuery($candidates), 'catalog');
            if (isset($input['search'])) {
                $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input['search']);
                $query->whereRaw('name ILIKE ?', ['%'.$term.'%']);
            }
            if (isset($input['classification'])) {
                $query->where('classification', $input['classification']);
            }
            if (isset($input['active'])) {
                $query->where('is_active', $input['active'] === 'true');
            }
            if (isset($input['eligible'])) {
                $query->where('request_eligible', $input['eligible'] === 'true');
            }
            $page = $query->orderBy('source')->orderBy('reason_id')->paginate(50, ['*'], 'page', $input['page'] ?? 1);

            return ['data' => $page->getCollection()->map(fn ($row) => $this->represent($row, $actor))->all(),
                'meta' => ['laboratory_id' => $lab->id, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total(),
                    'visible_sources' => $origins, 'eligibility_scope' => 'catalog', 'informational' => true]];
        });
    }

    private function represent(object $row, User $actor): array
    {
        $status = $this->policy->selectionStatus($row);
        $owner = $actor->hasRole('owner');

        return ['key' => $row->source.':'.$row->reason_id, 'source' => $row->source,
            'reason_id' => (int) $row->reason_id, 'code' => $row->code,
            'reason_reference' => $this->policy->referenceFor($row),
            'publication_status' => 'published',
            'version' => ['id' => (int) $row->version_id, 'number' => (int) $row->version_number, 'name' => $row->name, 'description' => $row->description,
                'classification' => $row->classification, 'evidence_requirements' => json_decode($row->evidence_requirements, true, flags: JSON_THROW_ON_ERROR),
                'is_active' => (bool) $row->is_active, 'created_at' => Carbon::parse($row->version_created_at)->toISOString()],
            'latest_version' => ['id' => (int) $row->latest_version_id, 'number' => (int) $row->latest_version_number, 'is_active' => (bool) $row->latest_is_active],
            'eligibility' => $status,
            'actions' => ['can_select_for_request' => $status['can_select_for_request'] && $actor->can('orders.clinical_exceptions.request'),
                'can_publish_local' => $row->source === 'local' && $owner && $actor->can(Authority::LOCAL_PUBLISH),
                'can_manage_local_activation' => $row->source === 'local' && $owner && $actor->can(Authority::LOCAL_MANAGE),
                'can_update_adoption' => $row->source === 'global' && $owner && $actor->can(Authority::UPDATE) && $row->latest_version_number > $row->version_number,
                'can_view_history' => $actor->can($row->source === 'local' ? Authority::LOCAL_HISTORY : Authority::HISTORY),
                'can_review_pending' => $row->pending_review_count > 0 && $actor->can(Authority::REVIEW)]];
    }
}
