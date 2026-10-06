<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

/** Fixed lists of the CRM (values are stored; labels are translated when shown). */
final class Catalog
{
    /** stage => [label, default probability, tone] — the first four are the open pipeline, in order */
    public const STAGES = [
        'QUALIFICATION'      => ['Qualification',       10,  'info'],
        'SURVEY_PROPOSAL'    => ['Survey & proposal',   30,  'info'],
        'EVALUATION_TESTING' => ['Evaluation & testing', 50, 'info'],
        'NEGOTIATION'        => ['Negotiation',         75,  'violet'],
        'CLOSED_WON'         => ['Closed won',          100, 'success'],
        'CLOSED_LOST'        => ['Closed lost',         0,   'danger'],
        'ON_HOLD'            => ['On hold',             null, 'warn'],
        'CANCEL'             => ['Cancelled',           0,   'neutral'],
    ];
    public const PIPELINE = ['QUALIFICATION', 'SURVEY_PROPOSAL', 'EVALUATION_TESTING', 'NEGOTIATION'];
    public const OPEN     = ['QUALIFICATION', 'SURVEY_PROPOSAL', 'EVALUATION_TESTING', 'NEGOTIATION', 'ON_HOLD'];
    /** stages that need a written reason */
    public const NEEDS_REASON = ['CLOSED_LOST', 'CANCEL', 'ON_HOLD'];

    public const PRIORITIES = ['LOW' => 'Low', 'MEDIUM' => 'Medium', 'HIGH' => 'High'];

    public const ACTIVITY_TYPES    = ['CALL' => 'Call', 'MEETING' => 'Meeting', 'EMAIL' => 'E-mail', 'TASK' => 'Task'];
    public const ACTIVITY_STATUSES = ['PLANNED' => 'Planned', 'DONE' => 'Done', 'CANCELLED' => 'Cancelled'];
    public const CALL_DIRECTIONS   = ['OUTBOUND' => 'Outbound', 'INBOUND' => 'Inbound'];
    public const MEETING_TYPES     = ['ONSITE' => 'At our office', 'CUSTOMER_SITE' => 'At the customer', 'ONLINE' => 'Online'];

    public const CAMPAIGN_TYPES    = ['EMAIL' => 'E-mail', 'EVENT' => 'Event', 'WEBINAR' => 'Webinar', 'TRADE_SHOW' => 'Trade show', 'SOCIAL' => 'Social media', 'ADVERTISING' => 'Advertising', 'REFERRAL' => 'Referral', 'OTHER' => 'Other'];
    public const CAMPAIGN_STATUSES = ['DRAFT' => 'Draft', 'PLANNED' => 'Planned', 'ACTIVE' => 'Active', 'COMPLETED' => 'Completed', 'CANCELLED' => 'Cancelled'];

    public const SALUTATIONS = ['Mr.', 'Mrs.', 'Ms.', 'Miss', 'Dr.', 'Prof.', 'Khun'];

    public static function tr(array $map): array { return array_map(fn ($l) => __($l), $map); }

    public static function stages(): array { return array_map(fn ($s) => __($s[0]), self::STAGES); }
    public static function stageLabel(?string $s): string { return isset(self::STAGES[$s]) ? __(self::STAGES[$s][0]) : (string) $s; }
    public static function stageTone(?string $s): string { return self::STAGES[$s][2] ?? 'neutral'; }

    public static function salutations(): array { return array_combine(self::SALUTATIONS, self::SALUTATIONS); }
}
