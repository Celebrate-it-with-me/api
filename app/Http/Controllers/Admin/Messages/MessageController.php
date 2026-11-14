<?php

namespace App\Http\Controllers\Admin\Messages;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Messages\MessageEditor;
use App\Models\User;
use App\Support\AdminPermissions;
use App\Support\Mail\MessageCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin text editor of the transactional mails (docs/plans/message-editor-contract.md). Reads: admin.events.view. Writes: admin.messages.manage + admin.reauth + reason, audited. */
class MessageController extends Controller
{
    private const LOCALES = ['es', 'en'];

    public function __construct(private readonly MessageEditor $editor) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->editor->list()]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $locale = $this->locale($request);
        $this->find($key);

        return response()->json(['data' => $this->editor->detail($key, $locale)]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $this->find($key);
        $data = $request->validate([
            'locale' => ['required', Rule::in(self::LOCALES)],
            'fields' => ['required', 'array', 'min:1'],
            'reason' => AdminAudit::reasonRule(),
        ], ['locale.*' => 'invalid', 'fields.*' => 'required', 'reason.required' => 'required', 'reason.*' => 'invalid']);
        $this->failOn($this->editor->validate($key, $data['locale'], $data['fields']));

        return response()->json(['data' => $this->editor->update($key, $data['locale'], $data['fields'], $request->user(), $data['reason'])]);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $this->find($key);
        $data = $request->validate([
            'locale' => ['required', Rule::in(self::LOCALES)],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
            'reason' => AdminAudit::reasonRule(),
        ], ['locale.*' => 'invalid', 'fields.*' => 'not_editable', 'reason.required' => 'required', 'reason.*' => 'invalid']);
        $fields = $data['fields'] ?? null;
        if ($fields !== null) {
            $this->failOn(collect($fields)->diff(MessageCatalog::fields($key))->mapWithKeys(fn ($f) => [$f => 'not_editable'])->all());
        }

        return response()->json(['data' => $this->editor->reset($key, $data['locale'], $fields, $data['reason'])]);
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        $this->find($key);
        $data = $request->validate([
            'locale' => ['required', Rule::in(self::LOCALES)],
            'fields' => ['nullable', 'array'],
        ], ['locale.*' => 'invalid', 'fields.*' => 'required']);
        $fields = $data['fields'] ?? [];
        $this->failOn($this->editor->validate($key, $data['locale'], $fields));

        return response()->json(['data' => $this->editor->preview($key, $data['locale'], $fields)]);
    }

    public function test(Request $request, string $key): JsonResponse
    {
        $this->find($key);
        $data = $request->validate([
            'locale' => ['required', Rule::in(self::LOCALES)],
            'to' => ['nullable', 'email:rfc', 'max:190'],
            'reason' => AdminAudit::reasonRule(),
        ], ['locale.*' => 'invalid', 'to.*' => 'not_staff', 'reason.required' => 'required', 'reason.*' => 'invalid']);

        $to = strtolower($data['to'] ?? $request->user()->email);
        $isStaff = User::role(AdminPermissions::STAFF_ROLES)->whereRaw('lower(email) = ?', [$to])->exists();
        if (! $isStaff) {
            throw ValidationException::withMessages(['to' => ['not_staff']]);
        }
        $this->editor->sendTest($key, $data['locale'], $to, $data['reason']);

        return response()->json(['data' => ['sent' => true]]);
    }

    private function locale(Request $request): string
    {
        $locale = $request->query('locale');
        if (! in_array($locale, self::LOCALES, true)) {
            throw ValidationException::withMessages(['locale' => ['invalid']]);
        }

        return $locale;
    }

    private function find(string $key): void
    {
        abort_unless(MessageCatalog::isEditable($key), response()->json(['message' => 'Message not found.', 'code' => 'message_not_found'], 404));
    }

    /** @param  array<string, string>  $errors  field => code */
    private function failOn(array $errors): void
    {
        if ($errors) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($code, $field) => ["fields.{$field}" => [$code]])->all());
        }
    }
}
