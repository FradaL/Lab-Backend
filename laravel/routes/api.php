<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\AvailableLaboratoryController;
use App\Http\Controllers\Api\V1\Auth\CurrentAuthorizationController;
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
        Route::get('/auth/authorization', CurrentAuthorizationController::class);
        Route::get('/branches/active', [BranchController::class, 'active'])
            ->middleware('can:branches.view');
        Route::get('/laboratory-orders', [LaboratoryOrderController::class, 'index'])
            ->middleware('can:orders.view');
        Route::post('/laboratory-orders', [LaboratoryOrderController::class, 'store'])
            ->middleware('can:orders.create');
        Route::get('/laboratory-orders/{laboratoryOrder}/exams', [LaboratoryOrderController::class, 'listExams'])
            ->middleware('can:orders.view')
            ->whereNumber('laboratoryOrder');
        Route::post('/laboratory-orders/{laboratoryOrder}/exams', [LaboratoryOrderController::class, 'addExam'])
            ->middleware('can:orders.add_exam')
            ->whereNumber('laboratoryOrder');
        Route::delete('/laboratory-orders/{laboratoryOrder}/exams/{laboratoryOrderExam}', [LaboratoryOrderController::class, 'removeExam'])
            ->middleware('can:orders.remove_exam')
            ->whereNumber(['laboratoryOrder', 'laboratoryOrderExam']);
        Route::put('/laboratory-orders/{laboratoryOrder}/discount', [LaboratoryOrderController::class, 'updateDiscount'])
            ->middleware('can:orders.manage_discount')
            ->whereNumber('laboratoryOrder');
        Route::delete('/laboratory-orders/{laboratoryOrder}/discount', [LaboratoryOrderController::class, 'removeDiscount'])
            ->middleware('can:orders.manage_discount')
            ->whereNumber('laboratoryOrder');
        Route::patch('/laboratory-orders/{laboratoryOrder}/status', [LaboratoryOrderController::class, 'updateStatus'])
            ->middleware('can:orders.change_status')
            ->whereNumber('laboratoryOrder');
        Route::get('/laboratory-orders/{laboratoryOrder}', [LaboratoryOrderController::class, 'show'])
            ->middleware('can:orders.view')
            ->whereNumber('laboratoryOrder');
        Route::post('/pricing/resolve-price-list', [PricingResolutionController::class, 'resolvePriceList'])
            ->middleware('can:pricing.resolve');
        Route::get('/commercial-clients', [CommercialClientController::class, 'index'])
            ->middleware('can:commercial_clients.view');
        Route::post('/commercial-clients', [CommercialClientController::class, 'store'])
            ->middleware('can:commercial_clients.create');
        Route::get('/commercial-clients/active', [CommercialClientController::class, 'active'])
            ->middleware('can:commercial_clients.view');
        Route::get('/commercial-clients/{commercialClient}/price-list-assignments', [CommercialClientPriceListController::class, 'index'])
            ->middleware('can:commercial_price_assignments.view')
            ->whereNumber('commercialClient');
        Route::post('/commercial-clients/{commercialClient}/price-list-assignments', [CommercialClientPriceListController::class, 'store'])
            ->middleware('can:commercial_price_assignments.manage')
            ->whereNumber('commercialClient');
        Route::patch('/commercial-clients/{commercialClient}/price-list-assignments/{assignment}', [CommercialClientPriceListController::class, 'update'])
            ->middleware('can:commercial_price_assignments.manage')
            ->whereNumber(['commercialClient', 'assignment']);
        Route::patch('/commercial-clients/{commercialClient}/price-list-assignments/{assignment}/status', [CommercialClientPriceListController::class, 'updateStatus'])
            ->middleware('can:commercial_price_assignments.manage')
            ->whereNumber(['commercialClient', 'assignment']);
        Route::get('/commercial-clients/{commercialClient}', [CommercialClientController::class, 'show'])
            ->middleware('can:commercial_clients.view')
            ->whereNumber('commercialClient');
        Route::patch('/commercial-clients/{commercialClient}', [CommercialClientController::class, 'update'])
            ->middleware('can:commercial_clients.update')
            ->whereNumber('commercialClient');
        Route::patch('/commercial-clients/{commercialClient}/status', [CommercialClientController::class, 'updateStatus'])
            ->middleware('can:commercial_clients.change_status')
            ->whereNumber('commercialClient');
        Route::get('/price-lists', [PriceListController::class, 'index'])
            ->middleware('can:price_lists.view');
        Route::post('/price-lists', [PriceListController::class, 'store'])
            ->middleware('can:price_lists.create');
        Route::get('/price-lists/active', [PriceListController::class, 'active'])
            ->middleware('can:price_lists.view');
        Route::get('/price-lists/{priceList}/available-exams', [PriceListExamController::class, 'availableExams'])
            ->middleware('can:exam_prices.view')
            ->whereNumber('priceList');
        Route::get('/price-lists/{priceList}/exams', [PriceListExamController::class, 'index'])
            ->middleware('can:exam_prices.view')
            ->whereNumber('priceList');
        Route::put('/price-lists/{priceList}/exams/bulk', [PriceListExamController::class, 'bulkUpsert'])
            ->middleware('can:exam_prices.manage')
            ->whereNumber('priceList');
        Route::put('/price-lists/{priceList}/exams/{laboratoryExam}', [PriceListExamController::class, 'upsert'])
            ->middleware('can:exam_prices.manage')
            ->whereNumber(['priceList', 'laboratoryExam']);
        Route::patch('/price-lists/{priceList}/exams/{laboratoryExam}/status', [PriceListExamController::class, 'updateStatus'])
            ->middleware('can:exam_prices.manage')
            ->whereNumber(['priceList', 'laboratoryExam']);
        Route::get('/price-lists/{priceList}', [PriceListController::class, 'show'])
            ->middleware('can:price_lists.view')
            ->whereNumber('priceList');

        Route::patch('/price-lists/{priceList}', [PriceListController::class, 'update'])
            ->middleware('can:price_lists.update')
            ->whereNumber('priceList');
        Route::patch('/price-lists/{priceList}/status', [PriceListController::class, 'updateStatus'])
            ->middleware('can:price_lists.change_status')
            ->whereNumber('priceList');
        Route::patch('/price-lists/{priceList}/default', [PriceListController::class, 'setDefault'])
            ->middleware('can:price_lists.set_default')
            ->whereNumber('priceList');
        Route::get('/laboratory-exams', [LaboratoryExamController::class, 'index'])
            ->middleware('can:laboratory_exams.view');
        Route::post('/laboratory-exams', [LaboratoryExamController::class, 'store'])
            ->middleware('can:laboratory_exams.create');
        Route::get('/laboratory-exams/active', [LaboratoryExamController::class, 'active'])
            ->middleware('can:laboratory_exams.view');
        Route::get('/laboratory-exams/{laboratoryExam}', [LaboratoryExamController::class, 'show'])
            ->middleware('can:laboratory_exams.view')
            ->whereNumber('laboratoryExam');
        Route::patch('/laboratory-exams/{laboratoryExam}', [LaboratoryExamController::class, 'update'])
            ->middleware('can:laboratory_exams.update')
            ->whereNumber('laboratoryExam');
        Route::patch('/laboratory-exams/{laboratoryExam}/status', [LaboratoryExamController::class, 'updateStatus'])
            ->middleware('can:laboratory_exams.change_status')
            ->whereNumber('laboratoryExam');
        Route::get('/sample-types', [SampleTypeController::class, 'index'])
            ->middleware('can:sample_types.view');
        Route::post('/sample-types', [SampleTypeController::class, 'store'])
            ->middleware('can:sample_types.create');
        Route::get('/sample-types/active', [SampleTypeController::class, 'active'])
            ->middleware('can:sample_types.view');
        Route::get('/sample-types/{sampleType}', [SampleTypeController::class, 'show'])
            ->middleware('can:sample_types.view')
            ->whereNumber('sampleType');
        Route::patch('/sample-types/{sampleType}', [SampleTypeController::class, 'update'])
            ->middleware('can:sample_types.update')
            ->whereNumber('sampleType');
        Route::patch('/sample-types/{sampleType}/status', [SampleTypeController::class, 'updateStatus'])
            ->middleware('can:sample_types.change_status')
            ->whereNumber('sampleType');
        Route::get('/laboratory-areas', [LaboratoryAreaController::class, 'index'])
            ->middleware('can:laboratory_areas.view');
        Route::post('/laboratory-areas', [LaboratoryAreaController::class, 'store'])
            ->middleware('can:laboratory_areas.create');
        Route::get('/laboratory-areas/active', [LaboratoryAreaController::class, 'active'])
            ->middleware('can:laboratory_areas.view');
        Route::get('/laboratory-areas/{area}', [LaboratoryAreaController::class, 'show'])
            ->middleware('can:laboratory_areas.view')
            ->whereNumber('area');
        Route::patch('/laboratory-areas/{area}', [LaboratoryAreaController::class, 'update'])
            ->middleware('can:laboratory_areas.update')
            ->whereNumber('area');
        Route::patch('/laboratory-areas/{area}/status', [LaboratoryAreaController::class, 'updateStatus'])
            ->middleware('can:laboratory_areas.change_status')
            ->whereNumber('area');
        Route::get('/doctors', [DoctorController::class, 'index'])
            ->middleware('can:doctors.view');
        Route::post('/doctors', [DoctorController::class, 'store'])
            ->middleware('can:doctors.create');
        Route::get('/doctors/{doctor}', [DoctorController::class, 'show'])
            ->middleware('can:doctors.view')
            ->whereNumber('doctor');
        Route::patch('/doctors/{doctor}', [DoctorController::class, 'update'])
            ->middleware('can:doctors.update')
            ->whereNumber('doctor');
        Route::patch('/doctors/{doctor}/status', [DoctorController::class, 'updateStatus'])
            ->middleware('can:doctors.change_status')
            ->whereNumber('doctor');
        Route::get('/patients', [PatientController::class, 'index'])
            ->middleware('can:patients.view');
        Route::post('/patients', [PatientController::class, 'store'])
            ->middleware('can:patients.create');
        Route::get('/patients/{patient}', [PatientController::class, 'show'])
            ->middleware('can:patients.view')
            ->whereNumber('patient');
        Route::patch('/patients/{patient}', [PatientController::class, 'update'])
            ->middleware('can:patients.update')
            ->whereNumber('patient');
        Route::patch('/patients/{patient}/status', [PatientController::class, 'updateStatus'])
            ->middleware('can:patients.change_status')
            ->whereNumber('patient');
    });
});
