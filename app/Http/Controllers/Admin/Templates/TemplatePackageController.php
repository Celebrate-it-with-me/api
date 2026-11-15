<?php

namespace App\Http\Controllers\Admin\Templates;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Templates\Packages\InstallRequest;
use App\Http\Requests\Admin\Templates\Packages\ListRequest;
use App\Http\Requests\Admin\Templates\Packages\ReasonRequest;
use App\Http\Requests\Admin\Templates\Packages\UploadRequest;
use App\Http\Services\Admin\Templates\Packages\PackageInstaller;
use App\Http\Services\Admin\Templates\Packages\PackageLifecycle;
use App\Http\Services\Admin\Templates\Packages\PackageQuery;
use App\Http\Services\Admin\Templates\Packages\PackageStage;
use Illuminate\Http\JsonResponse;

/** Installable template packages (docs/plans/template-package-spec.md, section 7.2). */
class TemplatePackageController extends Controller
{
    public function __construct(
        private readonly PackageQuery $query,
        private readonly PackageStage $stage,
        private readonly PackageInstaller $installer,
        private readonly PackageLifecycle $lifecycle,
    ) {}

    public function index(ListRequest $request): JsonResponse
    {
        return response()->json($this->query->list($request->validated()));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->query->show($id)]);
    }

    /** Defects of the zip's content are the answer (200 with `report`), not an error of the request (D-1). */
    public function validateUpload(UploadRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->stage->validate($request->file('package'))]);
    }

    public function discardStage(string $token): JsonResponse
    {
        $this->stage->load($token);
        $this->stage->discard($token);

        return response()->json(['data' => null]);
    }

    public function install(InstallRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->installer->install($data['stage_token'], $data['reason'])], 201);
    }

    public function impact(int $id): JsonResponse
    {
        $action = request()->validate(['action' => ['required', 'in:deactivate,withdraw']], ['action.required' => 'required', 'action.in' => 'invalid'])['action'];

        return response()->json(['data' => ['action' => $action] + $this->query->impact($id)]);
    }

    public function activate(ReasonRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->lifecycle->activate($id, $data['reason'], (bool) ($data['ack_third_party_marks'] ?? false))]);
    }

    public function deactivate(ReasonRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->lifecycle->deactivate($id, $request->validated('reason'))]);
    }

    public function destroy(ReasonRequest $request, int $id): JsonResponse
    {
        $this->lifecycle->withdraw($id, $request->validated('reason'));

        return response()->json(['data' => null]);
    }
}
