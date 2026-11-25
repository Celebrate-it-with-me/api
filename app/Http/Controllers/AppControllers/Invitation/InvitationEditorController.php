<?php

namespace App\Http\Controllers\AppControllers\Invitation;

use App\Http\Controllers\Controller;
use App\Http\Services\AppServices\Invitation\InvitationEditor;
use App\Http\Services\AppServices\InvitationService;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Tu invitación": the editor, the template selector, the preview and the cover photo of one event. Access is checked by
 * the `event.access` middleware of the route group (`edit_event` on every write); the rules live in InvitationEditor.
 * Contract: docs/plans/template-stage5-contract-draft.md.
 */
class InvitationEditorController extends Controller
{
    public function __construct(private readonly InvitationEditor $editor) {}

    public function show(Request $request, Events $event): JsonResponse
    {
        return response()->json(['data' => $this->editor->state($event, $request->user())]);
    }

    public function templates(Request $request, Events $event): JsonResponse
    {
        return response()->json(['data' => $this->editor->templates($event, $request->user())]);
    }

    public function impact(Request $request, Events $event, string $template): JsonResponse
    {
        return response()->json(['data' => $this->editor->impact($event, $request->user(), $template)]);
    }

    public function applyTemplate(Request $request, Events $event): JsonResponse
    {
        $id = $request->input('template_id');
        if ($id === null || $id === '') {
            throw ValidationException::withMessages(['template_id' => ['required']]);
        }
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw ValidationException::withMessages(['template_id' => ['invalid']]);
        }

        return response()->json(['data' => $this->editor->applyTemplate($event, $request->user(), $id, $request->boolean('undo'))]);
    }

    public function saveFields(Request $request, Events $event): JsonResponse
    {
        return response()->json(['data' => $this->editor->saveFields($event, $request->user(), $request->input('fields'))]);
    }

    public function saveSections(Request $request, Events $event): JsonResponse
    {
        return response()->json(['data' => $this->editor->saveSections($event, $request->user(), $request->input('sections'))]);
    }

    public function preview(Request $request, Events $event): JsonResponse
    {
        $body = $request->only(['template_id', 'fields', 'locale', 'state', 'preset']);
        $errors = [];
        if (isset($body['template_id']) && ! is_int($body['template_id']) && ! (is_string($body['template_id']) && ctype_digit($body['template_id']))) {
            $errors['template_id'] = ['invalid'];
        }
        if (isset($body['fields']) && (! is_array($body['fields']) || ($body['fields'] !== [] && array_is_list($body['fields'])))) {
            $errors['fields'] = ['invalid_type'];
        }
        if (isset($body['locale']) && ! in_array($body['locale'], InvitationService::LOCALES, true)) {
            $errors['locale'] = ['invalid'];
        }
        if (isset($body['state']) && ! in_array($body['state'], ['invitation', 'save_the_date', 'no_photo'], true)) {
            $errors['state'] = ['invalid'];
        }
        if (isset($body['preset']) && ! is_string($body['preset'])) {
            $errors['preset'] = ['invalid_choice'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return response()->json(['data' => $this->editor->preview($event, $request->user(), $body)]);
    }

    public function storeCover(Request $request, Events $event): JsonResponse
    {
        $focus = $request->input('focus');
        $photo = $this->editor->storeCover($event, $request->user(), $request->file('photo'), is_string($focus) ? $focus : null);

        return response()->json(['data' => $photo], 201);
    }

    public function destroyCover(Request $request, Events $event): JsonResponse
    {
        $this->editor->removeCover($event, $request->user());

        return response()->json(['data' => null]);
    }

    public function storeImage(Request $request, Events $event, string $field): JsonResponse
    {
        $focus = $request->input('focus');
        $photo = $this->editor->storeImage($event, $request->user(), $field, $request->file('photo'), is_string($focus) ? $focus : null);

        return response()->json(['data' => $photo], 201);
    }

    public function destroyImage(Request $request, Events $event, string $field): JsonResponse
    {
        $this->editor->removeImage($event, $request->user(), $field);

        return response()->json(['data' => null]);
    }
}
