<?php

namespace App\ClinicalReports;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReportSettingsService
{
    public function __construct(private ReportAuthority $authority, private AuditWriter $audit) {}

    public function read(Laboratory $lab, User $actor, bool $draft = false): array
    {
        return DB::transaction(function () use ($lab, $actor, $draft) {
            $this->authority->check($lab, $actor, 'reports.settings.view');
            [$revision,$presentation] = $this->snapshot($lab, $draft);
            unset($presentation['logo']);

            return ['revision' => $revision, 'presentation' => $presentation] + ($draft ? ['published_revision' => $this->snapshot($lab)[0], 'state' => $revision === $this->snapshot($lab)[0] ? 'published' : 'draft'] : []);
        });
    }

    public function readLogo(Laboratory $lab, User $actor, bool $draft = false): ?string
    {
        return DB::transaction(function () use ($lab, $actor, $draft) {
            $this->authority->check($lab, $actor, 'reports.settings.view');
            [, $presentation] = $this->snapshot($lab, $draft);

            return $presentation['logo'] === null ? null : base64_decode($presentation['logo'], true);
        });
    }

    public function snapshot(Laboratory $lab, bool $draft = false): array
    {
        $row = $draft ? DB::table('laboratory_report_settings_drafts')->where('laboratory_id', $lab->id)->sharedLock()->first() : null;
        $row ??= DB::table('laboratory_report_settings')->where('laboratory_id', $lab->id)->sharedLock()->first();

        $presentation = $row === null
            ? ['trade_name' => $lab->name, 'address' => $lab->address, 'phone' => $lab->phone, 'header' => '', 'footer' => '', 'logo' => null, 'logo_hash' => null]
            : json_decode($row->presentation, true, flags: JSON_THROW_ON_ERROR);
        $presentation['styles'] ??= ReportPresentationStyles::defaults();
        $presentation += ['color_theme' => 'neutral', 'area_order' => [], 'exam_order' => []];

        return [$row === null ? 0 : $row->revision, $presentation];
    }

