<?php

namespace App\ClinicalValidation;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\User;
use App\Models\ValidationProfessional;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ValidationSignatureService
{
    public function __construct(private ValidationAuthority $authority, private AuditWriter $audit) {}

    public function read(Laboratory $lab, User $actor): array
    {
        return DB::transaction(function () use ($lab, $actor) {
            $this->authority->access($lab, $actor, 'results.validate');
            $professional = ValidationProfessional::forLaboratory($lab)->where('user_id', $actor->id)->first();
            $event = $this->latest($lab, $actor);

            return ['available' => $professional?->status === 'authorized', 'revision' => $event?->id ?? 0,
                'has_signature' => $event?->action === 'grant', 'image_hash' => $event?->action === 'grant' ? $event->image_hash : null];
        });
    }

    private function latest(Laboratory $lab, User $actor): ?object
    {
        return DB::table('validation_signature_events')->where('laboratory_id', $lab->id)->where('user_id', $actor->id)->orderByDesc('id')->first();
    }

    public function change(Laboratory $lab, User $actor, int $revision, ?UploadedFile $file): array
    {
        $image = null;
        if ($file !== null) {
            if (! $file->isValid() || $file->getSize() > 1048576) {
                throw ValidationException::withMessages(['signature' => ['Use a JPEG/PNG signature of at most 1 MiB.']]);
            }
            $bytes = file_get_contents($file->getRealPath());
            $size = @getimagesizefromstring($bytes);
            if (! $size || ! in_array($size['mime'], ['image/png', 'image/jpeg'], true) || $size[0] > 2000 || $size[1] > 1000) {
                throw ValidationException::withMessages(['signature' => ['Use a bounded raster PNG/JPEG image.']]);
            }
            $raster = @imagecreatefromstring($bytes);
            if ($raster === false) {
                throw ValidationException::withMessages(['signature' => ['Invalid signature image.']]);
            }
            ob_start();
            imagepng($raster);
            $image = ob_get_clean();
            imagedestroy($raster);
            if (strlen($image) > 1048576) {
                throw ValidationException::withMessages(['signature' => ['The normalized signature exceeds 1 MiB.']]);
            }
        }

        return DB::transaction(function () use ($lab, $actor, $revision, $image) {
            $this->authority->access($lab, $actor, 'results.validate');
            $professional = ValidationProfessional::forLaboratory($lab)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($image === null || $professional->status === 'authorized', 403, 'An authorized professional must consent to their own signature.');
            if (($this->latest($lab, $actor)?->id ?? 0) !== $revision) {
                throw new ClinicalConflict('validation_signature_revision_conflict', 'Reload your signature authorization before changing it.');
            }
            $action = $image === null ? 'revoke' : 'grant';
            DB::table('validation_signature_events')->insert(['laboratory_id' => $lab->id, 'user_id' => $actor->id, 'professional_id' => $professional->id,
                'action' => $action, 'image' => $image === null ? null : base64_encode($image), 'image_hash' => $image === null ? null : hash('sha256', $image), 'recorded_at' => now()]);
            $this->audit->record($lab, $actor, new AuditEvent('order_report.signature_'.$action, 'validation_professional', $professional->id));

            return $this->read($lab, $actor);
        });
    }

    public function snapshot(Laboratory $lab, User $actor): array
    {
        $professional = ValidationProfessional::forLaboratory($lab)->where('user_id', $actor->id)->sharedLock()->first();
        $event = $this->latest($lab, $actor);
        if ($professional?->status !== 'authorized' || $event?->action !== 'grant') {
            throw new ClinicalConflict('validation_signature_unavailable', 'Authorize your own digital signature or use the manual signature option.');
        }

        return ['grant_id' => $event->id, 'image' => $event->image, 'image_hash' => $event->image_hash,
            'professional' => $professional->only(['id', 'user_id', 'professional_name', 'profession', 'registration_number']),
            'confirmed_by' => $actor->id, 'confirmed_at' => now()->toISOString()];
    }
}
