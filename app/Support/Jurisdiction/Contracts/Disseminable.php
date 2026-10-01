<?php

namespace App\Support\Jurisdiction\Contracts;

use App\Enums\NotificationSeverity;
use App\Support\Classification\Contracts\Classified;

/**
 * A record the owning state may disseminate to other states or LGAs
 * (Phase 7A). ONLY ThreatReport and Flashpoint implement this (enforced by
 * an architecture test): the DisseminationResolver widens the jurisdiction
 * scope for these two models and nothing else.
 */
interface Disseminable extends Classified, JurisdictionScoped
{
    /** The short type stored in record_disseminations.subject_type. */
    public function disseminationType(): string;

    /** Reference number or name, safe for notification payloads. */
    public function disseminationLabel(): string;

    /** "Threat report" or "Flashpoint" / "Blackspot". */
    public function disseminationKind(): string;

    /** Internal path of the record page. */
    public function disseminationPath(): string;

    /** Severity of dissemination notices, by urgency or concern level. */
    public function disseminationSeverity(): NotificationSeverity;

    /** Urgent enough to prompt for dissemination when the area crosses a border. */
    public function warrantsDissemination(): bool;

    /** Recipients are reminded after this many hours without acknowledgement. */
    public function acknowledgementHours(): int;
}
