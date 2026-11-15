<?php

namespace App\Http\Requests\Admin\Announcements;

use App\Http\Services\Admin\AdminAudit;
use App\Models\Announcement;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update. The 422 messages are the codes of docs/plans/announcements-contract.md. */
class AnnouncementRequest extends FormRequest
{
    private const TEXTS = ['title_es', 'title_en', 'body_es', 'body_en', 'cta_label_es', 'cta_label_en'];

    public function authorize(): bool
    {
        return true; // admin.can:admin.catalog.manage on the route
    }

    protected function prepareForValidation(): void
    {
        // Plain text only: tags never reach the database or the app.
        $clean = [];
        foreach (self::TEXTS as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim(strip_tags($value));
                $clean[$field] = $value === '' ? null : $value;
            }
        }
        $url = $this->input('cta_url');
        if (is_string($url)) {
            $clean['cta_url'] = trim($url) === '' ? null : trim($url);
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'level' => ['required', Rule::in(Announcement::LEVELS)],
            'title_es' => ['required', 'string', 'max:80'],
            'title_en' => ['required', 'string', 'max:80'],
            'body_es' => ['required', 'string', 'max:400'],
            'body_en' => ['required', 'string', 'max:400'],
            'cta_label_es' => ['nullable', 'string', 'max:30'],
            'cta_label_en' => ['nullable', 'string', 'max:30'],
            'cta_url' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                if (! self::validUrl($value)) {
                    $fail('invalid_url');
                }
            }],
            'dismissible' => ['nullable', 'boolean'],
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'upcoming_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'reason' => AdminAudit::reasonRule(),
        ];
    }

    public function messages(): array
    {
        $m = [];
        foreach (array_keys($this->rules()) as $field) {
            $m["$field.required"] = 'required';
            $m["$field.string"] = 'invalid';
            $m["$field.boolean"] = 'invalid';
            $m["$field.integer"] = 'invalid';
            $m["$field.min"] = 'too_short';
            $m["$field.max"] = 'too_long';
            $m["$field.date"] = 'invalid';
        }
        $m['level.in'] = 'invalid_level';
        $m['audience.in'] = 'invalid_audience';
        foreach (['integer', 'min', 'max'] as $rule) {
            $m["upcoming_days.$rule"] = 'invalid_days';
        }

        return $m;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $d = $v->getData();
            if ($v->errors()->has('starts_at') === false && $v->errors()->has('ends_at') === false
                && ! empty($d['starts_at']) && ! empty($d['ends_at'])
                && Carbon::parse($d['ends_at'])->lessThanOrEqualTo(Carbon::parse($d['starts_at']))) {
                $v->errors()->add('ends_at', 'ends_before_start');
            }
            if (($d['audience'] ?? null) === 'upcoming_event' && empty($d['upcoming_days']) && ! $v->errors()->has('upcoming_days')) {
                $v->errors()->add('upcoming_days', 'upcoming_days_required');
            }
            // With a URL both labels are required; the error goes on the field that is missing.
            if (! empty($d['cta_url']) && ! $v->errors()->has('cta_url')) {
                foreach (['cta_label_es', 'cta_label_en'] as $label) {
                    if (empty($d[$label]) && ! $v->errors()->has($label)) {
                        $v->errors()->add($label, 'cta_label_required');
                    }
                }
            }
        });
    }

    /** First path segments an announcement may never point to, even as an internal path (they act on the session). */
    private const BLOCKED_PATHS = ['impersonate', 'admin', 'api', 'logout', 'sanctum', 'reset-password', 'verify-email', 'email', 'auth', 'oauth', 'storage'];

    /**
     * `https://host/…` with a host from the allow-list (config `app.announcement_allowed_hosts`), or an internal path
     * starting with a single `/` that is not a sensitive one. Never `javascript:`, `data:`, `//host`, user-info or
     * control characters.
     */
    public static function validUrl(?string $url): bool
    {
        if ($url === null || $url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//') && ! self::isSensitivePath($url);
        }
        if (! str_starts_with(strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return self::hostAllowed($host) && ! self::isSensitivePath($parts['path'] ?? '/');
    }

    private static function isSensitivePath(string $path): bool
    {
        $path = rawurldecode(rawurldecode(explode('#', explode('?', $path)[0])[0]));
        if (str_contains($path, '..') || str_contains($path, '\\')) {
            return true;
        }
        $first = strtolower(explode('/', ltrim($path, '/'))[0]);

        return in_array($first, self::BLOCKED_PATHS, true);
    }

    private static function hostAllowed(string $host): bool
    {
        foreach (self::allowedHosts() as $allowed) {
            if ($allowed === $host || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function allowedHosts(): array
    {
        $configured = (string) config('app.announcement_allowed_hosts', '');
        if (trim($configured) !== '') {
            return array_values(array_filter(array_map(fn (string $h) => strtolower(trim($h)), explode(',', $configured))));
        }

        $hosts = ['quinvite.com', '*.quinvite.com'];
        foreach ([config('app.url'), config('app.frontend_app.url'), config('app.spa_url'), config('services.admin_url')] as $url) {
            $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }
}
