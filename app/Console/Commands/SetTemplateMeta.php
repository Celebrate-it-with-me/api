<?php

namespace App\Console\Commands;

use App\Models\InvitationTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Loads the picker description (es/en) and style tags of a template into its row. The texts come from the owner, not
 * from the code: nothing is seeded. Plain text only, short.
 */
class SetTemplateMeta extends Command
{
    public const DESCRIPTION_MAX = 200;

    public const TAG_MAX = 24;

    public const TAGS_MAX = 8;

    protected $signature = 'invitation:template-meta
        {slug : Slug of the template (invitation_templates.slug)}
        {--description-es= : Spanish description}
        {--description-en= : English description}
        {--tag=* : Style tag, repeatable (lowercase letters, digits and dashes)}
        {--clear-tags : Remove all tags}';

    protected $description = 'Set the description and tags a template shows in the picker';

    public function handle(): int
    {
        $template = InvitationTemplate::query()->where('slug', $this->argument('slug'))->first();
        if ($template === null) {
            $this->error('Template not found.');

            return self::FAILURE;
        }

        $es = $this->option('description-es');
        $en = $this->option('description-en');
        $tags = array_values(array_unique(array_map('strval', (array) $this->option('tag'))));

        $v = Validator::make(
            ['es' => $es, 'en' => $en, 'tags' => $tags],
            [
                'es' => ['nullable', 'string', 'max:' . self::DESCRIPTION_MAX, 'not_regex:/[<>]/'],
                'en' => ['nullable', 'string', 'max:' . self::DESCRIPTION_MAX, 'not_regex:/[<>]/'],
                'tags' => ['array', 'max:' . self::TAGS_MAX],
                'tags.*' => ['string', 'max:' . self::TAG_MAX, 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            ],
        );
        if ($v->fails()) {
            foreach ($v->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($es !== null || $en !== null) {
            $current = (array) $template->description;
            $template->description = [
                'es' => $es !== null && $es !== '' ? $es : ($current['es'] ?? null),
                'en' => $en !== null && $en !== '' ? $en : ($current['en'] ?? null),
            ];
        }
        if ($this->option('clear-tags')) {
            $template->tags = [];
        } elseif ($tags !== []) {
            $template->tags = $tags;
        }
        $template->save();
        $this->info("Updated {$template->slug}.");

        return self::SUCCESS;
    }
}
