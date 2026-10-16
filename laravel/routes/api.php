<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\AvailableLaboratoryController;
use App\Http\Controllers\Api\V1\Auth\CurrentAuthorizationController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\ClinicalExamController;
use App\Http\Controllers\Api\V1\ClinicalFinalizationController;
use App\Http\Controllers\Api\V1\ClinicalReportController;
use App\Http\Controllers\Api\V1\CommercialClientController;
use App\Http\Controllers\Api\V1\CommercialClientPriceListController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DoctorController;
use App\Http\Controllers\Api\V1\LaboratoryAreaController;
use App\Http\Controllers\Api\V1\LaboratoryExamController;
use App\Http\Controllers\Api\V1\LaboratoryOrderController;
use App\Http\Controllers\Api\V1\LaboratoryResultController;
use App\Http\Controllers\Api\V1\LaboratoryRoleController;
use App\Http\Controllers\Api\V1\LaboratorySampleController;
use App\Http\Controllers\Api\V1\LaboratoryUserController;
use App\Http\Controllers\Api\V1\LaboratoryUserInvitationController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PriceListController;
use App\Http\Controllers\Api\V1\PriceListExamController;
use App\Http\Controllers\Api\V1\PricingResolutionController;
use App\Http\Controllers\Api\V1\ReportDeliveryController;
use App\Http\Controllers\Api\V1\ResultValidationController;
use App\Http\Controllers\Api\V1\SampleTypeController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthCheckController::class);
    Route::get('/report-verifications/{token}', [ClinicalReportController::class, 'verify'])->middleware('throttle:report-verification');
    Route::post('/laboratory-user-invitations/{token}/accept', LaboratoryUserInvitationController::class)
        ->middleware('throttle:6,1');

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me'])->middleware('user.active');
            Route::get('/laboratories', AvailableLaboratoryController::class)->middleware('user.active');
        });
    });

    Route::middleware('saas')->group(function (): void {
        Route::get('/laboratory/users', [LaboratoryUserController::class, 'index'])->middleware('can:users.view');
        Route::post('/laboratory/users', [LaboratoryUserController::class, 'store'])->middleware('can:users.create');
        Route::get('/laboratory/users/{user}', [LaboratoryUserController::class, 'show'])->middleware('can:users.view')->whereNumber('user');
        Route::patch('/laboratory/users/{user}/role', [LaboratoryUserController::class, 'updateRole'])->middleware('can:users.assign_role')->whereNumber('user');
        Route::patch('/laboratory/users/{user}/status', [LaboratoryUserController::class, 'updateStatus'])->middleware('can:users.change_status')->whereNumber('user');
        Route::get('/laboratory/roles', LaboratoryRoleController::class)->middleware('can:roles.view');
        Route::get('/laboratory/report-settings', [ClinicalReportController::class, 'settings'])->middleware('can:reports.settings.view');
        Route::put('/laboratory/report-settings', [ClinicalReportController::class, 'updateSettings'])->middleware('can:reports.settings.edit');
        Route::post('/laboratory/report-settings/logo', [ClinicalReportController::class, 'logo'])->middleware('can:reports.settings.edit');
        Route::post('/laboratory-orders/{order}/reports', [ClinicalReportController::class, 'issue'])->middleware('can:reports.issue')->whereNumber('order');
        Route::get('/laboratory-orders/{order}/reports', [ClinicalReportController::class, 'history'])->middleware('can:reports.view_history')->whereNumber('order');
        Route::get('/laboratory-orders/{order}/reports/{report}', [ClinicalReportController::class, 'show'])->middleware('can:reports.view_history')->whereNumber(['order', 'report']);
        Route::get('/laboratory-orders/{order}/reports/{report}/download', [ClinicalReportController::class, 'download'])->middleware('can:reports.download')->whereNumber(['order', 'report']);
        Route::get('/laboratory/report-delivery-policy', [ReportDeliveryController::class, 'policy'])->middleware('can:reports.delivery_policy.view');
        Route::put('/laboratory/report-delivery-policy', [ReportDeliveryController::class, 'updatePolicy'])->middleware('can:reports.delivery_policy.manage');
        Route::get('/laboratory-orders/{order}/reports/{report}/delivery-eligibility', [ReportDeliveryController::class, 'eligibility'])->middleware('can:reports.delivery.evaluate')->whereNumber(['order', 'report']);
        Route::post('/laboratory-orders/{order}/reports/{report}/delivery-exceptions', [ReportDeliveryController::class, 'requestException'])->middleware('can:reports.delivery.prepare')->whereNumber(['order', 'report']);
        Route::get('/laboratory-orders/{order}/reports/{report}/delivery-exceptions', [ReportDeliveryController::class, 'exceptions'])->middleware('can:reports.delivery_exceptions.view')->whereNumber(['order', 'report']);
        Route::post('/laboratory-orders/{order}/reports/{report}/delivery-exceptions/{exception}/authorize', [ReportDeliveryController::class, 'authorizeException'])->middleware('can:reports.delivery_exceptions.authorize')->whereNumber(['order', 'report', 'exception']);
        Route::post('/laboratory-orders/{order}/reports/{report}/delivery-exceptions/{exception}/revoke', [ReportDeliveryController::class, 'revokeException'])->middleware('can:reports.delivery_exceptions.authorize')->whereNumber(['order', 'report', 'exception']);
        Route::post('/laboratory-orders/{order}/reports/{report}/delivery-preparations', [ReportDeliveryController::class, 'prepare'])->middleware('can:reports.delivery.prepare')->whereNumber(['order', 'report']);
        Route::get('/laboratory-orders/{order}/reports/{report}/delivery-preparations/{preparation}/download', [ReportDeliveryController::class, 'downloadPreparation'])->middleware('can:reports.delivery.prepare')->whereNumber(['order', 'report', 'preparation']);
        Route::post('/laboratory-orders/{order}/reports/{report}/deliveries', [ReportDeliveryController::class, 'deliver'])->middleware('can:reports.delivery.confirm')->whereNumber(['order', 'report']);
        Route::get('/laboratory-orders/{order}/reports/{report}/deliveries', [ReportDeliveryController::class, 'history'])->middleware('can:reports.delivery_history.view')->whereNumber(['order', 'report']);

        Route::get('/auth/authorization', CurrentAuthorizationController::class);
        Route::get('/dashboard', DashboardController::class)
            ->middleware('can:orders.view');
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
        Route::post('/laboratory-exams/drafts', [ClinicalExamController::class, 'store'])->middleware('can:laboratory_exams.create_draft');
        Route::get('/global-exam-templates', [ClinicalExamController::class, 'templates'])->middleware('can:laboratory_exams.view');
        Route::post('/laboratory-exams/import-template', [ClinicalExamController::class, 'import'])->middleware('can:laboratory_exams.import_template');
        Route::prefix('/laboratory-exams/{laboratoryExam}')->whereNumber('laboratoryExam')->group(function (): void {
            Route::get('/versions', [ClinicalExamController::class, 'index'])->middleware('can:laboratory_exams.view');
            Route::post('/versions', [ClinicalExamController::class, 'fork'])->middleware('can:laboratory_exams.create_draft');
            Route::get('/versions/current', [ClinicalExamController::class, 'current'])->middleware('can:laboratory_exams.view');
            Route::get('/order-configuration', [ClinicalExamController::class, 'orderConfiguration'])->middleware('can:laboratory_exams.view');
            Route::get('/versions/{version}', [ClinicalExamController::class, 'show'])->middleware('can:laboratory_exams.view')->whereNumber('version');
            Route::put('/versions/{version}', [ClinicalExamController::class, 'update'])->middleware('can:laboratory_exams.edit_draft')->whereNumber('version');
            Route::post('/versions/{version}/validate-publication', [ClinicalExamController::class, 'validatePublication'])->middleware('can:laboratory_exams.publish')->whereNumber('version');
            Route::post('/versions/{version}/publish', [ClinicalExamController::class, 'publish'])->middleware('can:laboratory_exams.publish')->whereNumber('version');
        });
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
        Route::post('/laboratory-orders/{order}/exams/{line}/exceptions', [ClinicalFinalizationController::class, 'requestException'])->middleware('can:orders.clinical_exceptions.request')->whereNumber(['order', 'line']);
        Route::get('/laboratory-orders/{order}/exams/{line}/exceptions', [ClinicalFinalizationController::class, 'exceptions'])->middleware('can:orders.clinical_exceptions.view')->whereNumber(['order', 'line']);
        Route::post('/laboratory-orders/{order}/exams/{line}/exceptions/{exception}/approve', [ClinicalFinalizationController::class, 'approve'])->middleware('can:orders.clinical_exceptions.approve')->whereNumber(['order', 'line', 'exception']);
        Route::post('/laboratory-orders/{order}/exams/{line}/exceptions/{exception}/reject', [ClinicalFinalizationController::class, 'reject'])->middleware('can:orders.clinical_exceptions.approve')->whereNumber(['order', 'line', 'exception']);
        Route::post('/laboratory-orders/{order}/exams/{line}/finalize', [ClinicalFinalizationController::class, 'finalizeLine'])->middleware('can:orders.exams.clinical_finalize')->whereNumber(['order', 'line']);
        Route::post('/laboratory-orders/{order}/finalize', [ClinicalFinalizationController::class, 'finalizeOrder'])->middleware('can:orders.clinical_finalize')->whereNumber(['order']);
        Route::get('/laboratory-orders/{order}/clinical-finalization', [ClinicalFinalizationController::class, 'show'])->middleware('can:orders.clinical_finalization.view')->whereNumber(['order']);

        Route::get('/laboratory/validation-policy', [ResultValidationController::class, 'policy'])->middleware('can:results.validation_policy.manage');
        Route::put('/laboratory/validation-policy', [ResultValidationController::class, 'setPolicy'])->middleware('can:results.validation_policy.manage');
        Route::get('/laboratory/validation-professionals', [ResultValidationController::class, 'professionals'])->middleware('can:results.professionals.manage');
        Route::post('/laboratory/validation-professionals', [ResultValidationController::class, 'register'])->middleware('can:results.professionals.manage');
        Route::post('/laboratory/validation-professionals/{professional}/authorize', [ResultValidationController::class, 'authorizeProfessional'])->middleware('can:results.professionals.manage')->whereNumber('professional');
        Route::post('/laboratory/validation-professionals/{professional}/revoke', [ResultValidationController::class, 'revokeProfessional'])->middleware('can:results.professionals.manage')->whereNumber('professional');
        Route::post('/laboratory-orders/{order}/exams/{line}/results/validate', [ResultValidationController::class, 'validateResults'])->middleware('can:results.validate')->whereNumber(['order', 'line']);
        Route::post('/laboratory-orders/{order}/exams/{line}/results/rectify', [ResultValidationController::class, 'rectify'])->middleware('can:results.rectify')->whereNumber(['order', 'line']);
        Route::get('/laboratory-orders/{order}/exams/{line}/results/validations', [ResultValidationController::class, 'history'])->middleware('can:results.validation_history')->whereNumber(['order', 'line']);

        Route::get('/laboratory-orders/{order}/exams/{line}/results/history', [LaboratoryResultController::class, 'history'])
            ->middleware('can:results.view_history')->whereNumber(['order', 'line']);
        Route::get('/laboratory-orders/{order}/exams/{line}/results', [LaboratoryResultController::class, 'show'])
            ->middleware('can:results.view')->whereNumber(['order', 'line']);
        Route::put('/laboratory-orders/{order}/exams/{line}/results', [LaboratoryResultController::class, 'save'])
            ->middleware('can:results.capture')->whereNumber(['order', 'line']);
        Route::get('/laboratory-orders/{order}/samples', [LaboratorySampleController::class, 'index'])
            ->middleware('can:samples.view')->whereNumber(['order']);
        Route::post('/laboratory-orders/{order}/samples/collect', [LaboratorySampleController::class, 'collect'])
            ->middleware('can:samples.collect')->whereNumber(['order']);
        Route::post('/laboratory-orders/{order}/samples/receive', [LaboratorySampleController::class, 'receiveNew'])
            ->middleware('can:samples.receive')->whereNumber(['order']);
        Route::get('/laboratory-orders/{order}/samples/{sample}', [LaboratorySampleController::class, 'show'])
            ->middleware('can:samples.view')->whereNumber(['order', 'sample']);
        Route::post('/laboratory-orders/{order}/samples/{sample}/receive', [LaboratorySampleController::class, 'receive'])
            ->middleware('can:samples.receive')->whereNumber(['order', 'sample']);
        Route::post('/laboratory-orders/{order}/samples/{sample}/accept', [LaboratorySampleController::class, 'accept'])
            ->middleware('can:samples.evaluate')->whereNumber(['order', 'sample']);
        Route::post('/laboratory-orders/{order}/samples/{sample}/reject', [LaboratorySampleController::class, 'reject'])
            ->middleware('can:samples.evaluate')->whereNumber(['order', 'sample']);
        Route::get('/laboratory-orders/{order}/samples/{sample}/candidates', [LaboratorySampleController::class, 'candidates'])
            ->middleware('can:samples.view')->whereNumber(['order', 'sample']);
        Route::post('/laboratory-orders/{order}/samples/{sample}/associations', [LaboratorySampleController::class, 'associate'])
            ->middleware('can:samples.associate')->whereNumber(['order', 'sample']);
        Route::post('/laboratory-orders/{order}/samples/{sample}/associations/{association}/release', [LaboratorySampleController::class, 'release'])
            ->middleware('can:samples.associate')->whereNumber(['order', 'sample', 'association']);
        Route::get('/laboratory-orders/{order}/operational', [LaboratorySampleController::class, 'operational'])
            ->middleware('can:samples.view')->whereNumber(['order']);
        Route::post('/laboratory-orders/{order}/exams/{line}/start', [LaboratorySampleController::class, 'start'])
            ->middleware('can:orders.start_exam')->whereNumber(['order', 'line']);
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
