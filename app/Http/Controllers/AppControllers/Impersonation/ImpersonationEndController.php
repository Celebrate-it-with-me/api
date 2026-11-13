<?php

namespace App\Http\Controllers\AppControllers\Impersonation;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Impersonation\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationEndController extends Controller
{
    /** POST impersonation/end: the banner's "Salir". Only reachable with an impersonation token (ImpersonationGuard sets the session). */
    public function __invoke(Request $request, ImpersonationService $service): JsonResponse
    {
        $session = $request->attributes->get('impersonation_session');

        if (! $session) {
            return response()->json(['message' => 'No hay una sesión de soporte activa.', 'code' => 'not_impersonating'], 409);
        }

        $service->finish($session, 'staff');

        return response()->json(['message' => 'Sesión de soporte terminada.', 'sessionId' => $session->id]);
    }
}
