<?php

namespace App\Support\Jurisdiction;

enum ContextMode: string
{
    /** Scoped to one user's jurisdiction. */
    case User = 'user';

    /** No restriction: console commands, queued jobs, runAsSystem(). */
    case System = 'system';

    /** Nothing is visible: HTTP requests without an authenticated user. */
    case None = 'none';
}
