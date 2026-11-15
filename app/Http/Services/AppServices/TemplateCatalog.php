<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\InvitationTemplate;
use App\Models\TemplatePackage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single place that decides which templates a person (and event) may use: active templates without a package or with
 * a public one. Packages with `visibility: private` are only for accounts or events with an active grant; grants arrive
 * with stage 6 of the plan, so until then a private package is available to nobody. The selector and any assignment of
 * a template go through here.
 */
class TemplateCatalog
{
    /** @return Collection<int, InvitationTemplate> */
    public function availableFor(?User $user = null, ?Events $event = null): Collection
    {
        return InvitationTemplate::query()
            ->where('is_active', true)
            ->when($event?->event_type_id, fn ($q, $type) => $q->where(fn ($q) => $q->whereNull('event_type_id')->orWhere('event_type_id', $type)))
            ->with('currentVersion.package')
            ->orderBy('id')
            ->get()
            ->filter(fn (InvitationTemplate $t) => $this->allows($t, $event, $user))
            ->values();
    }

    /** Whether the template may be drawn for this event: its package, if any, is public (or granted, from stage 6). */
    public function allows(InvitationTemplate $template, ?Events $event = null, ?User $user = null): bool
    {
        $package = $template->relationLoaded('currentVersion') && $template->currentVersion?->relationLoaded('package')
            ? $template->currentVersion->package
            : $template->currentVersion()->with('package')->first()?->package;

        if ($package === null) {
            return true;
        }

        return $package->visibility === TemplatePackage::PUBLIC && ! $package->isWithdrawn();
    }
}
