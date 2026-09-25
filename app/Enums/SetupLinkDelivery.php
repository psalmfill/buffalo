<?php

namespace App\Enums;

/**
 * How an account setup link reaches the user.
 */
enum SetupLinkDelivery: string
{
    /** Emailed to the user (only when mail is enabled). */
    case Email = 'email';

    /** Shown once to the administrator, who hands it over another way. */
    case Manual = 'manual';
}
