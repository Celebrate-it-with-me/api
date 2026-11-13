<x-mail::message :preheader="__('mail.invite_unregistered.preheader', ['event' => $invite->event->event_name])">
# {{ __('mail.invite_unregistered.title') }}

{{ __('mail.invite_unregistered.intro', ['event' => $invite->event->event_name, 'role' => $role]) }}

<x-mail::button :url="$acceptUrl">
{{ __('mail.invite_unregistered.action') }}
</x-mail::button>

<x-slot:subcopy>
{{ __('mail.invite_unregistered.outro') }}
</x-slot:subcopy>
</x-mail::message>
