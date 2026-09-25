<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Every controller action authorises through a Policy (CLAUDE.md).
    use AuthorizesRequests;
}
