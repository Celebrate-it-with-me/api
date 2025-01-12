<?php

namespace App\Http\Controllers;

use App\Http\Requests\MainGuest\CreateMainGuestRequest;
use App\Http\Resources\MainGuestResource;
use App\Http\Resources\UserResource;
use App\Http\Services\MainGuestServices;
use App\Models\MainGuest;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StatusController extends Controller
{
    /**
     * Executes the callable object.
     *
     * This method attempts to establish a database connection and checks if the connected
     * database name is not 'none'. It then returns a JsonResponse containing a boolean value
     * depending on the result. If an exception occurs during the process, it returns a
     * JsonResponse with the boolean value false.
     *
     * @return JsonResponse The JsonResponse containing a boolean value.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            return response()->json(DB::connection()->getDatabaseName() !== 'none');
        } catch (Exception $e) {
            return response()->json(false);
        }
    }
}
