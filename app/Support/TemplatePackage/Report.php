<?php

namespace App\Support\TemplatePackage;

/**
 * Validation report of a template package (docs/plans/template-package-spec.md, section 7.2). Errors block the
 * install, warnings do not. Every entry carries the file, the code from the catalog and an optional line.
 */
class Report
{
    /** @var list<array{file: string, line: int|null, code: string, message: string, detail: string|null}> */
    private array $errors = [];

    /** @var list<array{file: string, line: int|null, code: string, message: string, detail: string|null}> */
    private array $warnings = [];

    /** @var array<string, mixed> */
    public array $stats = [];

    /** @var array<string, mixed> */
    public array $manifest = [];

    public function error(string $file, string $code, string $message, ?int $line = null, ?string $detail = null): void
    {
        $this->errors[] = $this->entry($file, $code, $message, $line, $detail);
    }

    public function warning(string $file, string $code, string $message, ?int $line = null, ?string $detail = null): void
    {
        $this->warnings[] = $this->entry($file, $code, $message, $line, $detail);
    }

    public function valid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<string> */
    public function errorCodes(): array
    {
        return array_values(array_unique(array_column($this->errors, 'code')));
    }

    /** @return list<string> */
    public function warningCodes(): array
    {
        return array_values(array_unique(array_column($this->warnings, 'code')));
    }

    public function hasError(string $code): bool
    {
        return in_array($code, $this->errorCodes(), true);
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }

    /** Copies the entries of a partial report (a sanitizer run on one file). */
    public function merge(self $other): void
    {
        array_push($this->errors, ...$other->errors);
        array_push($this->warnings, ...$other->warnings);
    }

    /** @return array{errors: array, warnings: array, stats: array, manifest: array} */
    public function toArray(): array
    {
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'stats' => $this->stats,
            'manifest' => $this->manifest,
        ];
    }

    private function entry(string $file, string $code, string $message, ?int $line, ?string $detail): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'code' => $code,
            'message' => $message,
            'detail' => $detail === null ? null : mb_substr($this->printable($detail), 0, 200),
        ];
    }

    /** Hostile input must never reach a log or a screen raw: control and bidi characters are shown escaped. */
    private function printable(string $value): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}]/u',
            fn (array $m) => sprintf('\\u{%04X}', mb_ord($m[0])),
            $value
        );
    }
}