    public function update(Laboratory $lab, User $actor, array $input, ?UploadedFile $logo = null, bool $draft = false): array
    {
        $allowed = ['styles', 'revision', 'trade_name', 'address', 'phone', 'header', 'footer', 'remove_logo', 'color_theme', 'area_order', 'exam_order'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['input' => ['Unknown presentation fields.']]);
        }
        Validator::make($input, ['revision' => ['required', fn ($a, $v, $f) => is_int($v) && $v >= 0 ?: $f('A JSON integer revision is required.')],
            'trade_name' => ['sometimes', 'required', 'string', 'max:200'], 'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'], 'header' => ['sometimes', 'nullable', 'string', 'max:500'], 'footer' => ['sometimes', 'nullable', 'string', 'max:500'],
            'remove_logo' => ['sometimes', 'boolean']])->validate();
        Validator::make($input, ['color_theme' => ['sometimes', 'in:neutral,teal,blue,burgundy'],
            'area_order' => ['sometimes', 'array', 'list', 'max:200'], 'area_order.*' => ['integer', 'min:1', 'distinct:strict'],
            'exam_order' => ['sometimes', 'array', 'list', 'max:1000'], 'exam_order.*' => ['integer', 'min:1', 'distinct:strict']])->validate();
        foreach (['area_order' => 'laboratory_areas', 'exam_order' => 'laboratory_exams'] as $field => $table) {
            if (isset($input[$field]) && DB::table($table)->where('laboratory_id', $lab->id)->whereIn('id', $input[$field])->count() !== count($input[$field])) {
                throw ValidationException::withMessages([$field => ['The presentation order must use identifiers from this laboratory.']]);
            }
        }
        foreach ($input as $key => $value) {
            if (is_string($value) && (strip_tags($value) !== $value || preg_match('/[\x00-\x08\x0b-\x1f]/u', $value))) {
                throw ValidationException::withMessages([$key => ['Only plain text is accepted.']]);
            }
        }
        foreach (['trade_name' => 0, 'phone' => 0, 'address' => 3, 'header' => 3, 'footer' => 3] as $field => $maximumNewlines) {
            if (isset($input[$field]) && substr_count($input[$field], "\n") > $maximumNewlines) {
                throw ValidationException::withMessages([$field => ['Too many explicit line breaks for the report layout.']]);
            }
        }
        if (array_key_exists('styles', $input)) {
            ReportPresentationStyles::validate($input['styles']);
        }
        $normalized = $logo === null ? null : $this->logo($logo);

        return DB::transaction(function () use ($lab, $actor, $input, $normalized, $draft) {
            $current = Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $this->authority->check($current, $actor, 'reports.settings.edit');
            [$revision,$presentation] = $this->snapshot($current, $draft);
            if ($revision !== $input['revision']) {
                throw new ClinicalConflict('report_settings_revision_conflict', 'Reload presentation settings.');
            }
            foreach (['trade_name', 'address', 'phone', 'header', 'footer', 'color_theme', 'area_order', 'exam_order'] as $field) {
                if (array_key_exists($field, $input)) {
                    $presentation[$field] = $input[$field];
                }
            }
            if (array_key_exists('styles', $input)) {
                $presentation['styles'] = $input['styles'];
            }
            if (($input['remove_logo'] ?? false) || $normalized !== null) {
                $presentation['logo'] = $normalized;
                $presentation['logo_hash'] = $normalized === null ? null : hash('sha256', base64_decode($normalized, true));
            }
            app(ClinicalReportRenderer::class)->validatePresentation($presentation);
            DB::table($draft ? 'laboratory_report_settings_drafts' : 'laboratory_report_settings')->updateOrInsert(['laboratory_id' => $lab->id], ['revision' => $revision + 1, 'presentation' => json_encode($presentation, JSON_THROW_ON_ERROR)]);
            $this->audit->record($lab, $actor, new AuditEvent($draft ? 'order_report.settings_draft_saved' : 'order_report.settings_updated', 'laboratory', $lab->id, oldValues: ['revision' => $revision], newValues: ['revision' => $revision + 1, 'logo_hash' => $presentation['logo_hash']]));
            unset($presentation['logo']);

            return ['revision' => $revision + 1, 'presentation' => $presentation] + ($draft ? ['published_revision' => $this->snapshot($current)[0], 'state' => 'draft'] : []);
        });
    }

    public function publish(Laboratory $lab, User $actor, int $revision): array
    {
        return DB::transaction(function () use ($lab, $actor, $revision) {
            $current = Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $this->authority->check($current, $actor, 'reports.settings.edit');
            if (! DB::table('laboratory_report_settings_drafts')->where('laboratory_id', $lab->id)->exists()) {
                throw new ClinicalConflict('report_design_draft_required', 'Save a report design draft before publishing.');
            }
            [$draftRevision, $presentation] = $this->snapshot($current, true);
            if ($draftRevision !== $revision) {
                throw new ClinicalConflict('report_settings_revision_conflict', 'Reload the saved draft before publishing.');
            }
            $existing = DB::table('laboratory_report_settings_publications')->where('laboratory_id', $lab->id)->where('draft_revision', $revision)->first();
            if ($existing === null) {
                app(ClinicalReportRenderer::class)->validatePresentation($presentation);
                DB::table('laboratory_report_settings_publications')->insert(['laboratory_id' => $lab->id, 'draft_revision' => $revision,
                    'presentation' => json_encode($presentation, JSON_THROW_ON_ERROR), 'published_by' => $actor->id, 'publisher_name' => $actor->name, 'published_at' => now()]);
                DB::table('laboratory_report_settings')->updateOrInsert(['laboratory_id' => $lab->id], ['revision' => $revision, 'presentation' => json_encode($presentation, JSON_THROW_ON_ERROR)]);
                $this->audit->record($lab, $actor, new AuditEvent('order_report.settings_published', 'laboratory', $lab->id, newValues: ['revision' => $revision]));
            }

            return $this->read($current, $actor, true);
        });
    }

    public function publications(Laboratory $lab, User $actor): array
    {
        $this->authority->check($lab, $actor, 'reports.settings.view');
        $page = DB::table('laboratory_report_settings_publications')->where('laboratory_id', $lab->id)->orderByDesc('id')
            ->paginate(25, ['id', 'draft_revision', 'published_by', 'publisher_name', 'published_at']);

        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]];
    }

    private function logo(UploadedFile $file): string
    {
        if (! $file->isValid() || $file->getSize() > 2097152) {
            throw ValidationException::withMessages(['logo' => ['A JPEG/PNG logo of at most 2 MiB is required.']]);
        }
        $bytes = file_get_contents($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $dimensions = @getimagesizefromstring($bytes);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || ! $dimensions || $dimensions[0] > 2000 || $dimensions[1] > 2000 || $dimensions[0] * $dimensions[1] > 4000000) {
            throw ValidationException::withMessages(['logo' => ['Only bounded raster JPEG/PNG images are accepted.']]);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['logo' => ['Invalid image.']]);
        }
        ob_start();
        imagepng($image);
        $safe = ob_get_clean();
        imagedestroy($image);
        if (strlen($safe) > 1048576) {
            throw ValidationException::withMessages(['logo' => ['Normalized logo exceeds 1 MiB.']]);
        }

        return base64_encode($safe);
    }
}
