<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Doctor\IndexDoctorRequest;
use App\Http\Requests\Api\V1\Doctor\StoreDoctorRequest;
use App\Http\Requests\Api\V1\Doctor\UpdateDoctorRequest;
use App\Http\Requests\Api\V1\Doctor\UpdateDoctorStatusRequest;
use App\Http\Resources\Api\V1\DoctorDetailResource;
use App\Http\Resources\Api\V1\DoctorResource;
use App\Models\Doctor;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class DoctorController extends Controller
{
    #[OA\Get(
        path: '/api/v1/doctors',
        operationId: 'doctorsIndex',
        summary: 'Listar médicos',
        description: 'Lista, busca, filtra, ordena y pagina exclusivamente los médicos del laboratorio validado por el pipeline SaaS. La búsqueda parcial case-insensitive abarca nombres, apellidos y número de colegiado. El criterio solicitado siempre usa id ascendente como desempate estable.',
        security: [['sanctumCookie' => []]],
        tags: ['Doctors'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
            new OA\Parameter(name: 'search', description: 'Búsqueda parcial case-insensitive en nombres, apellidos y número de colegiado.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 150), example: 'COL-12345'),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'specialty', description: 'Coincidencia exacta case-insensitive de la especialidad.', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 125), example: 'Cardiología'),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', default: 'last_names', enum: ['first_names', 'last_names', 'specialty', 'created_at'])),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Listado paginado de médicos.', content: new OA\JsonContent(ref: '#/components/schemas/DoctorListResponse')),
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
        IndexDoctorRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): AnonymousResourceCollection {
        $query = Doctor::forLaboratory($currentLaboratory->get());

        if (($search = $request->search()) !== null) {
            $query->where(function (Builder $query) use ($search): void {
                $pattern = '%'.mb_strtolower($search).'%';

                $query
                    ->whereLike('first_names', $pattern)
                    ->orWhereLike('last_names', $pattern)
                    ->orWhereLike('license_number', $pattern);
            });
        }

        if (($status = $request->status()) !== null) {
            $query->where('status', $status);
        }

        if (($specialty = $request->specialty()) !== null) {
            $query->whereLike('specialty', mb_strtolower($specialty));
        }

        $doctors = $query
            ->orderBy($request->sort(), $request->direction())
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return DoctorResource::collection($doctors);
    }

    #[OA\Post(
        path: '/api/v1/doctors',
        operationId: 'doctorsStore',
        summary: 'Crear médico',
        description: 'Crea un médico activo en el laboratorio validado por el pipeline SaaS. El ownership y el estado inicial son controlados exclusivamente por el servidor.',
        security: [['sanctumCookie' => []]],
        tags: ['Doctors'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreDoctorInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Médico creado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/DoctorResponse')),
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
            new OA\Response(response: 422, description: 'Los datos del médico no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function store(
        StoreDoctorRequest $request,
        CurrentLaboratory $currentLaboratory,
    ): JsonResponse {
        $doctor = $currentLaboratory->get()
            ->doctors()
            ->create($request->validated())
            ->refresh();

        return DoctorResource::make($doctor)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/doctors/{doctor}',
        operationId: 'doctorsShow',
        summary: 'Consultar médico',
        description: 'Devuelve el detalle completo del médico únicamente cuando pertenece al laboratorio validado por el pipeline SaaS.',
        security: [['sanctumCookie' => []]],
        tags: ['Doctors'],
        parameters: [
            new OA\Parameter(
                name: 'doctor',
                description: 'Identificador del médico dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalle del médico.', content: new OA\JsonContent(ref: '#/components/schemas/DoctorDetailResponse')),
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
            new OA\Response(response: 404, description: 'El médico no fue encontrado en el contexto del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ],
    )]
    public function show(
        CurrentLaboratory $currentLaboratory,
        int $doctor,
    ): DoctorDetailResource {
        $doctor = $this->findDoctor($currentLaboratory, $doctor);

        return DoctorDetailResource::make($doctor);
    }

    #[OA\Patch(
        path: '/api/v1/doctors/{doctor}',
        operationId: 'doctorsUpdate',
        summary: 'Actualizar médico',
        description: 'Actualiza parcialmente uno o más campos editables del médico dentro del laboratorio actual. Debe enviarse al menos un campo editable; ownership y status no pueden modificarse aquí.',
        security: [['sanctumCookie' => []]],
        tags: ['Doctors'],
        parameters: [
            new OA\Parameter(
                name: 'doctor',
                description: 'Identificador del médico dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Debe contener al menos uno de los campos editables documentados.',
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateDoctorInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Médico actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/DoctorDetailResponse')),
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
            new OA\Response(response: 404, description: 'El médico no fue encontrado en el contexto del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Los campos enviados no son válidos o el PATCH está vacío.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function update(
        UpdateDoctorRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $doctor,
    ): DoctorDetailResource {
        $doctor = $this->findDoctor($currentLaboratory, $doctor);

        $doctor->update($request->validated());

        return DoctorDetailResource::make($doctor->refresh());
    }

    #[OA\Patch(
        path: '/api/v1/doctors/{doctor}/status',
        operationId: 'doctorsUpdateStatus',
        summary: 'Cambiar estado del médico',
        description: 'Cambia únicamente el estado del médico dentro del laboratorio actual. Los únicos estados válidos son active e inactive.',
        security: [['sanctumCookie' => []]],
        tags: ['Doctors'],
        parameters: [
            new OA\Parameter(
                name: 'doctor',
                description: 'Identificador del médico dentro del laboratorio actual.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64', minimum: 1),
            ),
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateDoctorStatusInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estado del médico actualizado correctamente.', content: new OA\JsonContent(ref: '#/components/schemas/DoctorDetailResponse')),
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
            new OA\Response(response: 404, description: 'El médico no fue encontrado en el contexto del laboratorio actual.', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'El estado no es válido o el payload contiene otros campos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function updateStatus(
        UpdateDoctorStatusRequest $request,
        CurrentLaboratory $currentLaboratory,
        int $doctor,
    ): DoctorDetailResource {
        $doctor = $this->findDoctor($currentLaboratory, $doctor);

        $doctor->update([
            'status' => $request->validated('status'),
        ]);

        return DoctorDetailResource::make($doctor->refresh());
    }

    private function findDoctor(CurrentLaboratory $currentLaboratory, int $doctor): Doctor
    {
        $resolvedDoctor = Doctor::forLaboratory($currentLaboratory->get())
            ->find($doctor);

        if ($resolvedDoctor === null) {
            abort(404, 'Resource not found.');
        }

        return $resolvedDoctor;
    }
}
