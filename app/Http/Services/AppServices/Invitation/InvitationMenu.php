<?php

namespace App\Http\Services\AppServices\Invitation;

use App\Http\Services\AppServices\EventModules;
use App\Models\Events;
use App\Models\Guest;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The menu step of the public RSVP (docs/plans/invitation-premium-modules-contract.md, section 6): which menu the
 * invitation offers, what each attendee already chose, and the rules for saving it. One dish of each type
 * (starter, main, dessert) per person, never mandatory. Without the `menus` module, or without a menu that has dishes,
 * there is no step: nothing is sent and anything received is ignored.
 */
class InvitationMenu
{
    public const MODULE = 'menus';

    /**
     * The menu this guest is offered: the one the organizer assigned to her, else the event's default one, else the
     * only menu the event has. Null without the module, without such a menu or when it has no dishes.
     */
    public function resolve(Events $event, Guest $guest): ?Menu
    {
        if (! app(EventModules::class)->isActive($event, self::MODULE)) {
            return null;
        }

        $menus = Menu::query()->where('event_id', $event->id)->orderBy('id')->get();

        $menu = $menus->firstWhere('id', $guest->assigned_menu_id)
            ?? $menus->firstWhere('is_default', true)
            ?? ($menus->count() === 1 ? $menus->first() : null);

        if ($menu === null) {
            return null;
        }

        $menu->setRelation('menuItems', $menu->menuItems()->orderBy('id')->get());

        return $menu->menuItems->isEmpty() ? null : $menu;
    }

    /**
     * The `menu` block of the rsvp section and the dish ids chosen by each person (main guest first).
     *
     * @param  list<Guest>  $people
     * @return array{menu: array<string, mixed>, chosen: array<int, list<int>>}|null
     */
    public function forPeople(Events $event, Guest $guest, array $people, bool $editable): ?array
    {
        $menu = $this->resolve($event, $guest);

        if ($menu === null) {
            return null;
        }

        $allowed = $menu->menuItems->pluck('id')->all();
        $ids = array_values(array_filter(array_map(fn (Guest $p) => $p->id, $people)));

        $chosen = array_fill_keys($ids, []);

        foreach (DB::table('guest_menu')->whereIn('guest_id', $ids)->whereIn('menu_item_id', $allowed)->orderBy('menu_item_id')->get(['guest_id', 'menu_item_id']) as $row) {
            $chosen[$row->guest_id][] = (int) $row->menu_item_id;
        }

        return [
            'menu' => [
                'title' => $menu->title,
                'description' => $menu->description,
                // One dish of each type per person: the rule is fixed, whatever the organizer's menu says.
                'allowMultiple' => false,
                'items' => $menu->menuItems->map(fn (MenuItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'type' => $item->type,
                    'dietType' => $item->diet_type,
                    'notes' => $item->notes,
                    'imageUrl' => $this->imageUrl($item->image_path),
                ])->all(),
                'editable' => $editable,
            ],
            'chosen' => $chosen,
        ];
    }

    /**
     * Refuses the request (before anything is written) if a person's dishes are not all from the offered menu or
     * repeat a type. Returns the dish ids to save per person key ("guest" or the companion id), only for the people
     * whose ids were sent; a missing key means "leave as it is".
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, list<int>>|null null when there is no menu step
     *
     * @throws ValidationException
     */
    public function validated(Events $event, Guest $guest, array $payload): ?array
    {
        $menu = $this->resolve($event, $guest);

        if ($menu === null) {
            return null;
        }

        $types = $menu->menuItems->pluck('type', 'id');
        $picks = [];

        $check = function (string $key, string $field, mixed $ids) use ($types, &$picks) {
            if (! is_array($ids)) {
                return;
            }

            $ids = array_values(array_unique(array_map('intval', $ids)));

            if (collect($ids)->contains(fn (int $id) => ! $types->has($id))) {
                throw ValidationException::withMessages([$field => 'invalid']);
            }

            if (collect($ids)->map(fn (int $id) => $types[$id])->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([$field => 'one_per_type']);
            }

            $picks[$key] = $ids;
        };

        $check('guest', 'guest.menuItemIds', $payload['guest']['menuItemIds'] ?? null);

        foreach (array_values($payload['companions'] ?? []) as $index => $companion) {
            $check((string) $companion['id'], "companions.{$index}.menuItemIds", $companion['menuItemIds'] ?? null);
        }

        return $picks;
    }

    /**
     * Writes one person's dishes. Someone who does not attend keeps none; a person who attends and sent no ids keeps
     * what she had (an older client).
     *
     * @param  list<int>|null  $ids  null when the request did not carry any
     */
    public function save(Guest $person, string $status, ?array $ids): void
    {
        if ($status !== 'attending') {
            $person->selectedMenuItems()->sync([]);

            return;
        }

        if ($ids !== null) {
            $person->selectedMenuItems()->sync($ids);
        }
    }

    private function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::disk(config('filesystems.media_disk'))->url($path);
    }
}
