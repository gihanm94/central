<?php
declare(strict_types=1);

namespace App\Modules\CRM\Api;

use App\Core\Api\Controller;
use App\Core\Api\Kernel;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Modules\CRM\Controllers\ActivityController;
use App\Modules\CRM\Controllers\ContactController;
use App\Modules\CRM\Controllers\LeadController;
use App\Modules\CRM\Controllers\OpportunityController;
use App\Modules\CRM\Controllers\TaskController;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Stages;
use App\Modules\CRM\Support\Targets;

/**
 * CRM for the mobile app. Every list and record goes through the same visibility as the website
 * (Access::visible: own, department, shared, project member …) and the same permissions.
 * Writes reuse the website's own save actions (validation, owner, linked-record checks, Google
 * Calendar sync, activity log) so mobile can never do more than the website.
 */
class CrmApi extends Controller
{
    private const C = 'crm';

    private const LEAD = ['id', 'code', 'name_en', 'name_th', 'tax_id', 'phone', 'mobile', 'fax', 'address', 'city', 'province', 'country', 'zipcode',
        'is_register', 'is_government', 'owner_id', 'owner', 'department_id', 'department', 'created_at', 'updated_at'];
    private const CONTACT = ['id', 'code', 'salutation', 'name_en', 'name_th', 'job_title', 'department', 'phone', 'phone_ext', 'email', 'lead_id', 'lead_name',
        'owner_id', 'owner', 'updated_at'];
    private const OPPORTUNITY = ['id', 'code', 'name', 'opportunity_stage', 'stage_label', 'probability', 'amount', 'currency', 'priority', 'follow_at', 'close_at',
        'cancel_reason', 'lead_id', 'lead_name', 'contact_id', 'contact_name', 'owner_id', 'owner', 'updated_at'];
    private const ACTIVITY = ['id', 'code', 'activity_type', 'topic', 'status', 'start_at', 'call_direction', 'call_duration', 'meeting_type', 'meeting_duration',
        'meeting_location', 'notify_me', 'notify_before', 'description', 'lead_id', 'lead_name', 'contact_id', 'contact_name', 'opportunity_id', 'opportunity_name',
        'owner_id', 'owner', 'updated_at'];
    private const TASK = ['id', 'code', 'name', 'description', 'status', 'start_date', 'end_date', 'project_id', 'project_name', 'assignees', 'owner_id', 'owner', 'updated_at'];

    /** Visibility + not deleted, for table alias t. */
    private function scope(string $type, string $resource): array
    {
        $this->need($resource);
        $s = Access::visible($this->user(), 't', $type);

        return ['t.deleted_at IS NULL AND ('.$s['sql'].')', $s['params']];
    }

    /** Adds owner / department names (core database) to rows. */
    private function names(array $rows): array
    {
        $users = Access::names('users', array_column($rows, 'owner_id'));
        $deps = Access::names('departments', array_column($rows, 'department_id'));
        foreach ($rows as &$r) {
            $r['owner'] = $users[$r['owner_id'] ?? 0] ?? null;
            if (array_key_exists('department_id', $r)) {
                $r['department'] ??= $deps[$r['department_id'] ?? 0] ?? null;
            }
        }

        return $rows;
    }

    private function list(string $sql, array $params, string $order, array $keys): array
    {
        $page = $this->paged($sql, $params, $order, self::C);
        $page['data'] = array_map(fn ($r) => self::pick($r, $keys), $this->names($page['data']));

        return $page;
    }

    private function one(string $sql, array $params, array $keys): array
    {
        $row = DB::first($sql, $params, self::C) ?? $this->notFound();

        return self::pick($this->names([$row])[0], $keys);
    }

    /* ------------------------------------------------------------------ leads */

    public function leads(): array
    {
        [$w, $p] = $this->scope('lead', 'crm_leads');
        if (($q = $this->q()) !== '') {
            $w .= ' AND (t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.code ILIKE ? OR t.tax_id ILIKE ? OR t.phone ILIKE ? OR t.mobile ILIKE ?)';
            array_push($p, ...array_fill(0, 6, $this->like($q)));
        }
        if (Request::query('mine') === '1') { $w .= ' AND t.owner_id = ?'; $p[] = $this->user()->id; }

        return $this->list("SELECT t.* FROM leads t WHERE {$w}", $p, 't.updated_at DESC, t.id DESC', self::LEAD);
    }

