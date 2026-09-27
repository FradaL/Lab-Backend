<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\AvailableLaboratoryController;
use App\Http\Controllers\Api\V1\DoctorController;
use App\Http\Controllers\Api\V1\LaboratoryAreaController;
use App\Http\Controllers\Api\V1\LaboratoryExamController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\SampleTypeController;
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
        Route::get('/laboratory-exams', [LaboratoryExamController::class, 'index']);
        Route::get('/sample-types', [SampleTypeController::class, 'index']);
        Route::post('/sample-types', [SampleTypeController::class, 'store']);
        Route::get('/sample-types/active', [SampleTypeController::class, 'active']);
        Route::get('/sample-types/{sampleType}', [SampleTypeController::class, 'show'])
            ->whereNumber('sampleType');
        Route::patch('/sample-types/{sampleType}', [SampleTypeController::class, 'update'])
            ->whereNumber('sampleType');
        Route::patch('/sample-types/{sampleType}/status', [SampleTypeController::class, 'updateStatus'])
            ->whereNumber('sampleType');
        Route::get('/laboratory-areas', [LaboratoryAreaController::class, 'index']);
        Route::post('/laboratory-areas', [LaboratoryAreaController::class, 'store']);
        Route::get('/laboratory-areas/active', [LaboratoryAreaController::class, 'active']);
        Route::get('/laboratory-areas/{area}', [LaboratoryAreaController::class, 'show'])
            ->whereNumber('area');
        Route::patch('/laboratory-areas/{area}', [LaboratoryAreaController::class, 'update'])
            ->whereNumber('area');
        Route::patch('/laboratory-areas/{area}/status', [LaboratoryAreaController::class, 'updateStatus'])
            ->whereNumber('area');
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
