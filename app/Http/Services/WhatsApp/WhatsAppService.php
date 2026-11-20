<?php

namespace App\Http\Services\WhatsApp;

use App\Models\WhatsApp\Template;
use App\Support\WhatsApp\TemplateCatalog;

/** Picks the driver (WHATSAPP_DRIVER) and answers the questions every entry point asks: can we send, is this template sendable. */
class WhatsAppService
{
    public function driverName(): string
    {
        return self::normalizeDriver(config('services.whatsapp.driver')) === 'meta' ? 'meta' : 'fake';
    }

    /** What is configured, trimmed and lower-cased (`Meta ` runs as `meta`); the safety check uses the same reading. */
    public static function normalizeDriver(mixed $driver): string
    {
        return strtolower(trim((string) $driver));
    }

    public function gateway(): WhatsAppGateway
    {
        return $this->driverName() === 'meta' ? new MetaGateway : new FakeGateway;
    }

    /** False when the driver is `meta` and some credential is missing. */
    public function isAvailable(): bool
    {
        return $this->gateway()->isConfigured();
    }

    /**
     * The template for (key, locale) if it can be sent. Under the fake driver a draft is approved on the spot (the fake
     * "approves at once"); `padrino_reminder` stays unsendable until Padrinos exists. Null when it cannot be sent.
     */
    public function sendable(string $key, string $locale): ?Template
    {
        $template = Template::query()->where('key', $key)->where('locale', $locale)->first();

        if ($template && ! $template->isApproved() && $this->driverName() === 'fake' && $template->status === 'draft' && in_array($key, TemplateCatalog::SENDABLE_KEYS, true)) {
            $template->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        }

        return $template?->isApproved() ? $template : null;
    }

    /** Like `sendable`, but only answers: it never approves the draft (for read-only endpoints). */
    public function canSend(string $key, string $locale): bool
    {
        $template = Template::query()->where('key', $key)->where('locale', $locale)->first();

        if (! $template) {
            return false;
        }

        return $template->isApproved()
            || ($this->driverName() === 'fake' && $template->status === 'draft' && in_array($key, TemplateCatalog::SENDABLE_KEYS, true));
    }
}
