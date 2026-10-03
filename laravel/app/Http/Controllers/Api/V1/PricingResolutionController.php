<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Pricing\ResolvePriceListRequest;
use App\Http\Resources\Api\V1\PriceListResolutionResource;
use App\Models\CommercialClient;
use App\Services\Pricing\EffectivePriceListResolver;
use App\Tenancy\CurrentLaboratory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

final class PricingResolutionController extends Controller
{
    #[OA\Post(
        path: '/api/v1/pricing/resolve-price-list',
        operationId: 'pricingResolvePriceList',
        summary: 'Resolver la lista de precios efectiva',
        description: 'Sugiere, sin persistir, la lista de precios aplicable para Particular o para una entidad comercial en una fecha explícita. El contrato 200 representa resultados resueltos y no resueltos; una cardinalidad ambigua se considera una violación interna y nunca se desempata silenciosamente.',
        security: [['sanctumCookie' => []]],
        tags: ['Commercial Client Price List Assignments'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LaboratoryContextHeader'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ResolvePriceListInput'),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resolución estable para Particular o entidad comercial, tanto resuelta como no resuelta.',
                content: new OA\JsonContent(ref: '#/components/schemas/PriceListResolutionResponse'),
            ),
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
            new OA\Response(response: 422, description: 'El payload o la entidad comercial seleccionada no son válidos.', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ],
    )]
    public function resolvePriceList(
        Request $request,
        CurrentLaboratory $currentLaboratory,
        ResolvePriceListRequest $resolveRequest,
        EffectivePriceListResolver $resolver,
    ): JsonResponse {
        $laboratory = $currentLaboratory->get();
        $attributes = $resolveRequest->validated($request);
        $commercialClient = null;

        if ($attributes['commercial_client_id'] !== null) {
            $commercialClient = CommercialClient::forLaboratory($laboratory)
                ->whereKey($attributes['commercial_client_id'])
                ->first();

            if ($commercialClient === null || $commercialClient->status !== CommercialClient::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'commercial_client_id' => ['La entidad comercial seleccionada no es válida.'],
                ]);
            }
        }

        $resolution = $resolver->resolve(
            $laboratory,
            $commercialClient,
            CarbonImmutable::createFromFormat('!Y-m-d', $attributes['effective_date']),
        );

        return PriceListResolutionResource::make($resolution)->response();
    }
}
