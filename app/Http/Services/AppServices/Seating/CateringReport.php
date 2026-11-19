<?php

namespace App\Http\Services\AppServices\Seating;

use App\Http\Services\AppServices\EventModules;
use App\Models\Events;
use App\Models\Guest;
use App\Models\MenuItem;
use Illuminate\Support\Collection;

/**
 * What the caterer and the venue need, as plain data that the XLSX and the PDF both print: dishes chosen (total and per
 * table), who sits where and what each person chose. No phone, no email. People: confirmed ones, plus the pending ones
 * when asked. The menu part exists only with the Menu module active, the table part only with Tables.
 *
 * @phpstan-type Person array{name: string, companion_of: ?string, status: string, table: ?string, seat: ?int, dishes: array<string, string>, diets: string}
 */
class CateringReport
{
    /** Courses in the order they are served; any other menu type goes after them. */
    private const TYPE_ORDER = ['starter', 'main', 'dessert'];

    public function __construct(private readonly EventModules $modules) {}

    /** @return array<string, mixed> */
    public function build(Events $event, bool $includePending, string $locale): array
    {
        $menus = $this->modules->isActive($event, 'menus');
        $seating = $this->modules->isActive($event, 'seating');

        $guests = $this->guests($event, $includePending, $menus, $seating);
        $types = $menus ? $this->types($event, $guests) : [];

        $people = $guests->map(fn (Guest $guest) => $this->person($guest, $types, $menus, $seating))->values();

        return [
            'event' => (string) $event->event_name,
            'date' => $event->start_date?->locale($locale)->translatedFormat($locale === 'es' ? 'j \d\e F \d\e Y' : 'F j, Y'),
            'locale' => $locale,
            'menus' => $menus,
            'seating' => $seating,
            'include_pending' => $includePending,
            'types' => $types,
            'total_people' => $people->count(),
            'summary' => $menus ? $this->dishCounts($people, $types) : [],
            'tables' => $seating ? $this->tables($people, $types, $menus) : [],
            'no_table' => $seating ? $people->whereNull('table')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all() : [],
        ];
    }

    /** @return Collection<int, Guest> */
    private function guests(Events $event, bool $includePending, bool $menus, bool $seating): Collection
    {
        $with = ['parent:id,name'];
        if ($menus) {
            $with[] = 'selectedMenuItems:menu_items.id,menu_items.name,menu_items.type,menu_items.diet_type';
        }
        if ($seating) {
            $with[] = 'tableAssignment.table:id,name,capacity';
        }

        return Guest::query()->with($with)
            ->where('event_id', $event->id)
            ->whereIn('rsvp_status', $includePending ? ['attending', 'pending'] : ['attending'])
            ->orderBy('name')->orderBy('id')
            ->get();
    }

    /** Course types of the event's menu (and of anything chosen), served order first. @return list<string> */
    private function types(Events $event, Collection $guests): array
    {
        $fromMenu = MenuItem::query()->whereHas('menu', fn ($q) => $q->where('event_id', $event->id))->distinct()->pluck('type');
        $chosen = $guests->flatMap(fn (Guest $g) => $g->selectedMenuItems->pluck('type'));
        $all = $fromMenu->merge($chosen)->filter()->unique()->values();

        return $all->sortBy(fn ($type) => [($i = array_search($type, self::TYPE_ORDER, true)) === false ? 99 : $i, $type])->values()->all();
    }

    /** @param  list<string>  $types  @return Person */
    private function person(Guest $guest, array $types, bool $menus, bool $seating): array
    {
        $dishes = [];
        $picks = [];
        $diets = [];
        if ($menus) {
            foreach ($types as $type) {
                $items = $guest->selectedMenuItems->where('type', $type)->sortBy('name');
                $dishes[$type] = $items->pluck('name')->implode(' / ');
                $picks[$type] = $items->map(fn (MenuItem $i) => ['name' => $i->name, 'diet' => (string) $i->diet_type])->values()->all();
                $diets = array_merge($diets, $items->pluck('diet_type')->filter()->all());
            }
        }
        if ($guest->allergies) {
            $diets[] = (string) $guest->allergies;
        }

        $assignment = $seating ? $guest->tableAssignment : null;

        return [
            'name' => $guest->name,
            'companion_of' => $guest->parent?->name,
            'status' => $guest->rsvp_status,
            'table' => $assignment?->table?->name,
            'table_id' => $assignment?->table_id,
            'capacity' => $assignment?->table?->capacity,
            'seat' => $assignment && preg_match('/(\d+)\s*$/', (string) $assignment->seat_number, $m) ? (int) $m[1] : null,
            'dishes' => $dishes,
            'picks' => $picks,
            'diets' => implode('; ', array_unique($diets)),
        ];
    }

    /**
     * Dishes chosen by these people, per course (with the diet note of the dish), plus how many chose nothing for it.
     *
     * @param  list<string>  $types
     * @return array<string, array{items: list<array{name: string, diet: string, count: int}>, unchosen: int}>
     */
    private function dishCounts(Collection $people, array $types): array
    {
        $out = [];
        foreach ($types as $type) {
            $counts = [];
            $unchosen = 0;
            foreach ($people as $person) {
                if ($person['picks'][$type] === []) {
                    $unchosen++;

                    continue;
                }
                foreach ($person['picks'][$type] as $pick) {
                    $counts[$pick['name']] ??= ['name' => $pick['name'], 'diet' => $pick['diet'], 'count' => 0];
                    $counts[$pick['name']]['count']++;
                }
            }
            ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);

            $out[$type] = ['items' => array_values($counts), 'unchosen' => $unchosen];
        }

        return $out;
    }

    /**
     * Tables in name order with their people (seat order) and the dish counts.
     *
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function tables(Collection $people, array $types, bool $menus): array
    {
        return $people->whereNotNull('table')->groupBy('table_id')
            ->map(function (Collection $group) use ($types, $menus) {
                $sorted = $group->sortBy(fn ($p) => [$p['seat'] ?? PHP_INT_MAX, $p['name']])->values();

                return [
                    'name' => $sorted[0]['table'],
                    'capacity' => $sorted[0]['capacity'],
                    'people' => $sorted->count(),
                    'dishes' => $menus ? $this->dishCounts($sorted, $types) : [],
                    'guests' => $sorted->all(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }
}