    /** A lead with its visible contacts, open opportunities and latest activities. */
    public function lead(int $id): array
    {
        [$w, $p] = $this->scope('lead', 'crm_leads');
        $lead = $this->one("SELECT t.* FROM leads t WHERE t.id = ? AND {$w}", [$id, ...$p], self::LEAD);
        $out = ['data' => $lead];
        if ($this->user()->can('crm_contacts', 'view')) {
            [$cw, $cp] = $this->scope('contact', 'crm_contacts');
            $out['contacts'] = array_map(fn ($r) => self::pick($r, self::CONTACT), $this->names(DB::select("SELECT t.* FROM contacts t WHERE t.lead_id = ? AND {$cw} ORDER BY t.name_en LIMIT 50", [$id, ...$cp], self::C)));
        }
        if ($this->user()->can('crm_opportunities', 'view')) {
            [$ow, $op] = $this->scope('opportunity', 'crm_opportunities');
            $out['opportunities'] = array_map(fn ($r) => $this->opportunityOut($r), $this->names(DB::select("SELECT t.* FROM opportunities t WHERE t.lead_id = ? AND {$ow} ORDER BY t.updated_at DESC LIMIT 50", [$id, ...$op], self::C)));
        }
        if ($this->user()->can('crm_activities', 'view')) {
            [$aw, $ap] = $this->scope('activity', 'crm_activities');
            $out['activities'] = array_map(fn ($r) => self::pick($r, self::ACTIVITY), $this->names(DB::select("SELECT t.* FROM activities t WHERE t.lead_id = ? AND {$aw} ORDER BY t.start_at DESC NULLS LAST LIMIT 20", [$id, ...$ap], self::C)));
        }

        return $out;
    }

    /* --------------------------------------------------------------- contacts */

    public function contacts(): array
    {
        [$w, $p] = $this->scope('contact', 'crm_contacts');
        if (($q = $this->q()) !== '') {
            $w .= ' AND (t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.email ILIKE ? OR t.phone ILIKE ? OR t.code ILIKE ?)';
            array_push($p, ...array_fill(0, 5, $this->like($q)));
        }
        if ($lead = (int) Request::query('lead_id')) { $w .= ' AND t.lead_id = ?'; $p[] = $lead; }

        return $this->list("SELECT t.*, l.name_en AS lead_name FROM contacts t LEFT JOIN leads l ON l.id = t.lead_id WHERE {$w}", $p, 't.name_en, t.id', self::CONTACT);
    }

    public function contact(int $id): array
    {
        [$w, $p] = $this->scope('contact', 'crm_contacts');
        $c = $this->one("SELECT t.*, l.name_en AS lead_name FROM contacts t LEFT JOIN leads l ON l.id = t.lead_id WHERE t.id = ? AND {$w}", [$id, ...$p], self::CONTACT);
        $c['mobiles'] = DB::select('SELECT number, ext, label, is_primary FROM contact_mobiles WHERE contact_id = ? ORDER BY is_primary DESC, id', [$id], self::C);

        return ['data' => $c];
    }

    /* ---------------------------------------------------------- opportunities */

    private function opportunityOut(array $r): array
    {
        $r['stage_label'] = __(Catalog::STAGES[$r['opportunity_stage']][0] ?? (string) $r['opportunity_stage']);

        return self::pick($r, self::OPPORTUNITY);
    }

    public function opportunities(): array
    {
        [$w, $p] = $this->scope('opportunity', 'crm_opportunities');
        if (($q = $this->q()) !== '') {
            $w .= ' AND (t.name ILIKE ? OR t.code ILIKE ? OR l.name_en ILIKE ?)';
            array_push($p, ...array_fill(0, 3, $this->like($q)));
        }
        if (($stage = (string) Request::query('stage')) !== '' && isset(Catalog::STAGES[$stage])) { $w .= ' AND t.opportunity_stage = ?'; $p[] = $stage; }
        if (Request::query('open') === '1') { $w .= " AND t.opportunity_stage IN ('".implode("', '", Catalog::OPEN)."')"; }
        if ($lead = (int) Request::query('lead_id')) { $w .= ' AND t.lead_id = ?'; $p[] = $lead; }
        if (Request::query('mine') === '1') { $w .= ' AND t.owner_id = ?'; $p[] = $this->user()->id; }
        $page = $this->paged("SELECT t.*, l.name_en AS lead_name, c.name_en AS contact_name FROM opportunities t LEFT JOIN leads l ON l.id = t.lead_id LEFT JOIN contacts c ON c.id = t.contact_id WHERE {$w}",
            $p, 't.updated_at DESC, t.id DESC', self::C);
        $page['data'] = array_map(fn ($r) => $this->opportunityOut($r), $this->names($page['data']));

        return $page;
    }

