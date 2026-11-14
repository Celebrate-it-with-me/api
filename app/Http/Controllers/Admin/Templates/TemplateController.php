<?php

namespace App\Http\Controllers\Admin\Templates;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Templates\PreviewRequest;
use App\Http\Requests\Admin\Templates\ReasonRequest;
use App\Http\Requests\Admin\Templates\UpdateVersionRequest;
use App\Http\Services\Admin\Templates\TemplatePreview;
use App\Http\Services\Admin\Templates\TemplateSchema;
use App\Http\Services\Admin\Templates\TemplateService;
use Illuminate\Http\JsonResponse;

class TemplateController extends Controller
{
    public function __construct(private readonly TemplateService $templates) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->templates->index()]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->templates->show($id)]);
    }

    public function showVersion(int $version): JsonResponse
    {
        return response()->json(['data' => $this->templates->showVersion($version)]);
    }

    public function createVersion(int $id): JsonResponse
    {
        return response()->json(['data' => $this->templates->createDraft($id)], 201);
    }

    public function updateVersion(UpdateVersionRequest $request, int $version): JsonResponse
    {
        return response()->json(['data' => $this->templates->updateDraft($version, $request->validated())]);
    }

    public function publish(ReasonRequest $request, int $version): JsonResponse
    {
        return response()->json(['data' => $this->templates->publish($version, $request->validated('reason'))]);
    }

    public function archive(ReasonRequest $request, int $version): JsonResponse
    {
        return response()->json(['data' => $this->templates->archive($version, $request->validated('reason'))]);
    }

    public function preview(PreviewRequest $request, TemplatePreview $preview): JsonResponse
    {
        $data = $request->validated();
        TemplateSchema::assertValid($data['sections'], $data['theme']);

        return response()->json(['data' => $preview->build($data['sections'], $data['theme'], $data['locale'], $data['eventType'])]);
    }
}
