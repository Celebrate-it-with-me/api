<?php

namespace App\Support\Mail;

use Illuminate\Translation\Translator;

/**
 * The application translator, with one addition: lines of the `mail` group are first looked up in the admin's edited
 * texts (MessageOverrides) and, when there is none, read from lang/{locale}/mail.php as always. No Mailable changes.
 */
class OverridableTranslator extends Translator
{
    protected function getLine($namespace, $group, $locale, $item, array $replace)
    {
        if ($namespace === '*' && $group === 'mail') {
            $text = app(MessageOverrides::class)->lookup($locale, $item);
            if ($text !== null) {
                return $this->makeReplacements($text, $replace);
            }
        }

        return parent::getLine($namespace, $group, $locale, $item, $replace);
    }
}
