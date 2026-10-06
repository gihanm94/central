<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support\Data;

use App\Core\Support\DB;
use App\Modules\CRM\Support\Catalog;

/** What the data tools may touch, and what each column of those tables looks like. */
final class Schema
{
    /** table => [label, code prefix (null = no code column)] */
    public const TABLES = [
        'leads'                => ['Leads', 'LD'],
        'contacts'             => ['Contacts', 'CT'],
        'contact_mobiles'      => ['Contact mobile numbers', null],
        'opportunities'        => ['Opportunities', 'OP'],
        'opportunity_products' => ['Opportunity products', null],
        'opportunity_steps'    => ['Opportunity stage history and next steps', null],
        'activities'           => ['Activities', 'AC'],
        'campaigns'            => ['Campaigns', 'CP'],
        'projects'             => ['Projects', 'PJ'],
        'project_members'      => ['Project responsible people', null],
        'tasks'                => ['Tasks', 'TK'],
        'task_assignees'       => ['Task responsible people', null],
        'comments'             => ['Comments', null],
        'industries'           => ['Industries', null],
        'lead_sources'         => ['Lead sources', null],
        'lead_industries'      => ['Lead ↔ industries', null],
        'lead_lead_sources'    => ['Lead ↔ lead sources', null],
        'products'             => ['Products', null],
        'currencies'           => ['Currencies', null],
    ];

    /** column => [table, lookup columns, connection] — a value in such a column may be an id or a name / code / e-mail */
    public const LOOKUPS = [
        'lead_id' => ['leads', ['code', 'name_en', 'name_th'], 'crm'], 'company_id' => ['leads', ['code', 'name_en', 'name_th'], 'crm'],
        'contact_id' => ['contacts', ['code', 'name_en'], 'crm'], 'opportunity_id' => ['opportunities', ['code', 'name'], 'crm'],
        'project_id' => ['projects', ['code', 'name'], 'crm'], 'task_id' => ['tasks', ['code', 'name'], 'crm'],
        'industry_id' => ['industries', ['name'], 'crm'], 'lead_source_id' => ['lead_sources', ['name'], 'crm'], 'product_id' => ['products', ['code', 'name'], 'crm'],
        'owner_id' => ['users', ['email', 'name'], 'core'], 'created_by' => ['users', ['email', 'name'], 'core'], 'updated_by' => ['users', ['email', 'name'], 'core'],
        'user_id' => ['users', ['email', 'name'], 'core'], 'added_by' => ['users', ['email', 'name'], 'core'], 'done_by' => ['users', ['email', 'name'], 'core'],
        'department_id' => ['departments', ['name'], 'core'],
    ];

    private static array $cache = [];

    public static function label(string $table): string { return __(self::TABLES[$table][0] ?? $table); }

    public static function allowed(string $table): bool { return isset(self::TABLES[$table]); }

    /** @return array<string, array{name: string, type: string, length: ?int, required: bool, identity: bool, has_default: bool}> */
    public static function columns(string $table): array
    {
        if (isset(self::$cache[$table])) {
            return self::$cache[$table];
        }
        if (! self::allowed($table)) {
            throw new \InvalidArgumentException('Unknown table');
        }
        $out = [];
        foreach (DB::select("SELECT column_name, data_type, character_maximum_length, is_nullable, column_default, is_identity FROM information_schema.columns
                              WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position", [$table], 'crm') as $c) {
            if ($c['column_name'] === 'deleted_at') {
                continue;
            }
            $identity = $c['is_identity'] === 'YES' || str_starts_with((string) $c['column_default'], 'nextval(');
            $out[$c['column_name']] = [
                'name' => $c['column_name'], 'type' => $c['data_type'], 'length' => $c['character_maximum_length'] !== null ? (int) $c['character_maximum_length'] : null,
                'required' => $c['is_nullable'] === 'NO' && $c['column_default'] === null && ! $identity, 'identity' => $identity, 'has_default' => $c['column_default'] !== null,
            ];
        }

        return self::$cache[$table] = $out;
    }

    /** code => label for columns that only take a fixed list of values; null for free columns. */
    public static function choices(string $table, string $column): ?array
    {
        $map = match ($table.'.'.$column) {
            'opportunities.opportunity_stage', 'opportunity_steps.stage', 'opportunity_steps.from_stage' => Catalog::stages(),
            'opportunities.priority' => Catalog::tr(Catalog::PRIORITIES),
            'activities.activity_type' => Catalog::tr(Catalog::ACTIVITY_TYPES),
            'activities.status' => Catalog::tr(Catalog::ACTIVITY_STATUSES),
            'activities.call_direction' => Catalog::tr(Catalog::CALL_DIRECTIONS),
            'activities.meeting_type' => Catalog::tr(Catalog::MEETING_TYPES),
            'campaigns.type' => Catalog::tr(Catalog::CAMPAIGN_TYPES),
            'campaigns.status' => Catalog::tr(Catalog::CAMPAIGN_STATUSES),
            'projects.status' => Catalog::tr(Catalog::PROJECT_STATUSES),
            'tasks.status' => Catalog::tr(Catalog::TASK_STATUSES),
            default => null,
        };

        return $map;
    }

    /** Which source column (by position) probably belongs to each table column: [column => index|null] */
    public static function suggest(string $table, array $header): array
    {
        $norm = fn (string $s) => preg_replace('/[^a-z0-9\x{0E00}-\x{0E7F}]+/u', '', mb_strtolower($s));
        $heads = [];
        foreach ($header as $i => $h) { $heads[$norm((string) $h)] ??= $i; }
        $alias = [
            'name_en' => ['name', 'nameenglish', 'company', 'companyname', 'customer', 'fullname', 'nameen'], 'name_th' => ['namethai', 'thainame', 'nameth'],
            'lead_id' => ['lead', 'company', 'companyname', 'customer', 'leadname'], 'contact_id' => ['contact', 'contactname'], 'opportunity_id' => ['opportunity', 'deal'],
            'project_id' => ['project'], 'owner_id' => ['owner', 'ownername', 'assignedto', 'responsible', 'salesperson', 'owneremail'], 'department_id' => ['department', 'dept'],
            'opportunity_stage' => ['stage', 'status'], 'phone' => ['tel', 'telephone', 'phonenumber'], 'mobile' => ['mobilenumber', 'cell'],
            'description' => ['details', 'notes', 'note', 'remark', 'remarks'], 'topic' => ['subject', 'title'], 'start_at' => ['start', 'date', 'when'],
            'amount' => ['value', 'price', 'total'], 'tax_id' => ['taxno', 'taxnumber', 'taxid'], 'job_title' => ['position', 'title'], 'email' => ['mail', 'emailaddress'],
            'created_at' => ['created', 'createddate'], 'created_by' => ['createdby'], 'number' => ['mobile', 'phone'],
        ];
        $out = [];
        foreach (self::columns($table) as $name => $c) {
            $cands = array_merge([$norm($name)], array_map($norm, $alias[$name] ?? []));
            $hit   = null;
            foreach ($cands as $k) {
                if (isset($heads[$k])) { $hit = $heads[$k]; break; }
            }
            $out[$name] = $hit;
        }

        return $out;
    }

    /** Is it a column the person is expected to supply (not an audit / generated one)? Used to order the mapping screen. */
    public static function audit(string $column): bool
    {
        return in_array($column, ['id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'owner_id', 'department_id'], true);
    }
}
