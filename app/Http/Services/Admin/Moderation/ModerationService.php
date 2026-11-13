<?php

namespace App\Http\Services\Admin\Moderation;

use App\Enums\EventCommentStatus;
use App\Http\Services\Admin\AdminAudit;
use App\Models\EventComment;
use App\Models\Events;
use App\Models\Guest;
use App\Models\SweetMemoriesImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The staff moderation queue (admin A6): comments and Sweet Memories photos in one list, with hide, restore and delete.
 */
class ModerationService
{
    public const TYPES = ['comment', 'image'];

    private const DISK = 'sweet-memories';

    public function __construct(private readonly AdminAudit $audit) {}

    // ---------------------------------------------------------------- queue

    /** @param  array{type?: string, status?: string, eventId?: int, from?: string, to?: string, search?: string}  $f */
    public function queue(array $f, int $perPage): LengthAwarePaginator
    {
        $type = $f['type'] ?? null;

        $parts = [];
        if (! $type || $type === 'comment') {
            $parts[] = $this->commentFilter($f)->toBase()->select([
                DB::raw("'comment' as type"), 'event_comments.id', 'event_comments.created_at',
            ]);
        }
        if (! $type || $type === 'image') {
            $parts[] = $this->imageFilter($f)->toBase()->select([
                DB::raw("'image' as type"), 'sweet_memories_images.id', 'sweet_memories_images.created_at',
            ]);
        }

        $union = array_shift($parts);
        foreach ($parts as $p) {
            $union->unionAll($p);
        }

        $page = DB::query()->fromSub($union, 'q')
            ->orderByDesc('created_at')->orderByDesc('id')->orderBy('type')
            ->paginate($perPage);

        $items = $this->hydrate(collect($page->items()));
        $page->setCollection($items);

        return $page;
    }

