<x-mail::message :preheader="__('mail.admin_invitation.preheader')">
# {{ __('mail.admin_invitation.title', ['name' => \App\Support\Mail\MailLocale::firstName($name)]) }}

{{ __('mail.admin_invitation.intro', ['inviter' => $inviter, 'role' => $role]) }}

<x-mail::button :url="$link">
{{ __('mail.admin_invitation.action') }}
</x-mail::button>

{{ __('mail.admin_invitation.expires', ['hours' => $hours]) }}

{{ __('mail.admin_invitation.outro') }}

{{ __('mail.admin_invitation.signature') }}
</x-mail::message>
