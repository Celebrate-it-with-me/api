<?php

namespace App\Http\Services\Budget;

/** The starting categories of a budget, by kind of event and language. The organizer edits them afterwards. */
class BudgetCatalog
{
    private const QUINCE = [
        ['Salón', 'Venue'],
        ['Banquete', 'Catering'],
        ['Decoración', 'Decoration'],
        ['Música y DJ', 'Music and DJ'],
        ['Fotografía y video', 'Photo and video'],
        ['Vestido y accesorios', 'Dress and accessories'],
        ['Vals y coreografía', 'Waltz and choreography'],
        ['Misa o ceremonia', 'Mass or ceremony'],
        ['Pastel', 'Cake'],
        ['Transporte y limusina', 'Transport and limousine'],
        ['Recuerdos', 'Favors'],
        ['Invitaciones', 'Invitations'],
        ['Otros', 'Other'],
    ];

    private const WEDDING = [
        ['Lugar', 'Venue'],
        ['Banquete', 'Catering'],
        ['Decoración y flores', 'Decoration and flowers'],
        ['Música', 'Music'],
        ['Fotografía y video', 'Photo and video'],
        ['Vestuario', 'Attire'],
        ['Ceremonia', 'Ceremony'],
        ['Pastel', 'Cake'],
        ['Transporte', 'Transport'],
        ['Recuerdos', 'Favors'],
        ['Invitaciones', 'Invitations'],
        ['Otros', 'Other'],
    ];

    /** @return list<string> */
    public function names(?string $eventTypeSlug, string $locale): array
    {
        $list = $eventTypeSlug === 'wedding' ? self::WEDDING : self::QUINCE;
        $index = $locale === 'en' ? 1 : 0;

        return array_map(fn (array $pair) => $pair[$index], $list);
    }
}
