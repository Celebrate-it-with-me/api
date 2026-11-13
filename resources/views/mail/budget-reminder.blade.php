<x-mail::message :preheader="trans_choice('mail.budget_reminder.preheader', count($budgetItems), ['count' => count($budgetItems), 'event' => $event->event_name])">
# {{ __('mail.budget_reminder.title', ['name' => \App\Support\Mail\MailLocale::firstName($user->name)]) }}

{{ __('mail.budget_reminder.intro', ['event' => $event->event_name]) }}

<x-mail::table>
| {{ __('mail.budget_reminder.item') }} | {{ __('mail.budget_reminder.due') }} | {{ __('mail.budget_reminder.cost') }} |
| :-- | :-- | --: |
@foreach ($budgetItems as $data)
@php($threshold = $data['threshold'])
| {{ $data['item']->title }}<br>{{ $threshold === 0 ? __('mail.budget_reminder.due_today') : trans_choice('mail.budget_reminder.due_in', $threshold, ['days' => $threshold]) }} | {{ $data['item']->due_date->format('d/m/Y') }} | {{ number_format($data['item']->estimated_cost, 2) }} {{ config('app.currency', '$') }} |
@endforeach
</x-mail::table>

<x-mail::button :url="$url">
{{ __('mail.budget_reminder.action') }}
</x-mail::button>

<x-slot:subcopy>
{{ __('mail.budget_reminder.outro') }}
</x-slot:subcopy>
</x-mail::message>
