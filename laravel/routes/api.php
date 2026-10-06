<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\AvailableLaboratoryController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CommercialClientController;
use App\Http\Controllers\Api\V1\CommercialClientPriceListController;
use App\Http\Controllers\Api\V1\DoctorController;
use App\Http\Controllers\Api\V1\LaboratoryAreaController;
use App\Http\Controllers\Api\V1\LaboratoryExamController;
use App\Http\Controllers\Api\V1\LaboratoryOrderController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PriceListController;
use App\Http\Controllers\Api\V1\PriceListExamController;
use App\Http\Controllers\Api\V1\PricingResolutionController;
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
        Route::get('/branches/active', [BranchController::class, 'active']);
        Route::post('/laboratory-orders', [LaboratoryOrderController::class, 'store']);
        Route::get('/laboratory-orders/{laboratoryOrder}/exams', [LaboratoryOrderController::class, 'listExams'])
            ->whereNumber('laboratoryOrder');
        Route::post('/laboratory-orders/{laboratoryOrder}/exams', [LaboratoryOrderController::class, 'addExam'])
            ->whereNumber('laboratoryOrder');
        Route::delete('/laboratory-orders/{laboratoryOrder}/exams/{laboratoryOrderExam}', [LaboratoryOrderController::class, 'removeExam'])
            ->whereNumber(['laboratoryOrder', 'laboratoryOrderExam']);
        Route::put('/laboratory-orders/{laboratoryOrder}/discount', [LaboratoryOrderController::class, 'updateDiscount'])
            ->whereNumber('laboratoryOrder');
        Route::delete('/laboratory-orders/{laboratoryOrder}/discount', [LaboratoryOrderController::class, 'removeDiscount'])
            ->whereNumber('laboratoryOrder');
        Route::patch('/laboratory-orders/{laboratoryOrder}/status', [LaboratoryOrderController::class, 'updateStatus'])
            ->whereNumber('laboratoryOrder');
        Route::get('/laboratory-orders/{laboratoryOrder}', [LaboratoryOrderController::class, 'show'])
            ->whereNumber('laboratoryOrder');
        Route::post('/pricing/resolve-price-list', [PricingResolutionController::class, 'resolvePriceList']);
        Route::get('/commercial-clients', [CommercialClientController::class, 'index']);
        Route::post('/commercial-clients', [CommercialClientController::class, 'store']);
        Route::get('/commercial-clients/active', [CommercialClientController::class, 'active']);
        Route::get('/commercial-clients/{commercialClient}/price-list-assignments', [CommercialClientPriceListController::class, 'index'])
            ->whereNumber('commercialClient');
        Route::post('/commercial-clients/{commercialClient}/price-list-assignments', [CommercialClientPriceListController::class, 'store'])
            ->whereNumber('commercialClient');
        Route::patch('/commercial-clients/{commercialClient}/price-list-assignments/{assignment}', [CommercialClientPriceListController::class, 'update'])
            ->whereNumber(['commercialClient', 'assignment']);
        Route::patch('/commercial-clients/{commercialClient}/price-list-assignments/{assignment}/status', [CommercialClientPriceListController::class, 'updateStatus'])
            ->whereNumber(['commercialClient', 'assignment']);
        Route::get('/commercial-clients/{commercialClient}', [CommercialClientController::class, 'show'])
            ->whereNumber('commercialClient');
        Route::patch('/commercial-clients/{commercialClient}', [CommercialClientController::class, 'update'])
            ->whereNumber('commercialClient');
        Route::patch('/commercial-clients/{commercialClient}/status', [CommercialClientController::class, 'updateStatus'])
            ->whereNumber('commercialClient');
        Route::get('/price-lists', [PriceListController::class, 'index']);
        Route::post('/price-lists', [PriceListController::class, 'store']);
        Route::get('/price-lists/active', [PriceListController::class, 'active']);
        Route::get('/price-lists/{priceList}/available-exams', [PriceListExamController::class, 'availableExams'])
            ->whereNumber('priceList');
        Route::get('/price-lists/{priceList}/exams', [PriceListExamController::class, 'index'])
            ->whereNumber('priceList');
        Route::put('/price-lists/{priceList}/exams/bulk', [PriceListExamController::class, 'bulkUpsert'])
            ->whereNumber('priceList');
        Route::put('/price-lists/{priceList}/exams/{laboratoryExam}', [PriceListExamController::class, 'upsert'])
            ->whereNumber(['priceList', 'laboratoryExam']);
        Route::patch('/price-lists/{priceList}/exams/{laboratoryExam}/status', [PriceListExamController::class, 'updateStatus'])
            ->whereNumber(['priceList', 'laboratoryExam']);
        Route::get('/price-lists/{priceList}', [PriceListController::class, 'show'])
            ->whereNumber('priceList');

        Route::patch('/price-lists/{priceList}', [PriceListController::class, 'update'])
            ->whereNumber('priceList');
        Route::patch('/price-lists/{priceList}/status', [PriceListController::class, 'updateStatus'])
            ->whereNumber('priceList');
        Route::patch('/price-lists/{priceList}/default', [PriceListController::class, 'setDefault'])
            ->whereNumber('priceList');
        Route::get('/laboratory-exams', [LaboratoryExamController::class, 'index']);
        Route::post('/laboratory-exams', [LaboratoryExamController::class, 'store']);
        Route::get('/laboratory-exams/active', [LaboratoryExamController::class, 'active']);
        Route::get('/laboratory-exams/{laboratoryExam}', [LaboratoryExamController::class, 'show'])
            ->whereNumber('laboratoryExam');
        Route::patch('/laboratory-exams/{laboratoryExam}', [LaboratoryExamController::class, 'update'])
            ->whereNumber('laboratoryExam');
        Route::patch('/laboratory-exams/{laboratoryExam}/status', [LaboratoryExamController::class, 'updateStatus'])
            ->whereNumber('laboratoryExam');
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
