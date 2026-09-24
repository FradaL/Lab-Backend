<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\AvailableLaboratoryController;
use App\Http\Controllers\Api\V1\DoctorController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthCheckController::class);

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::get('/laboratories', AvailableLaboratoryController::class);
        });
    });

    Route::middleware('saas')->group(function (): void {
        Route::get('/doctors', [DoctorController::class, 'index']);
        Route::post('/doctors', [DoctorController::class, 'store']);
        Route::get('/doctors/{doctor}', [DoctorController::class, 'show'])
            ->whereNumber('doctor');
        Route::patch('/doctors/{doctor}', [DoctorController::class, 'update'])
            ->whereNumber('doctor');
        Route::patch('/doctors/{doctor}/status', [DoctorController::class, 'updateStatus'])
            ->whereNumber('doctor');
        Route::get('/patients', [PatientController::class, 'index']);
        Route::post('/patients', [PatientController::class, 'store']);
        Route::get('/patients/{patient}', [PatientController::class, 'show'])
            ->whereNumber('patient');
        Route::patch('/patients/{patient}', [PatientController::class, 'update'])
            ->whereNumber('patient');
        Route::patch('/patients/{patient}/status', [PatientController::class, 'updateStatus'])
            ->whereNumber('patient');
    });
});
