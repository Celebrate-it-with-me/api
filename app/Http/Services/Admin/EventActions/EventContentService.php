<?php

namespace App\Http\Services\Admin\EventActions;

use App\Models\EventComment;
use App\Models\Events;
use App\Models\Guest;
use App\Models\Padrino;
use App\Models\SweetMemoriesImage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read side of the Contenido tab. Comments and photos carry the ids the moderation API (`admin/moderation/{type}/{id}`)
 * needs; guests and padrinos are read-only and their phone and email are masked.
 */
class EventContentService
{
    public const SECTIONS = ['comments', 'images', 'padrinos', 'guests'];

    public function page(Events $event, string $section, int $page, int $perPage): array
    {
        $p = match ($section) {
            'comments' => $this->comments($event, $page, $perPage),
            'images' => $this->images($event, $page, $perPage),
            'padrinos' => $this->padrinos($event, $page, $perPage),
            'guests' => $this->guests($event, $page, $perPage),
        };

        return [
            'data' => $p->items(),
            'meta' => ['section' => $section, 'page' => $p->currentPage(), 'perPage' => $p->perPage(), 'total' => $p->total(), 'lastPage' => $p->lastPage()],
        ];
    }

    private function comments(Events $event, int $page, int $perPage): LengthAwarePaginator
    {
        $p = EventComment::where('event_id', $event->id)->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        $guestAliases = array_unique([(new Guest)->getMorphClass(), Guest::class]);
        $userAliases = array_unique([(new User)->getMorphClass(), User::class]);
        $rows = $p->getCollection();
        $guests = Guest::withTrashed()->whereIn('id', $rows->whereIn('authorable_type', $guestAliases)->pluck('authorable_id'))->pluck('name', 'id');
        $users = User::whereIn('id', $rows->whereIn('authorable_type', $userAliases)->pluck('authorable_id'))->pluck('name', 'id');

        return $p->through(function (EventComment $c) use ($guestAliases, $guests, $users) {
            $isGuest = in_array($c->authorable_type, $guestAliases, true);
            $name = $isGuest ? ($guests[$c->authorable_id] ?? null) : ($users[$c->authorable_id] ?? null);

            return [
                'id' => $c->id,
                'type' => 'comment',
                'text' => $c->comment,
                'author' => $name ? ['type' => $isGuest ? 'guest' : 'user', 'name' => $name] : null,
                'status' => $c->hidden_at ? 'hidden' : 'visible',
                'createdAt' => $c->created_at?->toIso8601String(),
            ];
        });
    }

    private function images(Events $event, int $page, int $perPage): LengthAwarePaginator
    {
        return SweetMemoriesImage::where('event_id', $event->id)->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (SweetMemoriesImage $i) => [
                'id' => $i->id,
                'type' => 'image',
                'title' => $i->title,
                'thumbnailUrl' => $this->url($i->thumbnail_path ?: $i->image_path),
                'imageUrl' => $this->url($i->image_path),
                'status' => $i->hidden_at ? 'hidden' : 'visible',
                'createdAt' => $i->created_at?->toIso8601String(),
            ]);
    }

    private function padrinos(Events $event, int $page, int $perPage): LengthAwarePaginator
    {
        return Padrino::where('event_id', $event->id)->with('guest:id,email')->orderBy('sort_order')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Padrino $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'relationship' => $p->relationship,
                'status' => $p->status,
                'phone' => Mask::phone($p->phone),
                'email' => Mask::email($p->guest?->email),
            ]);
    }

    private function guests(Events $event, int $page, int $perPage): LengthAwarePaginator
    {
        return Guest::where('event_id', $event->id)->orderBy('id')->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Guest $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'isCompanion' => $g->parent_id !== null,
                'rsvpStatus' => $g->rsvp_status,
                'phone' => Mask::phone($g->phone),
                'email' => Mask::email($g->email),
            ]);
    }

    private function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        try {
            return Storage::disk('sweet-memories')->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
