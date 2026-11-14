<?php

namespace App\Http\Services\Announcements;

use App\Http\Services\Admin\AdminAudit;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\User;
use Illuminate\Support\Collection;

class AnnouncementService
{
    public function __construct(private readonly AnnouncementAudience $audiences, private readonly AdminAudit $audit) {}

    /** @return array{0: Collection<int, Announcement>, 1: int} page and total */
    public function list(?string $status, int $page, int $perPage): array
    {
        $query = Announcement::query()->withCount('dismissals')
            ->when($status, fn ($q, $s) => $q->withStatus($s));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('starts_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return [$rows, $total];
    }

    public function create(array $data, User $actor): Announcement
    {
        $announcement = Announcement::create($this->attributes($data) + ['created_by' => $actor->id]);
        $this->audit->record('announcement.create', ['announcement', $announcement->id], [
            'reason' => $data['reason'], 'after' => $this->snapshot($announcement),
        ]);

        return $announcement->loadCount('dismissals');
    }

    public function update(Announcement $announcement, array $data): Announcement
    {
        $before = $this->snapshot($announcement);
        $announcement->update($this->attributes($data));
        $this->audit->record('announcement.update', ['announcement', $announcement->id], [
            'reason' => $data['reason'], 'before' => $before, 'after' => $this->snapshot($announcement->refresh()),
        ]);

        return $announcement->loadCount('dismissals');
    }

    public function delete(Announcement $announcement, string $reason): void
    {
        $this->audit->record('announcement.delete', ['announcement', $announcement->id], [
            'reason' => $reason, 'before' => $this->snapshot($announcement),
        ]);
        $announcement->delete();
    }

    /** What the organizer sees: live, in her audience, not closed by her; at most 3, most urgent first. */
    public function forUser(User $user): Collection
    {
        $dismissed = AnnouncementDismissal::query()->where('user_id', $user->id)->pluck('announcement_id');

        return Announcement::query()->live()->whereNotIn('id', $dismissed)->get()
            ->filter(fn (Announcement $a) => $this->audiences->includes($user, $a->audience, $a->upcoming_days))
            ->sortBy([
                fn ($a, $b) => Announcement::LEVEL_WEIGHT[$b->level] <=> Announcement::LEVEL_WEIGHT[$a->level],
                fn ($a, $b) => $b->starts_at <=> $a->starts_at,
                fn ($a, $b) => $b->id <=> $a->id,
            ])
            ->take(3)->values();
    }

    /** Whether she may see (and so dismiss) it: live and in her audience, closed or not. */
    public function visibleTo(User $user, int $id): ?Announcement
    {
        $a = Announcement::query()->live()->find($id);

        return $a && $this->audiences->includes($user, $a->audience, $a->upcoming_days) ? $a : null;
    }

    public function dismiss(Announcement $announcement, User $user): void
    {
        AnnouncementDismissal::query()->firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['dismissed_at' => now()],
        );
    }

    private function attributes(array $data): array
    {
        $attrs = collect($data)->only([
            'level', 'title_es', 'title_en', 'body_es', 'body_en', 'cta_label_es', 'cta_label_en', 'cta_url',
            'audience', 'starts_at', 'ends_at',
        ])->all();
        $attrs += [
            'dismissible' => (bool) ($data['dismissible'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'upcoming_days' => $data['audience'] === 'upcoming_event' ? (int) $data['upcoming_days'] : null,
            'ends_at' => $data['ends_at'] ?? null,
        ];
        foreach (['cta_label_es', 'cta_label_en', 'cta_url'] as $f) {
            $attrs[$f] = $attrs[$f] ?? null;
        }

        return $attrs;
    }

    private function snapshot(Announcement $a): array
    {
        return $a->only(['level', 'title_es', 'title_en', 'body_es', 'body_en', 'cta_label_es', 'cta_label_en', 'cta_url', 'dismissible', 'audience', 'upcoming_days', 'is_active'])
            + ['starts_at' => $a->starts_at?->toIso8601String(), 'ends_at' => $a->ends_at?->toIso8601String()];
    }
}
