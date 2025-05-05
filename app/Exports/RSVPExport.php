<?php

namespace App\Exports;

use App\Models\Guest;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RSVPExport implements FromCollection, WithHeadings
{
    
    public int $eventId;
    public string $status;
    public string $search;
    public int $perPage;
    public int $page;
    
    public function __construct(
        int $eventId,
        string $status,
        string $search,
        int $perPage = 10,
        int $page = 1
    )
    {
        $this->eventId = $eventId;
        $this->status = $status;
        $this->search = $search;
        $this->perPage = $perPage;
        $this->page = $page;
    }
    
    public function collection()
    {
        return Guest::query()
            ->where('event_id', $this->eventId)
            ->whereNull('parent_id')
            ->with('companions')
            ->when($this->status, fn ($q) => $q->where('rsvp_status', $this->status))
            ->when($this->search !== '', function ($q) {
                $q->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhere('phone', 'like', "%{$this->search}%")
                        ->orWhereHas('companions', fn ($q) => $q->where('name', 'like', "%{$this->search}%"));
                });
            })
            ->orderBy('name')
            ->forPage($this->page, $this->perPage)
            ->get()
            ->map(function ($guest) {
                $companions = $guest->companions->map(fn ($c) => $c->name)->join(', ');
                return [
                    'Name' => $guest->name,
                    'Email' => $guest->email,
                    'Phone' => $guest->phone,
                    'Status' => $guest->rsvp_status,
                    'Companions' => $companions,
                ];
            });
    }
    
    public function headings(): array
    {
        return ['Name', 'Email', 'Phone', 'Status', 'Companions'];
    }
}
