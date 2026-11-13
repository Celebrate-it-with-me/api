Nueva solicitud de ayuda #{{ $request->id }}

De: {{ $user->name }} <{{ $user->email }}>
@if ($event)
Evento: {{ $event->event_name }} (#{{ $event->id }})
@endif

Mensaje:
{{ $request->message }}

Contexto:
@forelse ($context as $key => $value)
- {{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}
@empty
- (sin contexto)
@endforelse
@if ($link)

Abrir en el admin: {{ $link }}
@endif
