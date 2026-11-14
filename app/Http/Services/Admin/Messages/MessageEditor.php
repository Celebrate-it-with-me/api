<?php

namespace App\Http\Services\Admin\Messages;

use App\Http\Services\Admin\AdminAudit;
use App\Models\MessageOverride;
use App\Models\User;
use App\Support\Mail\MailPreviewRenderer;
use App\Support\Mail\MessageCatalog;
use App\Support\Mail\MessageOverrides;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Business rules of the mail text editor (docs/plans/message-editor-contract.md). */
class MessageEditor
{
    public function __construct(private readonly MessageOverrides $overrides, private readonly AdminAudit $audit) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $rows = MessageOverride::query()->get()->groupBy('message_key');

        return array_map(function (string $key) use ($rows) {
            $definition = MessageCatalog::definition($key);
            $own = $rows->get($key, collect());

            return [
                'key' => $key,
                'name' => $definition->label,
                'description' => $definition->when,
                'locales' => $definition->langs,
                'editableFields' => MessageCatalog::fields($key),
                'overridden' => ['es' => $own->contains('locale', 'es'), 'en' => $own->contains('locale', 'en')],
                'updatedAt' => $own->max('updated_at')?->toIso8601String(),
            ];
        }, MessageCatalog::keys());
    }

    /** @return array<string, mixed> */
    public function detail(string $key, string $locale): array
    {
        $rows = MessageOverride::query()->with('editor:id,name')->where('message_key', $key)->where('locale', $locale)->get()->keyBy('field');
        $fields = [];
        $variables = [];
        foreach (MessageCatalog::fields($key) as $field) {
            $default = MessageCatalog::default($key, $locale, $field);
            $names = MessageCatalog::variables($default);
            foreach ($names as $name) {
                $variables[$name] = ['name' => $name, 'example' => MessageCatalog::example($name)];
            }
            $fields[$field] = [
                'default' => $default,
                'current' => $rows->has($field) ? $rows[$field]->value : $default,
                'overridden' => $rows->has($field),
                'variables' => $names,
                'maxLength' => MessageCatalog::maxLength($field),
            ];
        }
        $latest = $rows->sortByDesc('updated_at')->first();

        return [
            'key' => $key,
            'name' => MessageCatalog::definition($key)->label,
            'locale' => $locale,
            'fields' => $fields,
            'variables' => array_values($variables),
            'updatedAt' => $latest?->updated_at?->toIso8601String(),
            'updatedBy' => $latest?->editor ? ['id' => $latest->editor->id, 'name' => $latest->editor->name] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, string> field => error code (empty when valid)
     */
    public function validate(string $key, string $locale, array $fields): array
    {
        $editable = MessageCatalog::fields($key);
        $errors = [];
        foreach ($fields as $field => $text) {
            $field = (string) $field;
            if (! in_array($field, $editable, true)) {
                $errors[$field] = 'not_editable';
            } elseif (($code = $this->check($key, $locale, $field, $text)) !== null) {
                $errors[$field] = $code;
            }
        }

        return $errors;
    }

    /** Subject and preheader are one line: line breaks become spaces. */
    public function normalize(string $field, string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return in_array($field, ['subject', 'preheader'], true) ? trim(preg_replace('/\s+/u', ' ', $text)) : trim($text);
    }

    /** @param  array<string, string>  $fields */
    public function update(string $key, string $locale, array $fields, User $actor, string $reason): array
    {
        DB::transaction(function () use ($key, $locale, $fields, $actor) {
            foreach ($fields as $field => $text) {
                $text = $this->normalize($field, $text);
                $match = ['message_key' => $key, 'locale' => $locale, 'field' => $field];
                if ($text === MessageCatalog::default($key, $locale, $field)) {
                    MessageOverride::where($match)->delete();
                } else {
                    MessageOverride::updateOrCreate($match, ['value' => $text, 'updated_by' => $actor->id]);
                }
            }
        });
        $this->overrides->forget();
        $this->audit->record('message.update', ['message', null], ['reason' => $reason, 'after' => ['key' => $key, 'locale' => $locale, 'fields' => array_keys($fields)]]);

        return $this->detail($key, $locale);
    }

    /** @param  list<string>|null  $fields  null restores every field of the language */
    public function reset(string $key, string $locale, ?array $fields, string $reason): array
    {
        $query = MessageOverride::where('message_key', $key)->where('locale', $locale);
        if ($fields !== null) {
            $query->whereIn('field', $fields);
        }
        $restored = $query->pluck('field')->all();
        $query->delete();
        $this->overrides->forget();
        $this->audit->record('message.reset', ['message', null], ['reason' => $reason, 'after' => ['key' => $key, 'locale' => $locale, 'fields' => $restored]]);

        return $this->detail($key, $locale);
    }

    /**
     * Renders the mail with its sample data, with the given unsaved texts on top of the saved ones.
     *
     * @param  array<string, string>  $fields
     * @return array{subject: string, preheader: string, html: string}
     */
    public function preview(string $key, string $locale, array $fields): array
    {
        foreach ($fields as $field => $text) {
            $fields[$field] = $this->normalize((string) $field, $text);
        }
        $out = $this->overrides->withPreview($key, $locale, $fields, fn () => MailPreviewRenderer::render(MessageCatalog::definition($key), $locale));

        return ['subject' => $out['subject'], 'preheader' => $out['preheader'], 'html' => $out['html']];
    }

    public function sendTest(string $key, string $locale, string $to, string $reason): void
    {
        $definition = MessageCatalog::definition($key);
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            $sample = ($definition->sample)($locale);
            if ($sample instanceof Mailable) {
                Mail::to($to)->send($sample);
            } else {
                [$notification, $notifiable] = $sample;
                $message = $notification->toMail($notifiable);
                Mail::html((string) $message->render(), fn ($mail) => $mail->to($to)->subject((string) $message->subject));
            }
        } finally {
            App::setLocale($previous);
        }
        $this->audit->record('message.test', ['message', null], ['reason' => $reason, 'after' => ['key' => $key, 'locale' => $locale, 'to' => $to]]);
    }

    private function check(string $key, string $locale, string $field, mixed $text): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return 'required';
        }
        $text = $this->normalize($field, $text);
        if ($text === '') {
            return 'required';
        }
        if (mb_strlen($text) > MessageCatalog::maxLength($field)) {
            return 'too_long';
        }
        if (preg_match('/<\s*\/?\s*[a-z!?]/i', $text)) {
            return 'html_not_allowed';
        }
        if (preg_match('/\]\s*[\(\[]|^\s*\[[^\]]+\]:|https?:|ftp:|mailto:|www\.|\b[\w.+-]+@[\w-]+\.[\w.-]+/im', $text)) {
            return 'links_not_allowed';
        }
        $original = MessageCatalog::variables(MessageCatalog::default($key, $locale, $field));
        $given = MessageCatalog::variables($text);
        if (array_diff($original, $given)) {
            return 'missing_variable';
        }

        return array_diff($given, $original) ? 'unknown_variable' : null;
    }
}
