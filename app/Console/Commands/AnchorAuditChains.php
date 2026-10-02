<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditChain;
use Illuminate\Console\Command;

/**
 * Records each audit chain's head hash in audit_anchors (Phase 8A.4),
 * nightly. The heads can be copied to a record kept outside the system
 * (for example a paper log): a chain rewritten after an anchor no longer
 * reaches the anchored hash.
 */
class AnchorAuditChains extends Command
{
    protected $signature = 'buffalo:audit:anchor';

    protected $description = 'Record the head hash of every audit chain';

    public function handle(): int
    {
        $anchors = AuditChain::anchor();

        $this->table(['Table', 'Head row', 'Sequence', 'Head hash'], array_map(
            fn (array $a) => [$a['table'], $a['head_row_id'] ?? '—', $a['head_seq'], $a['head_hash']],
            $anchors,
        ));
        $this->components->info(count($anchors).' anchors recorded at '.now()->format('Y-m-d H:i T').'.');

        return self::SUCCESS;
    }
}
