<?php

namespace App\Http\Controllers;

use App\Http\Services\AppServices\InvitationShare;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page link-preview crawlers read when an invitation link is shared. People never stay here: it sends
 * them to the app at once. A wrong slug, a wrong code and an unpublished event all answer the same 404.
 */
class InvitationShareController extends Controller
{
    public function show(Request $request, InvitationShare $share, string $slug, string $code): Response
    {
        $meta = $share->metadata($slug, $code, $request->query('lang'));

        abort_if($meta === null, 404);

        return response()->view('share', $meta)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
