<?php

namespace App\Enums;

/**
 * Client decisions that must be recorded before go-live (Phase 9B.6), with
 * the outcomes each allows. Recorded in `golive_decisions` (append-only):
 * who decided for the client, when, and the details.
 */
enum GoLiveDecision: string
{
    case RpoRto = 'rpo_rto';
    case Retention = 'retention';
    case Escrow = 'escrow';
    case ZapSignOff = 'zap_signoff';
    case PenetrationTest = 'pentest';
    case Quarantine = 'quarantine';
    case Mail = 'mail';
    case ReferenceData = 'reference_data';

    public function label(): string
    {
        return match ($this) {
            self::RpoRto => 'Recovery point and recovery time objectives (RPO/RTO) confirmed',
            self::Retention => 'Retention periods',
            self::Escrow => 'Key and passphrase escrow',
            self::ZapSignOff => 'OWASP ZAP accepted findings',
            self::PenetrationTest => 'Independent penetration test',
            self::Quarantine => 'Handling of quarantined malware',
            self::Mail => 'Email',
            self::ReferenceData => 'Reference data reviewed',
        };
    }

    /**
     * @return array<string, string> outcome => label
     */
    public function outcomes(): array
    {
        return match ($this) {
            self::RpoRto => ['confirmed' => 'Confirmed'],
            self::Retention => ['periods_set' => 'Retention periods set', 'keep_indefinitely' => 'Keep everything indefinitely'],
            self::Escrow => ['custodians_verified' => 'Custodians named and escrow verified at a drill'],
            self::ZapSignOff => ['signed_off' => 'Signed off'],
            self::PenetrationTest => ['completed' => 'Completed', 'deferred' => 'Deferred'],
            self::Quarantine => ['decided' => 'Decided'],
            self::Mail => ['enabled' => 'Mail enabled', 'not_enabled' => 'Mail not enabled'],
            self::ReferenceData => ['signed_off' => 'Signed off'],
        };
    }

    /** What the details must say (shown on the form). */
    public function detailsHint(): string
    {
        return match ($this) {
            self::RpoRto => 'The agreed RPO and RTO, for example "RPO 5 minutes, RTO 4 hours".',
            self::Retention => 'The periods per record type, or why everything is kept indefinitely.',
            self::Escrow => 'The custodians (names and roles) and the date of the drill at which the escrowed keys and passphrases were verified.',
            self::ZapSignOff => 'Who reviewed docs/security/zap-accepted.md and the version reviewed.',
            self::PenetrationTest => 'Completed: date, tester and the summary report reference. Deferred: the reason and the planned date.',
            self::Quarantine => 'Who reviews quarantined files, how they are handled and for how long they are kept.',
            self::Mail => 'The mail relay used, or how notifications and setup links reach users without mail.',
            self::ReferenceData => 'Which reference lists were reviewed and any changes agreed. The current reference data version is recorded automatically.',
        };
    }
}
