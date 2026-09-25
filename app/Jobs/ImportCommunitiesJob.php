<?php

namespace App\Jobs;

use App\Actions\LocalGovernment\ImportCommunities;
use App\Models\Lga;
use App\Models\User;
use App\Notifications\CommunityImportFinished;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A large community import (more than ImportCommunitiesJob::THRESHOLD
 * rows), run on the queue in the importing user's jurisdiction context. The
 * payload (parsed rows, not the file) is encrypted on the queue.
 */
final class ImportCommunitiesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const THRESHOLD = 200;

    /** One transaction: a failed attempt leaves nothing behind; the user is told. */
    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $include
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $lgaId,
        public readonly array $rows,
        public readonly array $include,
    ) {}

    public function handle(JurisdictionContext $context, ImportCommunities $import): void
    {
        $user = User::query()->findOrFail($this->userId);
        $lga = Lga::query()->findOrFail($this->lgaId);

        // A job acting for a user runs in that user's context (CLAUDE.md).
        $result = $context->actingAs($user, fn (): array => $import($lga, $this->rows, $this->include, $user));

        $user->notify(new CommunityImportFinished($lga, $result));
    }

    public function failed(?Throwable $exception): void
    {
        $user = User::query()->find($this->userId);
        $lga = Lga::query()->find($this->lgaId);

        if ($user instanceof User && $lga instanceof Lga) {
            $user->notify(new CommunityImportFinished($lga, null));
        }
    }
}
