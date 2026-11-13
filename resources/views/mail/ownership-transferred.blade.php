<x-mail::message :preheader="__('mail.ownership_'.$side.'.preheader')">
# {{ __('mail.ownership_'.$side.'.title', ['name' => \App\Support\Mail\MailLocale::firstName($user->name)]) }}

{{ __('mail.ownership_'.$side.'.intro', ['event' => $event->event_name]) }}

<x-mail::button :url="$eventUrl">
{{ __('mail.ownership_'.$side.'.action') }}
</x-mail::button>

<x-slot:subcopy>
{{ __('mail.ownership_'.$side.'.outro') }}
</x-slot:subcopy>
</x-mail::message>
