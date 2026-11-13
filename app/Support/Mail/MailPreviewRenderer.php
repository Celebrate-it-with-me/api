<?php

namespace App\Support\Mail;

use App\Mail\SupportRequestMail;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;

/** Renders a registry entry in one language and reports what is wrong with it (shared by the gallery, the command and the tests). */
class MailPreviewRenderer
{
    /** @return array{subject: string, preheader: string, html: string, problems: list<string>} */
    public static function render(MailDefinition $definition, string $lang): array
    {
        $previous = App::getLocale();
        App::setLocale($lang);

        try {
            $sample = ($definition->sample)($lang);

            if ($sample instanceof Mailable) {
                $subject = (string) $sample->envelope()->subject;
                $html = (string) $sample->render();
            } else {
                [$notification, $notifiable] = $sample;
                $message = $notification->toMail($notifiable);
                $subject = (string) $message->subject;
                $html = (string) ($message instanceof MailMessage ? $message->render() : '');
            }
        } finally {
            App::setLocale($previous);
        }

        preg_match('/<div data-preheader="1"[^>]*>(.*?)(?:&#847;|͏|<\/div>)/su', $html, $m);
        $preheader = trim(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5));

        return ['subject' => $subject, 'preheader' => $preheader, 'html' => $html, 'problems' => self::problems($subject, $preheader, $html, plain: $definition->class === SupportRequestMail::class)];
    }

    /** @return list<string> */
    public static function problems(string $subject, string $preheader, string $html, bool $plain = false): array
    {
        $problems = [];
        $text = html_entity_decode(strip_tags(preg_replace('#<(style|head)\b.*?</\1>#si', '', $html)), ENT_QUOTES | ENT_HTML5);
        $all = $subject . "\n" . $text;

        if (trim($subject) === '') {
            $problems[] = 'empty subject';
        }
        if (! $plain && $preheader === '') {
            $problems[] = 'missing preheader';
        }
        if (preg_match_all('/\bmail\.[a-z_]+(?:\.[a-z_]+)+\b/', $all, $keys)) {
            $problems[] = 'unresolved translation keys: ' . implode(', ', array_unique($keys[0]));
        }
        if (preg_match_all('/(?<![\w:\/]):[a-z_]{3,}\b/i', $all, $placeholders)) {
            $problems[] = 'unreplaced placeholders: ' . implode(', ', array_unique($placeholders[0]));
        }
        if (preg_match('/\{\{|\}\}|@php|@foreach|Undefined|<\?php/', $all . $html)) {
            $problems[] = 'template leftovers ({{ }}, @php, Undefined)';
        }
        if (preg_match('/<(strong|b|a|h1|td)\b[^>]*>\s*<\/\1>/i', preg_replace('#<td\b[^>]*>\s*</td>#i', '', $html), $empty) && $empty[1] !== 'td') {
            $problems[] = "empty <{$empty[1]}> (a variable resolved to nothing)";
        }
        if (preg_match('/\b(Hola|Hi|Hello),\s*[!.]/u', $all)) {
            $problems[] = 'greeting without a name';
        }
        if (preg_match_all('/href="([^"]*)"/', $html, $links)) {
            foreach ($links[1] as $href) {
                if (! preg_match('#^(https?://[^\s/]+|mailto:\S+)#', html_entity_decode($href))) {
                    $problems[] = "link is not absolute: [{$href}]";
                }
            }
        }
        if (preg_match('/(?<!\d)\s{2,}(minutos?|minutes?)/', $text)) {
            $problems[] = 'empty number before a unit';
        }

        return $problems;
    }
}
