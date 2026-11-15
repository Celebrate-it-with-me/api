<?php

/** Controllers whose exceptions are deliberate, user-facing validation errors (their message is written for the client). */
const NO_LEAK_ALLOWED = ['Theme/PublicEventController.php', 'GuestImportController.php', 'PublicSuggestedMusicController.php', 'Moderation/ModerationController.php'];

/** S-18: controllers answer a generic message and report() the exception; they never echo it to the client. */
it('does not put exception messages, files or traces in JSON responses', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http/Controllers')));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || collect(NO_LEAK_ALLOWED)->contains(fn ($a) => str_ends_with($file->getPathname(), $a))) {
            continue;
        }
        foreach (file($file->getPathname()) as $n => $line) {
            if (preg_match("/'(message|error|trace)'\s*=>[^\\n]*(->getMessage\(\)|->getTraceAsString\(\)|->getFile\(\))/", $line)
                && ! str_contains($line, "config('app.debug')")) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
