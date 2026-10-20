<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\DB;
use App\Core\Support\Modules;
use App\Core\Support\Request;

/**
 * Management → Overview: revenue for a period, top customers, the seller ranking and the department ranking.
 * Management → CRM overview: opportunities for the same periods (pipeline, won / lost, ranking), from CRM's 'management.crm' hook.
 * The figures come from Accounting through the 'revenue.ranking' hook; when Accounting is off or broken the page says so
 * and the rest of the module keeps working. Names, photos and departments come from the core database.
 */
class OverviewController extends Controller
{
    public const PERIODS = ['month' => 'This month', 'last_month' => 'Last month', 'quarter' => 'This quarter', 'ytd' => 'Year to date', 'year' => 'Full year', 'custom' => 'Custom'];

    /** @return array{0: string, 1: string, 2: string} from, to, period key */
    private function range(): array
    {
        $period = (string) Request::query('period', 'ytd');
        $period = isset(self::PERIODS[$period]) ? $period : 'ytd';
        $year = (int) Request::query('year', date('Y'));
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');
        $today = new \DateTimeImmutable('today');
        [$from, $to] = match ($period) {
            'month'      => [$today->modify('first day of this month'), $today],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            'quarter'    => [new \DateTimeImmutable(sprintf('%04d-%02d-01', (int) $today->format('Y'), intdiv((int) $today->format('n') - 1, 3) * 3 + 1)), $today],
            'year'       => [new \DateTimeImmutable("{$year}-01-01"), new \DateTimeImmutable("{$year}-12-31")],
            'custom'     => [self::date((string) Request::query('from'), $today->modify('first day of this month')), self::date((string) Request::query('to'), $today)],
            default      => [new \DateTimeImmutable($today->format('Y').'-01-01'), $today],
        };
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d'), $period];
    }

    private static function date(string $v, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? new \DateTimeImmutable($v) : $fallback;
    }

    public function index(): string
    {
        $this->authorize('management', 'view');
        [$from, $to, $period] = $this->range();
        $data = Modules::usable('accounting') ? (Modules::hook('revenue.ranking', [$from, $to], 'accounting')['accounting'] ?? null) : null;

        $departments = array_column(DB::select('SELECT id, name, color FROM departments WHERE deleted_at IS NULL'), null, 'id');
        $people = [];
        if ($data) {
            $ids = array_values(array_unique(array_filter(array_column($data['sellers'], 'user_id'))));
            if ($ids) {
                $people = array_column(DB::select('SELECT id, name, avatar, department_id FROM users WHERE id IN ('.implode(',', array_map('intval', $ids)).')'), null, 'id');
            }
        }
        $kpi = DB::first('SELECT count(*) AS n, round(avg(score), 2) AS avg FROM kpi_scores WHERE year = ?', [(int) substr($from, 0, 4)]);

        return view('management::overview', ['title' => __('Management overview'), 'from' => $from, 'to' => $to, 'period' => $period,
            'year' => (int) Request::query('year', date('Y')), 'data' => $data, 'departments' => $departments, 'people' => $people, 'kpi' => $kpi]);
    }

    public function crm(): string
    {
        $this->authorize('management', 'view');
        [$from, $to, $period] = $this->range();
        $data = Modules::usable('crm') ? (Modules::hook('management.crm', [$from, $to], 'crm')['crm'] ?? null) : null;

        $departments = array_column(DB::select('SELECT id, name, color FROM departments WHERE deleted_at IS NULL'), null, 'id');
        $people = [];
        if ($data) {
            $ids = array_values(array_unique(array_filter(array_merge(array_column($data['sellers'], 'id'), array_column($data['top'], 'owner_id')))));
            if ($ids) {
                $people = array_column(DB::select('SELECT id, name, avatar, department_id FROM users WHERE id IN ('.implode(',', array_map('intval', $ids)).')'), null, 'id');
            }
        }

        return view('management::crm', ['title' => __('CRM overview'), 'from' => $from, 'to' => $to, 'period' => $period,
            'year' => (int) Request::query('year', date('Y')), 'data' => $data, 'departments' => $departments, 'people' => $people]);
    }
}
