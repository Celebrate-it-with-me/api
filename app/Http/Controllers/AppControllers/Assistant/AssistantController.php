<?php

namespace App\Http\Controllers\AppControllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Services\Assistant\AssistantService;
use App\Models\AssistantCustomTask;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Smart assistant, phase 1 (docs/plans/assistant-contract.md). Reading needs `view_event`; every write needs
 * `edit_event` (owner and editor), both enforced by the `event.access` group the routes live in.
 */
class AssistantController extends Controller
{
    private const ACTIONS = ['done' => 'done', 'snooze' => 'snooze', 'hide' => 'hide', 'not-applicable' => 'not_applicable', 'reset' => 'reset'];

    public function __construct(private readonly AssistantService $assistant) {}

    public function next(Request $request, Events $event): JsonResponse
    {
        return response()->json(['data' => $this->assistant->next($event, $this->lang($request))]);
    }

    public function plan(Request $request, Events $event): JsonResponse
    {
        return response()->json(['data' => $this->assistant->plan($event, $this->lang($request))]);
    }

    public function act(Request $request, Events $event, string $key, string $action): JsonResponse
    {
        $days = null;
        if ($action === 'snooze') {
            $raw = $request->input('days');
            if ($raw !== null) {
                $days = is_int($raw) || (is_string($raw) && $raw !== '') ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]) : false;
                if ($days === false) {
                    return $this->invalid(['days' => ['invalid_days']]);
                }
            }
        }

        $task = $this->assistant->act($event, $request->user(), $key, self::ACTIONS[$action], $this->lang($request), $days);
        if ($task === null) {
            return response()->json(['message' => __('errors.assistant.task_not_found'), 'code' => 'task_not_found'], 404);
        }

        return response()->json(['data' => $task]);
    }

    public function storeCustom(Request $request, Events $event): JsonResponse
    {
        $data = $this->customInput($request, false);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $task = $this->assistant->createCustom($event, $request->user(), $data);
        if ($task === null) {
            return response()->json(['message' => __('errors.assistant.custom_task_limit'), 'code' => 'custom_task_limit'], 409);
        }

        return response()->json(['data' => $this->assistant->customPayload($task)], 201);
    }

    public function updateCustom(Request $request, Events $event, int $id): JsonResponse
    {
        $task = $this->customTask($event, $id);
        if ($task === null) {
            return $this->customNotFound();
        }

        $data = $this->customInput($request, true);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        return response()->json(['data' => $this->assistant->customPayload($this->assistant->updateCustom($task, $data))]);
    }

    public function destroyCustom(Events $event, int $id): JsonResponse
    {
        $task = $this->customTask($event, $id);
        if ($task === null) {
            return $this->customNotFound();
        }

        $task->delete();

        return response()->json(['data' => null]);
    }

    /**
     * The fields of a custom task, trimmed, plain text. Errors are codes the app translates: required, too_long,
     * invalid_date (and invalid for `done`). `$partial` is the PATCH: only the keys sent are checked and returned.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function customInput(Request $request, bool $partial): array|JsonResponse
    {
        $input = $request->only(['title', 'notes', 'due_date', 'done']);
        $errors = [];
        $data = [];

        if (! $partial || array_key_exists('title', $input)) {
            $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
            if ($title === '') {
                $errors['title'] = ['required'];
            } elseif (mb_strlen($title) > 120) {
                $errors['title'] = ['too_long'];
            } else {
                $data['title'] = $title;
            }
        }

        if (array_key_exists('notes', $input)) {
            $notes = $input['notes'];
            if ($notes !== null && ! is_string($notes)) {
                $errors['notes'] = ['required'];
            } elseif (is_string($notes) && mb_strlen(trim($notes)) > 500) {
                $errors['notes'] = ['too_long'];
            } else {
                $data['notes'] = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
            }
        }

        if (array_key_exists('due_date', $input)) {
            $date = $input['due_date'];
            if ($date !== null && ! $this->isDate($date)) {
                $errors['due_date'] = ['invalid_date'];
            } else {
                $data['due_date'] = $date;
            }
        }

        if ($partial && array_key_exists('done', $input)) {
            $done = filter_var($input['done'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($done === null) {
                $errors['done'] = ['invalid'];
            } else {
                $data['done'] = $done;
            }
        }

        return $errors === [] ? $data : $this->invalid($errors);
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function customTask(Events $event, int $id): ?AssistantCustomTask
    {
        return AssistantCustomTask::query()->where('event_id', $event->id)->find($id);
    }

    private function customNotFound(): JsonResponse
    {
        return response()->json(['message' => __('errors.assistant.custom_task_not_found'), 'code' => 'custom_task_not_found'], 404);
    }

    /** @param array<string, list<string>> $errors */
    private function invalid(array $errors): JsonResponse
    {
        return response()->json(['message' => __('errors.assistant.invalid'), 'errors' => $errors], 422);
    }

    /** `lang` from the query, or the language of the account (Spanish first). */
    private function lang(Request $request): string
    {
        $lang = $request->query('lang');

        return in_array($lang, ['es', 'en'], true) ? $lang : ($request->user()->preferences?->language === 'en' ? 'en' : 'es');
    }
}
