<?php

namespace App\Http\Controllers\Api\V1;

use App\ClinicalReports\ClinicalReportService;
use App\ClinicalReports\ReportSettingsService;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryReportIssuance;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ClinicalReportController extends Controller
{
    public function __construct(private ClinicalReportService $service, private ReportSettingsService $settings, private CurrentLaboratory $current) {}

    public function settings(Request $r)
    {
        return response()->json(['data' => $this->settings->read($this->current->get(), $r->user())]);
    }

    public function updateSettings(Request $r)
    {
        return response()->json(['data' => $this->settings->update($this->current->get(), $r->user(), $r->all())]);
    }

    public function readLogo(Request $r)
    {
        $bytes = $this->settings->readLogo($this->current->get(), $r->user());

        return response($bytes ?? '', $bytes === null ? 404 : 200)
            ->header('Content-Type', 'image/png')->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function logo(Request $r)
    {
        $r->validate(['revision' => ['required', 'regex:/\A[0-9]+\z/'], 'logo' => ['required', 'file', 'max:2048']]);
        if (array_diff(array_keys($r->all()), ['revision', 'logo', 'changes']) !== []) {
            throw ValidationException::withMessages(['input' => ['Unknown logo fields.']]);
        }

        $changes = [];
        if ($r->has('changes')) {
            try {
                $object = json_decode($r->input('changes'), flags: JSON_THROW_ON_ERROR);
                $changes = json_decode($r->input('changes'), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['changes' => ['A JSON object is required.']]);
            }
            if (! $object instanceof \stdClass || array_key_exists('revision', $changes) || array_key_exists('remove_logo', $changes)) {
                throw ValidationException::withMessages(['changes' => ['Only presentation text and styles are accepted.']]);
            }
        }

        return response()->json(['data' => $this->settings->update($this->current->get(), $r->user(), ['revision' => (int) $r->input('revision')] + $changes, $r->file('logo'))]);
    }

    public function issue(Request $r, int $order)
    {
        $report = $this->service->issue($this->current->get(), $r->user(), $order, $r->all(), (string) $r->header('Idempotency-Key'));

        return response()->json(['data' => $this->service->metadata($report)], 201);
    }

    public function history(Request $r, int $order)
    {
        return response()->json($this->service->history($this->current->get(), $r->user(), $order));
    }

    public function show(Request $r, int $order, int $report)
    {
        return response()->json(['data' => $this->service->metadata($this->service->lookup($this->current->get(), $r->user(), $order, $report))]);
    }

    public function download(Request $r, int $order, int $report)
    {
        [$issuance,$bytes] = $this->service->download($this->current->get(), $r->user(), $order, $report);

        return response($bytes)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$issuance->number.'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function verify(string $token)
    {
        // Never include credentials in exception logs, audit, response links or metadata.
        try {
            $report = preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1
                ? LaboratoryReportIssuance::select(['number', 'issued_at'])->selectRaw("presentation_snapshot->>'trade_name' AS laboratory_name")->where('verification_hash', hash('sha256', $token))->first() : null;
            if ($report === null) {
                return response()->json(['message' => 'Resource not found.'], 404)->header('Cache-Control', 'no-store');
            }

            return response()->json(['data' => ['authentic' => true, 'number' => $report->number,
                'issued_at' => $report->issued_at->toISOString(), 'laboratory' => $report->laboratory_name]])
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        } catch (\Throwable) {
            Log::error('Public report authenticity lookup failed.');

            return response()->json(['message' => 'Verification temporarily unavailable.'], 503)->header('Cache-Control', 'no-store');
        }
    }
}
