<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Targets;

/**
 * CRM dashboard.
 * Everyone sees the data of their own department. Whole-company roles (Admin, Management …) see all departments
 * and can pick one department to look at; "My records" narrows any view to the person's own records.
 */
class DashboardController extends Controller
{
    public function index(): string
    {
        $this->authorize('crm_dashboard', 'view');
        $u     = $this->user();
        $all   = Access::seesAll($u);
        $deps  = Access::departments();
        $pick  = $all ? (int) Request::query('department', 0) : 0;
        $pick  = isset($deps[$pick]) ? $pick : 0;
        $mine  = Request::query('mine') === '1';
        [$from, $to, $range] = \App\Core\Support\Period::fromRequest();
        $lo = $from->format('Y-m-d'); $hi = $to->format('Y-m-d');          // validated dates: safe to put in the SQL

        // who can be picked: everybody with CRM access for whole-company roles, the own department's for BU managers and managers, nobody for members
        $people = Access::crmMembers($u, $pick ?: null);
        $member = (int) Request::query('member', 0);
        $member = isset($people[$member]) ? $member : 0;
        if ($people) { $mine = false; }

        // Which records feed the numbers (alias t = the CRM table)
        if ($all) {
            [$sql, $params] = $pick ? ['t.department_id = ?', [$pick]] : ['TRUE', []];
            $scopeName = $pick ? $deps[$pick] : __('All departments');
        } elseif ($u->department_id) {
            [$sql, $params] = ['t.department_id = ?', [$u->department_id]];
            $scopeName = $deps[$u->department_id] ?? (string) $u->department_name;
        } else {
            [$sql, $params] = ['t.owner_id = ?', [$u->id]];
            $scopeName = __('My records');
        }
        if ($mine || $member) {
            $sql    = "($sql) AND t.owner_id = ?";
            $params = [...$params, $member ?: $u->id];
        }
        $f = ['sql' => $sql, 'params' => $params];

        $base = (array) DB::first('SELECT code FROM currencies WHERE is_base LIMIT 1', [], 'crm');
        $cur  = $base['code'] ?? 'THB';
        $val  = 'COALESCE(t.amount, 0) * COALESCE(c.rate_to_base, 1)';
        $opp  = 'FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency WHERE t.deleted_at IS NULL AND ';
        $when = 'COALESCE(t.close_at, t.updated_at)';
        $open = "('".implode("','", Catalog::PIPELINE)."')";

        // ----- pipeline by stage (open stages in order)
        $byStage = array_column(DB::select("SELECT t.opportunity_stage AS stage, count(*) AS n, sum({$val}) AS v, sum({$val} * COALESCE(t.probability, 0) / 100) AS w
            {$opp} {$f['sql']} AND t.opportunity_stage IN {$open} GROUP BY t.opportunity_stage", $f['params'], 'crm'), null, 'stage');
        $stages = [];
        foreach (Catalog::PIPELINE as $s) {
            $stages[$s] = ['label' => Catalog::stageLabel($s), 'n' => (int) ($byStage[$s]['n'] ?? 0), 'v' => (float) ($byStage[$s]['v'] ?? 0), 'w' => (float) ($byStage[$s]['w'] ?? 0)];
        }
        $hold = (array) DB::first("SELECT count(*) AS n, COALESCE(sum({$val}), 0) AS v {$opp} {$f['sql']} AND t.opportunity_stage = 'ON_HOLD'", $f['params'], 'crm');

        // ----- won / lost
        $won = (array) DB::first("SELECT
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND {$when}::date BETWEEN '{$lo}' AND '{$hi}') AS month_n,
              COALESCE(sum({$val}) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND {$when}::date BETWEEN '{$lo}' AND '{$hi}'), 0) AS month_v,
              COALESCE(sum({$val}) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND date_trunc('year', {$when}) = date_trunc('year', now())), 0) AS year_v,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND {$when} >= now() - interval '12 months') AS won12,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST' AND {$when} >= now() - interval '12 months') AS lost12
            {$opp} {$f['sql']}", $f['params'], 'crm');
        $closed  = (int) $won['won12'] + (int) $won['lost12'];
        $winRate = $closed ? round((int) $won['won12'] / $closed * 100) : null;

