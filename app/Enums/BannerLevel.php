<?php

namespace App\Enums;

/**
 * Level of the optional system banner (BUFFALO_BANNER_LEVEL). Sets its colour.
 */
enum BannerLevel: string
{
    case Info = 'info';
    case Restricted = 'restricted';
    case Confidential = 'confidential';
    case Secret = 'secret';
}
