<x-mail::message :preheader="__('mail.invite_registered.preheader', ['event' => $invite->event->event_name])">
# {{ __('mail.invite_registered.title') }}

{{ __('mail.invite_registered.intro', ['event' => $invite->event->event_name, 'role' => $role]) }}

<x-mail::button :url="$eventUrl">
{{ __('mail.invite_registered.action') }}
</x-mail::button>

<x-slot:subcopy>
{{ __('mail.invite_registered.outro') }}
</x-slot:subcopy>
</x-mail::message>