    private function commentFilter(array $f): Builder
    {
        $q = EventComment::query()
            ->when($f['eventId'] ?? null, fn ($q, $v) => $q->where('event_comments.event_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('event_comments.status', $v === 'hidden' ? EventCommentStatus::HIDDEN->value : EventCommentStatus::VISIBLE->value))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('event_comments.created_at', '>=', $v . ' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('event_comments.created_at', '<=', $v . ' 23:59:59'));

        if ($s = $this->like($f['search'] ?? null)) {
            $q->where(function (Builder $w) use ($s) {
                $w->where('event_comments.comment', 'ilike', $s)
                    ->orWhereIn('event_comments.event_id', Events::withTrashed()->where('event_name', 'ilike', $s)->select('id'))
                    ->orWhere(fn (Builder $a) => $a->whereIn('event_comments.authorable_type', $this->aliases(Guest::class))
                        ->whereIn('event_comments.authorable_id', Guest::withTrashed()->where('name', 'ilike', $s)->select('id')))
                    ->orWhere(fn (Builder $a) => $a->whereIn('event_comments.authorable_type', $this->aliases(User::class))
                        ->whereIn('event_comments.authorable_id', User::where('name', 'ilike', $s)->select('id')));
            });
        }

        return $q;
    }

    private function imageFilter(array $f): Builder
    {
        $q = SweetMemoriesImage::query()
            ->when($f['eventId'] ?? null, fn ($q, $v) => $q->where('sweet_memories_images.event_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $v === 'hidden'
                ? $q->whereNotNull('sweet_memories_images.hidden_at')
                : $q->whereNull('sweet_memories_images.hidden_at'))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('sweet_memories_images.created_at', '>=', $v . ' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('sweet_memories_images.created_at', '<=', $v . ' 23:59:59'));

        if ($s = $this->like($f['search'] ?? null)) {
            $q->where(function (Builder $w) use ($s) {
                $w->where('sweet_memories_images.title', 'ilike', $s)
                    ->orWhere('sweet_memories_images.description', 'ilike', $s)
                    ->orWhere('sweet_memories_images.image_original_name', 'ilike', $s)
                    ->orWhereIn('sweet_memories_images.event_id', Events::withTrashed()->where('event_name', 'ilike', $s)->select('id'));
            });
        }

        return $q;
    }

    private function like(?string $term): ?string
    {
        $term = trim((string) $term);

        return $term === '' ? null : '%' . addcslashes($term, '%_\\') . '%';
    }

    /** The values `authorable_type` may hold for a model: the morph alias and, for old rows, the class name. */
    private function aliases(string $class): array
    {
        return array_values(array_unique([(new $class)->getMorphClass(), $class]));
    }

    /**
     * Loads the rows of one page (a few queries, not one per row) and maps them to the API shape.
     *
     * @param  Collection<int, object>  $rows
     */
    private function hydrate(Collection $rows): Collection
    {
        $ids = fn (string $type) => $rows->where('type', $type)->pluck('id')->all();

        $comments = EventComment::whereIn('id', $ids('comment'))->get()->keyBy('id');
        $images = SweetMemoriesImage::whereIn('id', $ids('image'))->get()->keyBy('id');

        $events = Events::withTrashed()->whereIn('id', $comments->pluck('event_id')->merge($images->pluck('event_id'))->unique())
            ->get(['id', 'event_name'])->keyBy('id');

        $guestIds = $comments->filter(fn ($c) => in_array($c->authorable_type, $this->aliases(Guest::class), true))->pluck('authorable_id');
        $userIds = $comments->filter(fn ($c) => in_array($c->authorable_type, $this->aliases(User::class), true))->pluck('authorable_id')
            ->merge($comments->pluck('hidden_by'))->merge($images->pluck('hidden_by'))->filter()->unique();
        $guests = Guest::withTrashed()->whereIn('id', $guestIds)->get(['id', 'name'])->keyBy('id');
        $users = User::whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        return $rows->map(function ($row) use ($comments, $images, $events, $guests, $users) {
            if ($row->type === 'comment') {
                $m = $comments[$row->id];
                $isGuest = in_array($m->authorable_type, $this->aliases(Guest::class), true);
                $author = $isGuest ? $guests[$m->authorable_id] ?? null : $users[$m->authorable_id] ?? null;

                return $this->shape('comment', $m, $events, $users, [
                    'author' => $author ? ['type' => $isGuest ? 'guest' : 'user', 'id' => $author->id, 'name' => $author->name] : null,
                    'content' => ['text' => $m->comment, 'thumbnailUrl' => null, 'imageUrl' => null],
                    'status' => $m->status instanceof EventCommentStatus ? $m->status->value : (string) $m->status,
                    'pinned' => (bool) $m->is_pinned,
                ]);
            }

            $m = $images[$row->id];

            return $this->shape('image', $m, $events, $users, [
                // Photos carry no uploader: they are added by the event's organizer.
                'author' => null,
                'content' => [
                    'text' => $m->title,
                    'thumbnailUrl' => $this->url($m->thumbnail_path ?: $m->image_path),
                    'imageUrl' => $this->url($m->image_path),
                ],
                'status' => $m->hidden_at ? 'hidden' : 'visible',
            ]);
        })->values();
    }

    private function shape(string $type, Model $m, $events, $users, array $extra): array
    {
        $event = $events[$m->event_id] ?? null;
        $by = $m->hidden_by ? $users[$m->hidden_by] ?? null : null;

        return [
            'type' => $type,
            'id' => $m->id,
            'event' => ['id' => $m->event_id, 'name' => $event?->event_name],
            'author' => $extra['author'],
            'content' => $extra['content'],
            'status' => $extra['status'],
            'createdAt' => $m->created_at?->toIso8601String(),
            'hiddenAt' => $m->hidden_at?->toIso8601String(),
            'hiddenBy' => $m->hidden_at ? ($by ? ['id' => $by->id, 'name' => $by->name] : ($m->hidden_by ? ['id' => $m->hidden_by, 'name' => null] : null)) : null,
        ];
    }

    private function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        try {
            return Storage::disk(self::DISK)->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------- actions

    /** Loads one item, or null when it does not exist (comments already deleted count as gone). */
    public function find(string $type, int $id): ?Model
    {
        return $type === 'comment' ? EventComment::find($id) : SweetMemoriesImage::find($id);
    }

    /** @return array{changed: bool, item: array} */
    public function apply(string $action, string $type, int $id, ?string $reason, User $actor): array
    {
        return DB::transaction(function () use ($action, $type, $id, $reason, $actor) {
            $model = $this->find($type, $id);
            if (! $model) {
                throw new ModerationItemNotFound;
            }

            $before = $this->snapshot($type, $model);
            $changed = match ($action) {
                'hide' => $this->hide($type, $model, $actor),
                'restore' => $this->restore($type, $model),
                'delete' => $this->delete($type, $model),
            };

            if ($changed) {
                $this->audit->record('moderation.' . $action, [$type, $id], [
                    'event_id' => $model->event_id,
                    'reason' => $reason,
                    'before' => $before,
                    'after' => $action === 'delete' ? null : $this->snapshot($type, $model->refresh()),
                    'actor' => $actor,
                ]);
            }

            return ['changed' => $changed, 'item' => $action === 'delete' ? null : $this->hydrate(collect([(object) ['type' => $type, 'id' => $id]]))->first()];
        });
    }

    private function snapshot(string $type, Model $m): array
    {
        return [
            'status' => $type === 'comment' ? ($m->status instanceof EventCommentStatus ? $m->status->value : $m->status) : ($m->hidden_at ? 'hidden' : 'visible'),
            'hiddenAt' => $m->hidden_at?->toIso8601String(),
            'hiddenBy' => $m->hidden_by,
        ];
    }

    private function hide(string $type, Model $m, User $actor): bool
    {
        $already = $type === 'comment' ? ($m->status === EventCommentStatus::HIDDEN && $m->hidden_at) : (bool) $m->hidden_at;
        if ($already) {
            return false;
        }
        if ($type === 'comment') {
            $m->status = EventCommentStatus::HIDDEN->value;
        }
        $m->hidden_at = now();
        $m->hidden_by = $actor->id;
        $m->save();

        return true;
    }

    private function restore(string $type, Model $m): bool
    {
        if ($type === 'comment') {
            if ($m->status !== EventCommentStatus::HIDDEN && ! $m->hidden_at) {
                return false;
            }
            $m->status = EventCommentStatus::VISIBLE->value;
        } elseif (! $m->hidden_at) {
            return false;
        }
        $m->hidden_at = null;
        $m->hidden_by = null;
        $m->save();

        return true;
    }

    private function delete(string $type, Model $m): bool
    {
        if ($type === 'comment') {
            $m->delete(); // soft delete, like the organizer's

            return true;
        }

        $paths = array_filter([$m->image_path, $m->thumbnail_path]);
        // Legacy rows may share a file: only remove what no other photo still uses.
        $shared = SweetMemoriesImage::where('id', '!=', $m->id)
            ->where(fn ($q) => $q->whereIn('image_path', $paths)->orWhereIn('thumbnail_path', $paths))
            ->get(['image_path', 'thumbnail_path'])
            ->flatMap(fn ($o) => [$o->image_path, $o->thumbnail_path])->all();
        $toDelete = array_values(array_diff($paths, $shared));

        // Files first: if storage fails the row stays, so nothing is left orphaned and the action can be retried.
        try {
            if ($toDelete) {
                Storage::disk(self::DISK)->delete($toDelete);
            }
        } catch (Throwable $e) {
            Log::error('Moderation: could not delete photo files', ['id' => $m->id, 'message' => $e->getMessage()]);
            throw new RuntimeException('storage_failed', 0, $e);
        }
        $m->delete();

        return true;
    }
}
