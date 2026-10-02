<?php

namespace App\Enums;

/**
 * Events recorded in the append-only login_histories table.
 */
enum LoginEvent: string
{
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case TwoFactorFailed = 'two_factor_failed';
    case Locked = 'locked';
    case Logout = 'logout';
    case SessionExpired = 'session_expired';
    case SignedOutElsewhere = 'signed_out_elsewhere';
    case SetupCompleted = 'setup_completed';
    // Step-up authentication for sensitive actions (Phase 8B.2).
    case StepUpSuccess = 'step_up_success';
    case StepUpFailed = 'step_up_failed';
}
