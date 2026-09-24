<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Patient\IndexPatientRequest;
use App\Http\Requests\Api\V1\Patient\StorePatientRequest;
use App\Http\Requests\Api\V1\Patient\UpdatePatientRequest;
use App\Http\Requests\Api\V1\Patient\UpdatePatientStatusRequest;
use App\Http\Resources\Api\V1\PatientDetailResource;
use App\Http\Resources\Api\V1\PatientResource;
use App\Models\Patient;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class PatientController extends Controller
{
    #[OA\Get(
        path: '/api/v1/patients',
        operationId: 'patientsIndex',
        summary: 'Listar pacientes',
        description: 'Lista, busca, filtra, ordena y pagina exclusivamente los pacientes del laboratorio validado por el pipeline SaaS. El criterio solicitado siempre usa id ascendente como desempate estable.',
        security: [['sanctumCookie' => []]],
        tags: ['Patients'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda case-insensitive en nombres y apellidos.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 150), example: 'Daniel'),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'last_names', enum: ['first_names', 'last_names', 'birth_date', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de pacientes.', content: new OA\JsonContent(ref: '#/components/schemas/PatientListResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'),
                    new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError'),
                ]),
            ),
            new OA\Response(response: 422, description: 'Los parámetros de consulta no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function index(
        IndexPatientRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $query = Patient::forLaboratory($currentLaboratory->get());

        if (($search = $request->search()) !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $pattern = "%{$search}%";

                $query
                    ->whereLike('first_names', $pattern)
                    ->orWhereLike('last_names', $pattern);
            });
        }

        if (($status = $request->status()) !== null) {
            $query->where('status', $status);
        }

        $patients = $query
            ->orderBy($request->sort(), $request->direction())
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return PatientResource::collection($patients);
    }

    #[OA\Post(
        path: '/api/v1/patients',
        operationId: 'patientsStore',
        summary: 'Crear paciente',
        description: 'Crea un paciente activo en el laboratorio validado por el pipeline SaaS. El ownership y el estado inicial son controlados exclusivamente por el servidor.',
        security: [['sanctumCookie' => []]],
        tags: ['Patients'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StorePatientInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Paciente creado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/PatientResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'),
                    new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError'),
                ]),
            ),
            new OA\Response(response: 422, description: 'Los datos del paciente no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        StorePatientRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): JsonResponse {
        $patient = $currentLaboratory->get()
            ->patients()
            ->create($request->validated())
            ->refresh();

        return PatientResource::make($patient)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/patients/{patient}',
        operationId: 'patientsShow',
        summary: 'Consultar paciente',
        description: 'Devuelve el detalle completo del paciente únicamente cuando pertenece al laboratorio validado por el pipeline SaaS.',
        security: [['sanctumCookie' => []]],
        tags: ['Patients'],
        parameters: [
            new OA\Parameter(
                name: 'patient',
                description: 'Identificador del paciente dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del paciente.', content: new OA\JsonContent(ref: '#/components/schemas/PatientDetailResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'),
                    new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError'),
                ]),
            ),
            new OA\Response(response: 404, description: 'El paciente no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function show(
        CurrentLaboratory $currentLaboratory,
        int $patient,
    ): PatientDetailResource {
        $patient = Patient::forLaboratory($currentLaboratory->get())
            ->findOrFail($patient);

        return PatientDetailResource::make($patient);
    }

    #[OA\Patch(
        path: '/api/v1/patients/{patient}',
        operationId: 'patientsUpdate',
        summary: 'Actualizar paciente',
        description: 'Actualiza parcialmente uno o más campos editables del paciente dentro del laboratorio actual. Debe enviarse al menos un campo editable; ownership y status no pueden modificarse aquí.',
        security: [['sanctumCookie' => []]],
        tags: ['Patients'],
        parameters: [
            new OA\Parameter(
                name: 'patient',
                description: 'Identificador del paciente dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Debe contener al menos uno de los campos editables documentados.',
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePatientInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Paciente actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/PatientDetailResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'),
                    new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError'),
                ]),
            ),
            new OA\Response(response: 404, description: 'El paciente no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Los campos enviados no son válidos o el PATCH está vacío.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function update(
        UpdatePatientRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $patient,
    ): PatientDetailResource {
        $patient = Patient::forLaboratory($currentLaboratory->get())
            ->findOrFail($patient);

        $patient->update($request->validated());

        return PatientDetailResource::make($patient->refresh());
    }

    #[OA\Patch(
        path: '/api/v1/patients/{patient}/status',
        operationId: 'patientsUpdateStatus',
        summary: 'Cambiar estado del paciente',
        description: 'Cambia únicamente el estado del paciente dentro del laboratorio actual. Los únicos estados válidos son active e inactive.',
        security: [['sanctumCookie' => []]],
        tags: ['Patients'],
        parameters: [
            new OA\Parameter(
                name: 'patient',
                description: 'Identificador del paciente dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePatientStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado del paciente actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/PatientDetailResponse')),
            new OA\Response(ref: '#/components/responses/LaboratoryContextRequired', response: 400),
            new OA\Response(response: 401, description: 'La solicitud no tiene una sesión autenticada.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(
                response: 403,
                description: 'El usuario no tiene acceso al laboratorio o el laboratorio no tiene acceso vigente al SaaS.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/LaboratoryContextError'),
                    new OA\Schema(ref: '#/components/schemas/SubscriptionAccessError'),
                ]),
            ),
            new OA\Response(response: 404, description: 'El paciente no existe dentro del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El estado no es válido o el payload contiene otros campos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        UpdatePatientStatusRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $patient,
    ): PatientDetailResource {
        $patient = Patient::forLaboratory($currentLaboratory->get())
            ->findOrFail($patient);

        $patient->update($request->validated());

        return PatientDetailResource::make($patient->refresh());
    }
}
