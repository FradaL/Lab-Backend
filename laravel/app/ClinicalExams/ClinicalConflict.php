<?php

namespace App\ClinicalExams;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ClinicalConflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $errors = [], public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode, 'errors' => $this->errors], $this->status);
    }
}
