<?php

namespace App\Support\Mail;

use Closure;

/**
 * One entry of the mail registry.
 *
 * @param  class-string  $class  Mailable or Notification it describes
 * @param  list<string>  $langs  languages it is written in
 * @param  list<string>  $channels  'mail' and/or 'database'
 * @param  Closure|null  $sample  fn (string $lang): Mailable | array{0: Notification, 1: User}; null when it has no mail
 */
final class MailDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $class,
        public readonly array $langs,
        public readonly array $channels,
        public readonly ?Closure $sample,
        public readonly string $when = '',
    ) {}

    public function hasMail(): bool
    {
        return $this->sample !== null;
    }
}
