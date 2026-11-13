<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Correos · Quinvite (dev)</title>
@include('dev.mail._style')
</head>
<body>
<main class="wrap">
    <div class="top">
        <h1>Correos de Quinvite</h1>
        <span class="muted">Solo en local. Registro: <code>App\Support\Mail\MailRegistry</code> · <code>php artisan mail:preview-all</code></span>
    </div>

    <div class="grid">
    @foreach ($rows as $row)
        @php($d = $row['definition'])
        <article class="card">
            <h2>{{ $d->label }}</h2>
            <div>
                @foreach ($d->channels as $channel)<span class="pill">{{ $channel }}</span>@endforeach
                @foreach ($d->langs as $l)<span class="pill">{{ $l }}</span>@endforeach
                @if ($d->hasMail())<span class="pill {{ $row['problems'] ? 'bad' : 'ok' }}">{{ $row['problems'] ? $row['problems'].' problema(s)' : 'sin problemas' }}</span>@endif
            </div>
            <div class="muted">{{ $d->when }}</div>
            @foreach ($row['subjects'] as $l => $subject)
                <div class="subj"><b>{{ $l }}</b>{{ $subject }}</div>
            @endforeach
            <div class="muted"><code>{{ class_basename($d->class) }}</code></div>
            @if ($d->hasMail())
            <div class="actions">
                @foreach ($d->langs as $l)
                    <a class="btn {{ $loop->first ? '' : 'alt' }}" href="{{ route('dev.mail.show', ['key' => $d->key, 'lang' => $l]) }}">Ver {{ strtoupper($l) }}</a>
                @endforeach
            </div>
            @endif
        </article>
    @endforeach
    </div>
</main>
</body>
</html>
