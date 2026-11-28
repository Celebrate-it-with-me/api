<?php

namespace App\Http\Services\AppServices\Menu;

use App\Models\Events;
use Illuminate\Support\Facades\DB;

/**
 * What the guests of an event chose from its menus, counted over ALL of them (never one page).
 *
 * Declined people (`not-attending`) are not counted anywhere, even with dishes chosen before she declined.
 * The unit is the person: every row of `guests` of the event (main guests and companions) counts as one. A person has
 * "chosen" when she has at least one row in `guest_menu`. The menu a person chose is not stored on its own: it is the
 * `menu_id` of her dishes (`menu_items.menu_id`); the RSVP only accepts dishes of one menu, and if old data ever mixed
 * menus the one of her lowest dish id wins.
 */
class MenuSelectionSummary
{
    /** RSVP status of someone who said she does not come: she is out of every count, whatever dishes she has. */
    public const DECLINED = 'not-attending';

    public const COURSES = ['starter', 'main', 'dessert'];

    /**
     * @return array{total: int, responded: int, pending: int, menus: list<array{menuId: int, name: string, count: int}>, courses: array<string, list<array{dishId: int, name: string, count: int}>>}
     */
    public function for(Events $event): array
    {
        $total = DB::table('guests')->where('event_id', $event->id)->where('rsvp_status', '!=', self::DECLINED)->count();

        $responded = DB::table('guest_menu')
            ->join('guests', 'guests.id', '=', 'guest_menu.guest_id')
            ->where('guests.event_id', $event->id)
            ->where('guests.rsvp_status', '!=', self::DECLINED)
            ->distinct()
            ->count('guest_menu.guest_id');

        return [
            'total' => $total,
            'responded' => $responded,
            'pending' => $total - $responded,
            'menus' => $this->menus($event),
            'courses' => $this->courses($event),
        ];
    }

    /** @return list<array{menuId: int, name: string, count: int}> every menu of the event, most chosen first */
    private function menus(Events $event): array
    {
        // One menu per person: the menu of her lowest dish id.
        $perGuest = DB::table('guest_menu')
            ->join('guests', 'guests.id', '=', 'guest_menu.guest_id')
            ->join('menu_items', 'menu_items.id', '=', 'guest_menu.menu_item_id')
            ->where('guests.event_id', $event->id)
            ->where('guests.rsvp_status', '!=', self::DECLINED)
            ->groupBy('guest_menu.guest_id')
            ->selectRaw('guest_menu.guest_id, MIN(menu_items.id) as first_item');

        $counts = DB::query()->fromSub($perGuest, 'pg')
            ->join('menu_items', 'menu_items.id', '=', 'pg.first_item')
            ->groupBy('menu_items.menu_id')
            ->selectRaw('menu_items.menu_id as menu_id, COUNT(*) as total')
            ->pluck('total', 'menu_id');

        return DB::table('menus')->where('event_id', $event->id)->get(['id', 'title'])
            ->map(fn ($menu) => ['menuId' => (int) $menu->id, 'name' => (string) ($menu->title ?? ''), 'count' => (int) ($counts[$menu->id] ?? 0)])
            ->sortBy([['count', 'desc'], ['name', 'asc'], ['menuId', 'asc']])
            ->values()
            ->all();
    }

    /** @return array<string, list<array{dishId: int, name: string, count: int}>> the chosen dishes of each course, most chosen first */
    private function courses(Events $event): array
    {
        $courses = array_fill_keys(self::COURSES, []);

        $rows = DB::table('guest_menu')
            ->join('guests', 'guests.id', '=', 'guest_menu.guest_id')
            ->join('menu_items', 'menu_items.id', '=', 'guest_menu.menu_item_id')
            ->where('guests.event_id', $event->id)
            ->where('guests.rsvp_status', '!=', self::DECLINED)
            ->groupBy('menu_items.id', 'menu_items.name', 'menu_items.type')
            ->selectRaw('menu_items.id as id, menu_items.name as name, menu_items.type as type, COUNT(*) as total')
            ->get();

        foreach ($rows as $row) {
            if (isset($courses[$row->type])) {
                $courses[$row->type][] = ['dishId' => (int) $row->id, 'name' => (string) $row->name, 'count' => (int) $row->total];
            }
        }

        foreach ($courses as $type => $dishes) {
            usort($dishes, fn ($a, $b) => [$b['count'], $a['name'], $a['dishId']] <=> [$a['count'], $b['name'], $b['dishId']]);
            $courses[$type] = $dishes;
        }

        return $courses;
    }
}
