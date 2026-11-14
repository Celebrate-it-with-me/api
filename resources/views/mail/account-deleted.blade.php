<x-mail::message :preheader="__('mail.account_deleted.preheader')">
# {{ __('mail.account_deleted.title', ['name' => \App\Support\Mail\MailLocale::md($firstName)]) }}

{{ __('mail.account_deleted.intro') }}

@if ($archivedEvents > 0)
{{ trans_choice('mail.account_deleted.archived', $archivedEvents, ['count' => $archivedEvents, 'days' => $archiveDays]) }}

@endif
{{ __('mail.account_deleted.kept') }}

<x-slot:subcopy>
{{ __('mail.account_deleted.outro') }}
</x-slot:subcopy>
</x-mail::message>
