<?php

namespace App\ClinicalReports;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PrivateReportStorage
{
    public function write(int $lab, string $pdf): string
    {
        if (! str_starts_with($pdf, '%PDF-') || ! str_contains(substr($pdf, -1024), '%%EOF')) {
            throw new RuntimeException('Invalid rendered document.');
        }
        $disk = Storage::disk('clinical_reports');
        $path = 'reports/'.$lab.'/'.bin2hex(random_bytes(32)).'.pdf';
        // Local, private disk; x mode atomically refuses overwrites even on collision.
        $directory = $disk->path('reports/'.$lab);
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
            clearstatcache(true, $directory);
        }
        if (! is_dir($directory) || ! chmod($directory, 0700)) {
            throw new RuntimeException('Private document directory creation failed.');
        }
        $handle = fopen($disk->path($path), 'xb');
        if ($handle === false) {
            throw new RuntimeException('Private document creation failed.');
        }
        try {
            if (! chmod($disk->path($path), 0600)) {
                throw new RuntimeException('Private document permission setup failed.');
            }
            $offset = 0;
            while ($offset < strlen($pdf)) {
                $written = fwrite($handle, substr($pdf, $offset));
                if (! $written) {
                    throw new RuntimeException('Private document write failed.');
                }
                $offset += $written;
            }
            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Private document flush failed.');
            }
        } catch (\Throwable $e) {
            fclose($handle);
            $disk->delete($path);
            throw $e;
        }
        fclose($handle);

        return $path;
    }

    public function read(string $path, string $hash): string
    {
        $pdf = Storage::disk('clinical_reports')->get($path);
        if (! is_string($pdf) || ! hash_equals($hash, hash('sha256', $pdf))) {
            throw new RuntimeException('Stored document integrity check failed.');
        }

        return $pdf;
    }

    public function remove(string $path): void
    {
        if (! Storage::disk('clinical_reports')->delete($path)) {
            throw new RuntimeException('Private orphan cleanup failed.');
        }
    }
}
