<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Team\InvitationAcceptance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public side of the team invitations (no session; the token in the link is the credential). */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationAcceptance $acceptance) {}

    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->acceptance->preview($this->acceptance->lookup($token))]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        return response()->json($this->acceptance->accept($request, $this->acceptance->lookup($token)));
    }
}
