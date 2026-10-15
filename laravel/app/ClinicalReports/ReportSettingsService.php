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

    public function read(Laboratory $lab, User $actor): array
    {
        return DB::transaction(function () use ($lab, $actor) {
            $this->authority->check($lab, $actor, 'reports.settings.view');
            [$revision,$presentation] = $this->snapshot($lab);
            unset($presentation['logo']);

            return ['revision' => $revision, 'presentation' => $presentation];
        });
    }

    public function snapshot(Laboratory $lab): array
    {
        $row = DB::table('laboratory_report_settings')->where('laboratory_id', $lab->id)->sharedLock()->first();

        return $row === null ? [0, ['trade_name' => $lab->name, 'address' => $lab->address, 'phone' => $lab->phone, 'header' => '', 'footer' => '', 'logo' => null, 'logo_hash' => null]] : [$row->revision, json_decode($row->presentation, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function update(Laboratory $lab, User $actor, array $input, ?UploadedFile $logo = null): array
    {
        $allowed = ['revision', 'trade_name', 'address', 'phone', 'header', 'footer', 'remove_logo'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['input' => ['Unknown presentation fields.']]);
        }
        Validator::make($input, ['revision' => ['required', fn ($a, $v, $f) => is_int($v) && $v >= 0 ?: $f('A JSON integer revision is required.')],
            'trade_name' => ['sometimes', 'required', 'string', 'max:200'], 'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'], 'header' => ['sometimes', 'nullable', 'string', 'max:500'], 'footer' => ['sometimes', 'nullable', 'string', 'max:500'],
            'remove_logo' => ['sometimes', 'boolean']])->validate();
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
        $normalized = $logo === null ? null : $this->logo($logo);

        return DB::transaction(function () use ($lab, $actor, $input, $normalized) {
            $current = Laboratory::whereKey($lab->id)->lockForUpdate()->firstOrFail();
            $this->authority->check($current, $actor, 'reports.settings.edit');
            [$revision,$presentation] = $this->snapshot($current);
            if ($revision !== $input['revision']) {
                throw new ClinicalConflict('report_settings_revision_conflict', 'Reload presentation settings.');
            }
            foreach (['trade_name', 'address', 'phone', 'header', 'footer'] as $field) {
                if (array_key_exists($field, $input)) {
                    $presentation[$field] = $input[$field];
                }
            }
            if (($input['remove_logo'] ?? false) || $normalized !== null) {
                $presentation['logo'] = $normalized;
                $presentation['logo_hash'] = $normalized === null ? null : hash('sha256', base64_decode($normalized, true));
            }
            DB::table('laboratory_report_settings')->updateOrInsert(['laboratory_id' => $lab->id], ['revision' => $revision + 1, 'presentation' => json_encode($presentation, JSON_THROW_ON_ERROR)]);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.settings_updated', 'laboratory', $lab->id, oldValues: ['revision' => $revision], newValues: ['revision' => $revision + 1, 'logo_hash' => $presentation['logo_hash']]));
            unset($presentation['logo']);

            return ['revision' => $revision + 1, 'presentation' => $presentation];
        });
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
