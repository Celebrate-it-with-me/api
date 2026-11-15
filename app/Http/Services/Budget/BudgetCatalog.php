<?php

namespace App\Http\Services\Budget;

/**
 * The starting categories of a budget, by kind of event and language. The organizer edits them afterwards.
 * Each carries a stable `slug` (same across languages and kinds of event) that survives renaming: the assistant's
 * vendor detectors read it instead of the name.
 */
class BudgetCatalog
{
    private const QUINCE = [
        ['Salón', 'Venue', 'venue'],
        ['Banquete', 'Catering', 'catering'],
        ['Decoración', 'Decoration', 'decoration'],
        ['Música y DJ', 'Music and DJ', 'music'],
        ['Fotografía y video', 'Photo and video', 'photo'],
        ['Vestido y accesorios', 'Dress and accessories', 'attire'],
        ['Vals y coreografía', 'Waltz and choreography', 'waltz'],
        ['Misa o ceremonia', 'Mass or ceremony', 'ceremony'],
        ['Pastel', 'Cake', 'cake'],
        ['Transporte y limusina', 'Transport and limousine', 'transport'],
        ['Recuerdos', 'Favors', 'favors'],
        ['Invitaciones', 'Invitations', 'invitations'],
        ['Otros', 'Other', 'other'],
    ];

    private const WEDDING = [
        ['Lugar', 'Venue', 'venue'],
        ['Banquete', 'Catering', 'catering'],
        ['Decoración y flores', 'Decoration and flowers', 'decoration'],
        ['Música', 'Music', 'music'],
        ['Fotografía y video', 'Photo and video', 'photo'],
        ['Vestuario', 'Attire', 'attire'],
        ['Ceremonia', 'Ceremony', 'ceremony'],
        ['Pastel', 'Cake', 'cake'],
        ['Transporte', 'Transport', 'transport'],
        ['Recuerdos', 'Favors', 'favors'],
        ['Invitaciones', 'Invitations', 'invitations'],
        ['Otros', 'Other', 'other'],
    ];

    /** @return list<array{name: string, slug: string}> */
    public function entries(?string $eventTypeSlug, string $locale): array
    {
        $list = $eventTypeSlug === 'wedding' ? self::WEDDING : self::QUINCE;
        $index = $locale === 'en' ? 1 : 0;

        return array_map(fn (array $row) => ['name' => $row[$index], 'slug' => $row[2]], $list);
    }

    /** The slug of a starting category by its name in either language and either kind of event (null if none matches). */
    public function slugForName(string $name): ?string
    {
        $needle = mb_strtolower(trim($name));

        foreach ([...self::QUINCE, ...self::WEDDING] as [$es, $en, $slug]) {
            if (mb_strtolower($es) === $needle || mb_strtolower($en) === $needle) {
                return $slug;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function names(?string $eventTypeSlug, string $locale): array
    {
        $list = $eventTypeSlug === 'wedding' ? self::WEDDING : self::QUINCE;
        $index = $locale === 'en' ? 1 : 0;

        return array_map(fn (array $pair) => $pair[$index], $list);
    }
}