        $months = DB::select("SELECT to_char(m, 'YYYY-MM') AS ym, to_char(m, 'Mon') AS label, COALESCE(sum({$val}), 0) AS v, count(t.id) AS n
            FROM generate_series(LEAST(date_trunc('month', '{$lo}'::date), date_trunc('month', '{$hi}'::date) - interval '5 months'), date_trunc('month', '{$hi}'::date), interval '1 month') m
            LEFT JOIN opportunities t ON t.deleted_at IS NULL AND t.opportunity_stage = 'CLOSED_WON' AND date_trunc('month', {$when}) = m AND {$f['sql']}
            LEFT JOIN currencies c ON c.code = t.currency GROUP BY m ORDER BY m", $f['params'], 'crm');

        // ----- leads, contacts
        $counts = (array) DB::first("SELECT
              (SELECT count(*) FROM leads t WHERE t.deleted_at IS NULL AND {$f['sql']}) AS leads,
              (SELECT count(*) FROM leads t WHERE t.deleted_at IS NULL AND t.created_at::date BETWEEN '{$lo}' AND '{$hi}' AND {$f['sql']}) AS leads_new,
              (SELECT count(*) FROM contacts t WHERE t.deleted_at IS NULL AND {$f['sql']}) AS contacts",
            [...$f['params'], ...$f['params'], ...$f['params']], 'crm');
        $industries = DB::select("SELECT i.name, i.color, count(DISTINCT t.id) AS n FROM industries i JOIN lead_industries x ON x.industry_id = i.id
            JOIN leads t ON t.id = x.lead_id AND t.deleted_at IS NULL AND {$f['sql']} GROUP BY i.id ORDER BY n DESC, i.name LIMIT 6", $f['params'], 'crm');
        $sources = DB::select("SELECT s.name, s.color, count(DISTINCT t.id) AS n FROM lead_sources s JOIN lead_lead_sources x ON x.lead_source_id = s.id
            JOIN leads t ON t.id = x.lead_id AND t.deleted_at IS NULL AND {$f['sql']} GROUP BY s.id ORDER BY n DESC, s.name LIMIT 6", $f['params'], 'crm');

        // ----- activities
        $actCount = (array) DB::first("SELECT
              count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at < now()) AS overdue,
              count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at >= now() AND t.start_at < now() + interval '7 days') AS week,
              count(*) FILTER (WHERE t.status = 'DONE' AND t.start_at::date BETWEEN '{$lo}' AND '{$hi}') AS done_month
            FROM activities t WHERE t.deleted_at IS NULL AND {$f['sql']}", $f['params'], 'crm');
        $upcoming = DB::select("SELECT t.id, t.topic, t.activity_type, t.start_at, t.status, t.owner_id, l.name_en AS lead_name
            FROM activities t LEFT JOIN leads l ON l.id = t.lead_id
            WHERE t.deleted_at IS NULL AND t.status = 'PLANNED' AND {$f['sql']} ORDER BY (t.start_at < now()) DESC, t.start_at NULLS LAST LIMIT 7", $f['params'], 'crm');
        $reminders = DB::select("SELECT t.id, t.topic, t.activity_type, t.start_at FROM activities t
            WHERE t.deleted_at IS NULL AND t.status = 'PLANNED' AND t.notify_me AND t.owner_id = ? AND t.start_at IS NOT NULL
              AND t.start_at - (t.notify_before * interval '1 minute') <= now() AND t.start_at > now() - interval '1 day' ORDER BY t.start_at LIMIT 5", [$u->id], 'crm');

        // ----- top deals, campaigns
        $top = DB::select("SELECT t.id, t.name, t.opportunity_stage AS stage, t.owner_id, t.close_at, t.probability, t.currency, t.amount, {$val} AS base_value, l.name_en AS lead_name
            FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency LEFT JOIN leads l ON l.id = t.lead_id
            WHERE t.deleted_at IS NULL AND t.opportunity_stage IN {$open} AND {$f['sql']} ORDER BY base_value DESC NULLS LAST LIMIT 5", $f['params'], 'crm');
        $campaigns = DB::select("SELECT t.id, t.name, t.budget, t.actual_cost, t.end_date FROM campaigns t WHERE t.deleted_at IS NULL AND t.status = 'ACTIVE' AND {$f['sql']} ORDER BY t.end_date NULLS LAST LIMIT 4", $f['params'], 'crm');

        $owners = Access::names('users', array_merge(array_column($top, 'owner_id'), array_column($upcoming, 'owner_id')));

        // ----- department comparison (whole-company roles looking at everything)
        $compare = [];
        if ($all && ! $pick) {
            $compare = $this->compare($deps, $val, $mine ? $u->id : null);
        }

        // yearly target of the department being looked at (all departments added up for whole-company roles)
        $year    = (int) $to->format('Y');
        $depFor  = $all ? ($pick ?: null) : ($u->department_id ?: 0);
        $target  = $depFor === 0 ? null : Targets::summary($depFor, $year);

        $pipeline = array_sum(array_column($stages, 'v'));

        return view('crm/dashboard', [
            'title' => __('CRM overview'), 'all' => $all, 'deps' => $deps, 'pick' => $pick, 'mine' => $mine, 'scopeName' => $scopeName, 'cur' => $cur,
            'stages' => $stages, 'hold' => $hold, 'pipeline' => $pipeline, 'forecast' => array_sum(array_column($stages, 'w')), 'openCount' => array_sum(array_column($stages, 'n')),
            'won' => $won, 'winRate' => $winRate, 'months' => $months, 'counts' => $counts, 'industries' => $industries, 'sources' => $sources,
            'target' => $target, 'targetYear' => $year, 'from' => $from, 'to' => $to, 'range' => $range, 'people' => $people, 'member' => $member, 'depFor' => $depFor, 'erpId' => $depFor ? \App\Modules\CRM\Support\Revenue::erpId((int) $depFor) : null, 'actCount' => $actCount, 'upcoming' => $upcoming, 'reminders' => $reminders, 'top' => $top, 'campaigns' => $campaigns, 'owners' => $owners, 'compare' => $compare,
        ]);
    }

    public function settings(): never
    {
        $first = LookupController::tabs()[0][0] ?? null;
        $first ? redirect($first) : abort(403, __('You do not have access to CRM settings.'));
    }

    /** One row per department for the whole-company view. */
    private function compare(array $deps, string $val, ?int $mineId): array
    {
        $own   = $mineId ? ' AND t.owner_id = '.(int) $mineId : '';
        $rows  = [];
        $touch = function (?int $d) use (&$rows, $deps) {
            $k = $d ?: 0;
            $rows[$k] ??= ['id' => $k, 'name' => $k ? ($deps[$k] ?? '#'.$k) : __('No department'), 'leads' => 0, 'open' => 0, 'pipeline' => 0.0, 'won_year' => 0.0, 'overdue' => 0];

            return $k;
        };
        foreach (DB::select("SELECT t.department_id AS d, count(*) AS n FROM leads t WHERE t.deleted_at IS NULL{$own} GROUP BY 1", [], 'crm') as $r) {
            $rows[$touch($r['d'] ? (int) $r['d'] : null)]['leads'] = (int) $r['n'];
        }
        $open = "('".implode("','", Catalog::PIPELINE)."')";
        foreach (DB::select("SELECT t.department_id AS d, count(*) FILTER (WHERE t.opportunity_stage IN {$open}) AS open_n,
                COALESCE(sum({$val}) FILTER (WHERE t.opportunity_stage IN {$open}), 0) AS pipe,
                COALESCE(sum({$val}) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND date_trunc('year', COALESCE(t.close_at, t.updated_at)) = date_trunc('year', now())), 0) AS won
            FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency WHERE t.deleted_at IS NULL{$own} GROUP BY 1", [], 'crm') as $r) {
            $k = $touch($r['d'] ? (int) $r['d'] : null);
            $rows[$k]['open'] = (int) $r['open_n']; $rows[$k]['pipeline'] = (float) $r['pipe']; $rows[$k]['won_year'] = (float) $r['won'];
        }
        foreach (DB::select("SELECT t.department_id AS d, count(*) AS n FROM activities t WHERE t.deleted_at IS NULL AND t.status = 'PLANNED' AND t.start_at < now(){$own} GROUP BY 1", [], 'crm') as $r) {
            $rows[$touch($r['d'] ? (int) $r['d'] : null)]['overdue'] = (int) $r['n'];
        }
        usort($rows, fn ($a, $b) => $b['pipeline'] <=> $a['pipeline'] ?: strcmp($a['name'], $b['name']));

        return array_values($rows);
    }
}
