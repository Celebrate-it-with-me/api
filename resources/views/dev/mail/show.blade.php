<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $definition->label }} · Correos (dev)</title>
@include('dev.mail._style')
</head>
<body>
<main class="wrap">
    <div class="top">
        <a href="{{ route('dev.mail.index') }}">&larr; Todos los correos</a>
        <h1>{{ $definition->label }}</h1>
    </div>
    <div class="tabs">
        @foreach ($definition->langs as $l)
            <a class="btn {{ $l === $lang ? '' : 'alt' }}" href="{{ route('dev.mail.show', ['key' => $definition->key, 'lang' => $l]) }}">{{ strtoupper($l) }}</a>
        @endforeach
    </div>
    <dl class="meta">
        <dt>Asunto</dt><dd><strong>{{ $out['subject'] }}</strong></dd>
        <dt>Preheader</dt><dd>{{ $out['preheader'] ?: '(sin preheader)' }}</dd>
        <dt>Cuándo</dt><dd>{{ $definition->when }}</dd>
        <dt>Clase</dt><dd><code>{{ $definition->class }}</code></dd>
    </dl>
    @if ($out['problems'])
        <div class="problems"><strong>Problemas</strong><ul>@foreach ($out['problems'] as $p)<li>{{ $p }}</li>@endforeach</ul></div>
    @endif
    @php($src = route('dev.mail.frame', ['key' => $definition->key, 'lang' => $lang]))
    <div class="frames">
        <div class="frame"><h3>Escritorio · 600 px</h3><iframe src="{{ $src }}" width="600" title="Escritorio"></iframe></div>
        <div class="frame"><h3>Móvil · 390 px</h3><iframe src="{{ $src }}" width="390" title="Móvil"></iframe></div>
    </div>
</main>
</body>
</html>
