<?php

namespace App\Support\Persons\Merge;

use RuntimeException;

/**
 * A merge or undo that must not happen (Phase 10A): a NIN conflict,
 * different states, a missing confirmation, a record already merged, a
 * manifest row that can no longer move back. Nothing has been changed when
 * it is thrown (the transaction rolls back). The message is shown to the
 * executor; it names no person.
 */
final class MergeBlocked extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'merge')
    {
        parent::__construct($message);
    }
}
