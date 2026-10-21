<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LaboratoryUser\AcceptLaboratoryUserInvitationRequest;
use App\Models\User;
use App\UserAdministration\LaboratoryUserManager;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class LaboratoryUserInvitationController extends Controller
{
    #[OA\Post(
        path: '/api/v1/laboratory-user-invitations/{token}/accept',
        operationId: 'laboratoryUserInvitationsAccept',
        summary: 'Aceptar una invitación de laboratorio',
        description: 'El token prueba control del correo. Si la identidad no existe, name y password son obligatorios y el correo queda verificado; si existe, se exige su sesión Sanctum y esos campos se ignoran. No usa X-Laboratory-ID porque aún no existe membresía.',
        tags: ['Laboratory users'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string', minLength: 64, maxLength: 64))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/AcceptLaboratoryUserInvitationInput')),
        responses: [
            new OA\Response(response: 201, description: 'Identidad y membresía creadas, o identidad existente vinculada con autorización verificable.'),
            new OA\Response(response: 403, description: 'La identidad global existente no está autenticada.'),
            new OA\Response(response: 409, description: 'La membresía ya existe.'),
            new OA\Response(response: 410, description: 'Invitación inválida, expirada, revocada o consumida.'),
            new OA\Response(response: 422, description: 'Una identidad nueva requiere nombre y contraseña segura.'),
            new OA\Response(response: 429, description: 'Demasiados intentos.'),
        ],
    )]
    public function __invoke(AcceptLaboratoryUserInvitationRequest $request, LaboratoryUserManager $manager, string $token): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, Response::HTTP_NOT_FOUND);

        /** @var User|null $authenticatedUser */
        $authenticatedUser = $request->user();
        $manager->accept($token, $request->safe()->only(['name', 'password']), $authenticatedUser);

        return response()->json([
            'message' => 'Invitación aceptada. La membresía ya está disponible.',
        ], Response::HTTP_CREATED);
    }
}