    public function opportunity(int $id): array
    {
        [$w, $p] = $this->scope('opportunity', 'crm_opportunities');
        $row = DB::first("SELECT t.*, l.name_en AS lead_name, c.name_en AS contact_name FROM opportunities t LEFT JOIN leads l ON l.id = t.lead_id LEFT JOIN contacts c ON c.id = t.contact_id
            WHERE t.id = ? AND {$w}", [$id, ...$p], self::C) ?? $this->notFound();

        return ['data' => $this->opportunityOut($this->names([$row])[0])];
    }

    /* ------------------------------------------------------------- activities */

    private const ACTIVITY_SQL = 'SELECT t.*, l.name_en AS lead_name, c.name_en AS contact_name, o.name AS opportunity_name FROM activities t
        LEFT JOIN leads l ON l.id = t.lead_id LEFT JOIN contacts c ON c.id = t.contact_id LEFT JOIN opportunities o ON o.id = t.opportunity_id';

    /** ?status= ?type= ?from=YYYY-MM-DD ?to=YYYY-MM-DD ?mine=1 ?lead_id= */
    public function activities(): array
    {
        [$w, $p] = $this->scope('activity', 'crm_activities');
        if (($q = $this->q()) !== '') { $w .= ' AND (t.topic ILIKE ? OR l.name_en ILIKE ?)'; array_push($p, $this->like($q), $this->like($q)); }
        if (isset(Catalog::ACTIVITY_STATUSES[(string) Request::query('status')])) { $w .= ' AND t.status = ?'; $p[] = Request::query('status'); }
        if (isset(Catalog::ACTIVITY_TYPES[(string) Request::query('type')])) { $w .= ' AND t.activity_type = ?'; $p[] = Request::query('type'); }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) Request::query($k))) { $w .= " AND t.start_at {$op} ?::date".($k === 'to' ? ' + 1' : ''); $p[] = Request::query($k); }
        }
        if (Request::query('mine') === '1') { $w .= ' AND t.owner_id = ?'; $p[] = $this->user()->id; }
        if ($lead = (int) Request::query('lead_id')) { $w .= ' AND t.lead_id = ?'; $p[] = $lead; }

        return $this->list(self::ACTIVITY_SQL." WHERE {$w}", $p, 't.start_at DESC NULLS LAST, t.id DESC', self::ACTIVITY);
    }

    public function activity(int $id): array
    {
        [$w, $p] = $this->scope('activity', 'crm_activities');

        return ['data' => $this->one(self::ACTIVITY_SQL." WHERE t.id = ? AND {$w}", [$id, ...$p], self::ACTIVITY)];
    }

    private const ACTIVITY_INPUT = ['activity_type', 'status', 'topic', 'start_at', 'call_direction', 'call_duration', 'meeting_type', 'meeting_duration',
        'meeting_location', 'lead_id', 'contact_id', 'opportunity_id', 'notify_me', 'notify_before', 'description'];

    /** Log a call / meeting. Same rules as the website form (lead required, linked records must be visible to you …). */
    public function createActivity(): array
    {
        $r = Kernel::delegate(fn () => (new ActivityController())->store(), $this->input(self::ACTIVITY_INPUT));

        return $this->activity((int) $r['id']) + ['_status' => 201];
    }

    /** Change an activity; fields left out keep their value. */
    public function updateActivity(int $id): array
    {
        $old = $this->activity($id)['data'];
        $input = $this->input(self::ACTIVITY_INPUT) + array_intersect_key($old, array_flip(self::ACTIVITY_INPUT));
        Kernel::delegate(fn () => (new ActivityController())->update($id), $input);

        return $this->activity($id);
    }

    /* ------------------------------------------------------------ new leads, contacts, opportunities */

    private const LEAD_INPUT = ['name_en', 'name_th', 'code', 'tax_id', 'phone', 'mobile', 'fax', 'address', 'city', 'province', 'country', 'zipcode',
        'is_register', 'is_government', 'description'];
    private const CONTACT_INPUT = ['lead_id', 'salutation', 'name_en', 'name_th', 'job_title', 'department', 'code', 'email', 'phone', 'phone_ext',
        'additional_contact', 'description'];
    private const OPPORTUNITY_INPUT = ['name', 'lead_id', 'contact_id', 'opportunity_stage', 'priority', 'code', 'amount', 'currency', 'probability',
        'follow_at', 'close_at', 'description'];

    /** Choices for the forms: the stages this person may use, priorities, currencies, salutations. */
    public function options(): array
    {
        $this->need('crm_leads');
        $pairs = fn (array $a) => array_map(fn ($k, $v) => ['value' => (string) $k, 'label' => (string) $v], array_keys($a), array_values($a));

        return ['data' => [
            'stages' => $pairs(Stages::options($this->user())),
            'priorities' => $pairs(Catalog::tr(Catalog::PRIORITIES)),
            'currencies' => $pairs(array_column(DB::select("SELECT code, code || ' · ' || name AS label FROM currencies ORDER BY is_base DESC, code", [], self::C), 'label', 'code')),
            'salutations' => Catalog::SALUTATIONS,
            'stage_names' => Stages::forMobile(),
        ]];
    }

    /** Same form rules as the website: you become the owner, linked records must be visible to you. */
    public function createLead(): array
    {
        $r = Kernel::delegate(fn () => (new LeadController())->store(), $this->input(self::LEAD_INPUT));

        return $this->lead((int) $r['id']) + ['_status' => 201];
    }

    public function createContact(): array
    {
        $r = Kernel::delegate(fn () => (new ContactController())->store(), $this->input(self::CONTACT_INPUT));

        return $this->contact((int) $r['id']) + ['_status' => 201];
    }

    public function createOpportunity(): array
    {
        $r = Kernel::delegate(fn () => (new OpportunityController())->store(), $this->input(self::OPPORTUNITY_INPUT));

        return $this->opportunity((int) $r['id']) + ['_status' => 201];
    }

    /** {status: PLANNED|DONE|CANCELLED} */
    public function activityStatus(int $id): array
    {
        $this->activity($id);                                     // 404 when not visible
        Kernel::delegate(fn () => (new ActivityController())->setStatus($id), $this->input(['status']), true);

        return $this->activity($id);
    }

    /* ------------------------------------------------------------------ tasks */

    /** ?mine=1 (assigned to me) ?status= ?project_id= */
    public function tasks(): array
    {
        [$w, $p] = $this->scope('task', 'crm_tasks');
        if (($q = $this->q()) !== '') { $w .= ' AND (t.name ILIKE ? OR pr.name ILIKE ?)'; array_push($p, $this->like($q), $this->like($q)); }
        if (isset(Catalog::TASK_STATUSES[(string) Request::query('status')])) { $w .= ' AND t.status = ?'; $p[] = Request::query('status'); }
        if (Request::query('open') === '1') { $w .= " AND t.status NOT IN ('DONE', 'CANCELLED')"; }
        if (Request::query('mine') === '1') { $w .= ' AND EXISTS (SELECT 1 FROM task_assignees a WHERE a.task_id = t.id AND a.user_id = ?)'; $p[] = $this->user()->id; }
        if ($project = (int) Request::query('project_id')) { $w .= ' AND t.project_id = ?'; $p[] = $project; }
        $page = $this->paged("SELECT t.*, pr.name AS project_name FROM tasks t LEFT JOIN projects pr ON pr.id = t.project_id WHERE {$w}", $p, 't.end_date NULLS LAST, t.id DESC', self::C);
        $page['data'] = array_map(fn ($r) => self::pick($r, self::TASK), $this->assignees($this->names($page['data'])));

        return $page;
    }

    public function task(int $id): array
    {
        [$w, $p] = $this->scope('task', 'crm_tasks');
        $row = DB::first("SELECT t.*, pr.name AS project_name FROM tasks t LEFT JOIN projects pr ON pr.id = t.project_id WHERE t.id = ? AND {$w}", [$id, ...$p], self::C) ?? $this->notFound();

        return ['data' => self::pick($this->assignees($this->names([$row]))[0], self::TASK)];
    }

    private function assignees(array $rows): array
    {
        $ids = array_column($rows, 'id');
        if (! $ids) {
            return $rows;
        }
        $links = DB::select('SELECT task_id, user_id FROM task_assignees WHERE task_id IN ('.implode(',', array_map('intval', $ids)).')', [], self::C);
        $names = Access::names('users', array_column($links, 'user_id'));
        foreach ($rows as &$r) {
            $r['assignees'] = array_values(array_map(fn ($l) => ['id' => (int) $l['user_id'], 'name' => $names[$l['user_id']] ?? null], array_filter($links, fn ($l) => (int) $l['task_id'] === (int) $r['id'])));
        }

        return $rows;
    }

    /** {status: TODO|IN_PROGRESS|BLOCKED|DONE|CANCELLED} */
    public function taskStatus(int $id): array
    {
        $this->task($id);
        Kernel::delegate(fn () => (new TaskController())->setStatus($id), $this->input(['status']), true);

        return $this->task($id);
    }

    /* ----------------------------------------------------------- search, stats */

    /** One box for the app: visible leads, contacts and opportunities matching ?q= (5 each). */
    public function search(): array
    {
        $q = $this->q();
        if (mb_strlen($q) < 2) {
            return ['data' => []];
        }
        $like = $this->like($q);
        $out = [];
        $u = $this->user();
        $sets = [
            'leads' => ['lead', 'crm_leads', "SELECT t.id, t.name_en AS label, t.code AS sub FROM leads t WHERE %s AND (t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.code ILIKE ? OR t.tax_id ILIKE ?) ORDER BY t.name_en LIMIT 5", 4],
            'contacts' => ['contact', 'crm_contacts', "SELECT t.id, t.name_en AS label, coalesce(t.email, t.phone) AS sub FROM contacts t WHERE %s AND (t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.email ILIKE ? OR t.phone ILIKE ?) ORDER BY t.name_en LIMIT 5", 4],
            'opportunities' => ['opportunity', 'crm_opportunities', "SELECT t.id, t.name AS label, t.opportunity_stage AS sub FROM opportunities t WHERE %s AND (t.name ILIKE ? OR t.code ILIKE ?) ORDER BY t.updated_at DESC LIMIT 5", 2],
        ];
        foreach ($sets as $key => [$type, $resource, $sql, $n]) {
            if (! $u->can($resource, 'view')) {
                continue;
            }
            [$w, $p] = $this->scope($type, $resource);
            $out[$key] = DB::select(sprintf($sql, $w), [...$p, ...array_fill(0, $n, $like)], self::C);
        }

        return ['data' => $out];
    }

    /** My CRM at a glance — counted over what I may see. */
    public function stats(): array
    {
        $u = $this->user();
        $out = [];
        if ($u->can('crm_opportunities', 'view')) {
            [$w, $p] = $this->scope('opportunity', 'crm_opportunities');
            // amounts per currency, plus the same converted to the base currency (base_*) so rows can be added up
            $base = "coalesce(c.rate_to_base, 1)";
            $out['base_currency'] = Targets::baseCurrency();
            $out['pipeline'] = DB::select("SELECT t.opportunity_stage AS stage, t.currency, count(*) AS count, coalesce(sum(t.amount), 0) AS amount,
                coalesce(sum(t.amount * coalesce(t.probability, 0) / 100), 0) AS weighted,
                round(coalesce(sum(t.amount * {$base}), 0), 2) AS base_amount, round(coalesce(sum(t.amount * coalesce(t.probability, 0) / 100 * {$base}), 0), 2) AS base_weighted
                FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency
                WHERE {$w} AND t.opportunity_stage IN ('".implode("', '", Catalog::OPEN)."') GROUP BY 1, 2 ORDER BY 1, 2", $p, self::C);
            $out['won_this_year'] = DB::select("SELECT t.currency, count(*) AS count, coalesce(sum(t.amount), 0) AS amount, round(coalesce(sum(t.amount * {$base}), 0), 2) AS base_amount
                FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency
                WHERE {$w} AND t.opportunity_stage = 'CLOSED_WON' AND t.close_at >= date_trunc('year', now()) GROUP BY 1", $p, self::C);
        }
        if ($u->can('crm_activities', 'view')) {
            [$w, $p] = $this->scope('activity', 'crm_activities');
            $out['activities'] = DB::first("SELECT
                count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at < now()) AS overdue,
                count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at::date = CURRENT_DATE) AS today,
                count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at >= now() AND t.start_at < now() + interval '7 days') AS next_7_days,
                count(*) FILTER (WHERE t.status = 'DONE' AND t.start_at >= date_trunc('month', now())) AS done_this_month
                FROM activities t WHERE {$w} AND t.owner_id = ?", [...$p, $u->id], self::C);
        }
        if ($u->can('crm_tasks', 'view')) {
            [$w, $p] = $this->scope('task', 'crm_tasks');
            $out['my_tasks'] = DB::first("SELECT count(*) FILTER (WHERE t.status NOT IN ('DONE', 'CANCELLED')) AS open,
                count(*) FILTER (WHERE t.status NOT IN ('DONE', 'CANCELLED') AND t.end_date < CURRENT_DATE) AS late
                FROM tasks t WHERE {$w} AND EXISTS (SELECT 1 FROM task_assignees a WHERE a.task_id = t.id AND a.user_id = ?)", [...$p, $u->id], self::C);
        }
        // this quarter for the Home "performance" card: new leads, deals won / lost, and activities per day (last 8 days)
        $q = [];
        if ($u->can('crm_leads', 'view')) {
            [$w, $p] = $this->scope('lead', 'crm_leads');
            $q['leads'] = (int) DB::scalar("SELECT count(*) FROM leads t WHERE {$w} AND t.created_at >= date_trunc('quarter', now())", $p, self::C);
        }
        if ($u->can('crm_opportunities', 'view')) {
            [$w, $p] = $this->scope('opportunity', 'crm_opportunities');
            $r = DB::first("SELECT count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON') AS won, count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST') AS lost
                FROM opportunities t WHERE {$w} AND COALESCE(t.close_at, t.updated_at::date) >= date_trunc('quarter', now())", $p, self::C) ?? [];
            $q['won'] = (int) ($r['won'] ?? 0);
            $q['lost'] = (int) ($r['lost'] ?? 0);
        }
        if ($u->can('crm_activities', 'view')) {
            [$w, $p] = $this->scope('activity', 'crm_activities');
            $rows = DB::select("SELECT t.start_at::date AS day, count(*) AS n FROM activities t WHERE {$w} AND t.owner_id = ?
                AND t.start_at >= CURRENT_DATE - 7 AND t.start_at < CURRENT_DATE + 1 GROUP BY 1", [...$p, $u->id], self::C);
            $byDay = array_column($rows, 'n', 'day');
            $q['activities'] = array_map(fn (int $k) => ['date' => $d = date('Y-m-d', strtotime("-{$k} days")), 'count' => (int) ($byDay[$d] ?? 0)], range(7, 0));
        }
        if ($q) {
            $out['quarter'] = $q;
        }
        // this year's sales target, the same figure as the website's CRM dashboard: own department, or every department
        // added up for whole-company roles; nothing for someone outside any department
        if ($u->can('crm_dashboard', 'view')) {
            $dep = Access::seesAll($u) ? null : ((int) $u->department_id ?: 0);
            if ($dep !== 0) {
                $year = (int) date('Y');
                $t    = Targets::summary($dep, $year);
                $out['target'] = [
                    'year' => $year, 'currency' => Targets::baseCurrency(), 'source' => $t['source'],
                    'total' => round($t['total'], 2), 'actual' => round($t['actual_total'], 2),
                    'quarters' => array_map(fn (int $q) => ['q' => $q, 'target' => round($t['target'][$q], 2), 'actual' => round((float) $t['actual'][$q], 2)], [1, 2, 3, 4]),
                ];
            }
        }

        $out['stage_names'] = Stages::forMobile();

        return ['data' => $out];
    }
}
