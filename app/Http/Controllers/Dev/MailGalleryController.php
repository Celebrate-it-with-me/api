<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\Mail\MailPreviewRenderer;
use App\Support\Mail\MailRegistry;
use Illuminate\Http\Request;

/** Development gallery of every registered mail (/dev/mail). Only answers when APP_ENV=local; 404 everywhere else. */
class MailGalleryController extends Controller
{
    public function index()
    {
        $this->onlyLocal();

        $rows = collect(MailRegistry::all())->map(function ($definition) {
            $subjects = [];
            $problems = 0;
            foreach ($definition->hasMail() ? $definition->langs : [] as $lang) {
                $out = MailPreviewRenderer::render($definition, $lang);
                $subjects[$lang] = $out['subject'];
                $problems += count($out['problems']);
            }

            return ['definition' => $definition, 'subjects' => $subjects, 'problems' => $problems];
        });

        return view('dev.mail.index', ['rows' => $rows]);
    }

    public function show(Request $request, string $key)
    {
        $this->onlyLocal();
        $definition = MailRegistry::find($key);
        abort_unless($definition && $definition->hasMail(), 404);

        $lang = in_array($request->query('lang'), $definition->langs, true) ? $request->query('lang') : $definition->langs[0];
        $out = MailPreviewRenderer::render($definition, $lang);

        return view('dev.mail.show', ['definition' => $definition, 'lang' => $lang, 'out' => $out]);
    }

    public function frame(Request $request, string $key)
    {
        $this->onlyLocal();
        $definition = MailRegistry::find($key);
        abort_unless($definition && $definition->hasMail(), 404);

        $lang = in_array($request->query('lang'), $definition->langs, true) ? $request->query('lang') : $definition->langs[0];
        $html = MailPreviewRenderer::render($definition, $lang)['html'];

        // The support mail is plain text: show it as such.
        return response($html !== strip_tags($html) ? $html : '<pre style="font:14px/1.5 monospace;padding:16px;white-space:pre-wrap">' . e($html) . '</pre>');
    }

    private function onlyLocal(): void
    {
        abort_unless(app()->environment('local'), 404);
    }
}
