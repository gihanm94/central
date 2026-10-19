<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\KpiSubmissions as K;
use App\Core\Support\Options;
use App\Core\Support\ValidationException;

class KpiController extends ResourceController
{
    public const STATUSES = ['active' => 'Active', 'achieved' => 'Achieved', 'missed' => 'Missed', 'archived' => 'Archived'];

    /** KPIs and OKRs are what people submit a monthly result for (KPI submissions). */
    public const KINDS = ['kpi' => 'KPI', 'okr' => 'OKR'];
    public const STATUS_TONES = ['active' => 'bg-sky-600 text-white', 'achieved' => 'bg-emerald-600 text-white', 'missed' => 'bg-signal-600 text-white', 'archived' => 'bg-graphite-500 text-white'];

    public static function statuses(): array { return array_map(fn ($l) => __($l), self::STATUSES); }
    public static function kinds(): array { return array_map(fn ($l) => __($l), self::KINDS); }

    protected string $resource = 'kpis';
    protected string $table = 'kpis';
    protected string $type = 'kpi';
    protected string $singular = 'KPI / OKR';
    protected string $plural = 'KPIs & OKRs';
    protected string $base = '/kpis';
    protected string $icon = 'chart';
    protected string $orderBy = "CASE t.status WHEN 'active' THEN 0 ELSE 1 END, t.period_end DESC, t.id DESC";

    protected function select(): string
    {
        return 'SELECT t.*, o.name AS owner_name, o.avatar AS owner_avatar FROM kpis t LEFT JOIN users o ON o.id = t.user_id';
    }

    protected function fields(?array $row): array
    {
        return [
            'title'        => ['label' => __('KPI / OKR'), 'rules' => 'required|max:160', 'span' => 2, 'example' => 'Finish assigned work on time at least 90% per month'],
            'kind'         => ['label' => __('Type'), 'type' => 'select', 'options' => self::kinds(), 'rules' => 'required', 'default' => 'kpi', 'search' => false, 'example' => 'kpi'],
            'criteria'     => ['label' => __('Criteria'), 'type' => 'number', 'rules' => 'nullable|numeric', 'example' => '90',
                               'help' => __('Target or weight from 1 to 100, e.g. 90 for "at least 90%". Copied into each monthly submission.')],
            'direction'    => ['label' => __('Direction'), 'type' => 'select', 'options' => ['min' => __('Not less than (higher is better)'), 'max' => __('Not more than (a limit)')],
                               'rules' => 'required', 'default' => 'min', 'search' => false, 'example' => 'min'],
            'measure'      => ['label' => __('Monthly figure'), 'type' => 'select', 'options' => ['percent' => __('Finished ÷ total, in %'), 'count' => __('Count of finished')],
                               'rules' => 'required', 'default' => 'percent', 'search' => false, 'example' => 'percent',
                               'help' => __('How the KPI calculator reads each monthly submission against the criteria.')],
            'period'       => ['label' => __('Measured'), 'type' => 'select', 'options' => array_map(fn ($l) => __($l), \App\Core\Support\KpiScore::PERIODS),
                               'rules' => 'required', 'default' => 'month', 'search' => false, 'example' => 'month'],
            'weight'       => ['label' => __('Weight %'), 'type' => 'number', 'rules' => 'nullable|numeric', 'example' => '30',
                               'help' => __('Share of this KPI in the KPI section; the weights of one person should add up to 100.')],
            'user_id'      => ['label' => __('Owner'), 'type' => 'select', 'options' => Options::people($this->user()), 'rules' => 'required', 'rich' => true,
                               'all_options' => $row ? [$row['user_id'] => ['label' => $row['owner_name'], 'img' => upload_url($row['owner_avatar'])]] : []],
            'status'       => ['label' => __('Status'), 'type' => 'select', 'options' => self::statuses(), 'rules' => 'required', 'default' => 'active'],
            'target'       => ['label' => __('Target'), 'type' => 'number', 'rules' => 'required|numeric', 'example' => '95'],
            'actual'       => ['label' => __('Actual'), 'type' => 'number', 'rules' => 'required|numeric', 'default' => '0', 'example' => '80'],
            'unit'         => ['label' => __('Unit'), 'rules' => 'required|max:20', 'default' => '%', 'help' => __('e.g. %, hrs, THB, units'), 'example' => '%'],
            'period_start' => ['label' => __('Period start'), 'type' => 'date', 'rules' => 'required|date', 'default' => date('Y-01-01'), 'example' => date('Y-01-01'),
                               'help' => __('People submit a result every month inside this period.')],
            'period_end'   => ['label' => __('Period end'), 'type' => 'date', 'rules' => 'required|date', 'default' => date('Y-12-31'), 'example' => date('Y-12-31')],
            'description'  => ['label' => __('How it is measured'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:2000'],
        ];
    }

    protected function columns(): array
    {
        return [
            'title'    => ['label' => __('KPI / OKR'), 'primary' => true, 'render' => fn ($r) => '<span class="badge mr-1 '.($r['kind'] === 'okr' ? 'bg-violet-50 text-violet-800' : 'bg-sky-50 text-sky-800').'">'.e(self::KINDS[$r['kind']] ?? 'KPI').'</span>'
                .'<a href="'.url('/kpis/'.$r['id']).'" class="font-medium hover:text-signal-700">'.e($r['title']).'</a>'],
            'criteria' => ['label' => __('Criteria'), 'render' => fn ($r) => '<span class="tabular-nums">'.($r['criteria'] !== null ? e(number_clean($r['criteria'])) : '—').'</span>'],
            'owner'    => ['label' => __('Owner'), 'render' => fn ($r) => $r['owner_name'] ? TaskController::person($r['owner_name'], $r['owner_avatar']) : '—'],
            'progress' => ['label' => __('Progress'), 'render' => function ($r) {
                $pct = (float) $r['target'] > 0 ? (int) round((float) $r['actual'] / (float) $r['target'] * 100) : 0;

                return '<div class="flex min-w-40 items-center gap-3">'.partial('partials/progress', ['value' => $pct]).'<span class="w-10 text-right text-xs tabular-nums">'.$pct.'%</span></div>'
                    .'<p class="mt-1 text-xs text-steel">'.number_clean($r['actual']).' / '.number_clean($r['target']).' '.e($r['unit']).'</p>';
            }],
            'period'   => ['label' => __('Period'), 'render' => fn ($r) => '<span class="text-xs text-steel">'.format_date($r['period_start'], 'd M').' – '.format_date($r['period_end'], 'd M Y').'</span>'],
            'status'   => ['label' => __('Status'), 'render' => fn ($r) => '<span class="badge '.(self::STATUS_TONES[$r['status']] ?? 'bg-graphite-900/6 text-graphite-800').'">'.e(self::statuses()[$r['status']] ?? $r['status']).'</span>'],
        ];
    }

    protected function searchable(): array { return ['t.title', 'o.name']; }

    /** Administrators and Management see all, a BU manager the department and their own, everyone else their own. */
    protected function scopeSql(): array { return K::scopeSql($this->user(), 't'); }

    /** Only the BU manager of the department (or an administrator) changes or deletes. */
    protected function canModify(array $row, string $action): bool { return K::canManage($this->user(), $row['department_id']); }

    public function create(): string
    {
        K::canAdd($this->user()) || abort(403, __('Only a BU manager or an administrator can add KPIs & OKRs.'));

        return parent::create();
    }

    public function store(): never
    {
        K::canAdd($this->user()) || abort(403, __('Only a BU manager or an administrator can add KPIs & OKRs.'));
        parent::store();
    }

    /** Status, progress against the target, this year's submissions and every change, on the KPI's page. */
    protected function showExtra(array $row): string
    {
        $subs = DB::select('SELECT s.*, ap.name AS approver_name, ap.avatar AS approver_avatar FROM kpi_submissions s LEFT JOIN users ap ON ap.id = s.approver_id
            WHERE s.kpi_id = ? AND s.deleted_at IS NULL ORDER BY s.period_year DESC, s.period_month DESC LIMIT 36', [$row['id']]);
        $history = DB::select("SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
            WHERE (l.subject_type = 'kpi' AND l.subject_id = ?) OR (l.subject_type = 'kpi_submission' AND l.properties->>'kpi_id' = ?)
            ORDER BY l.created_at DESC, l.id DESC LIMIT 100", [$row['id'], (string) $row['id']]);

        return partial('kpi/kpi-extra', ['row' => $row, 'subs' => $subs, 'history' => $history, 'fields' => $this->fields($row)]);
    }

    protected function filters(): array
    {
        return [
            'kind'   => ['label' => __('KPIs and OKRs'), 'options' => self::kinds(), 'column' => 't.kind'],
            'status' => ['label' => __('All statuses'), 'options' => self::statuses(), 'column' => 't.status'],
        ];
    }

    protected function owner(array $row): ?int { return (int) $row['user_id']; }

    protected function prepare(array $data, ?array $existing): array
    {
        if (isset($data['period_start'], $data['period_end']) && strtotime($data['period_end']) < strtotime($data['period_start'])) {
            throw new ValidationException(['period_end' => __('Period end must be on or after the start.')]);
        }
        if (($data['criteria'] ?? null) !== null && $data['criteria'] !== '' && ((float) $data['criteria'] < 1 || (float) $data['criteria'] > 100)) {
            throw new ValidationException(['criteria' => __('Criteria must be between 1 and 100.')]);
        }
        if (($data['weight'] ?? null) !== null && $data['weight'] !== '' && ((float) $data['weight'] <= 0 || (float) $data['weight'] > 100)) {
            throw new ValidationException(['weight' => __('Weight must be between 1 and 100.')]);
        }
        if (! $existing) {
            $data['created_by'] = $this->user()->id;
        }
        if (! empty($data['user_id'])) {
            $o = DB::first('SELECT department_id, team_id FROM users WHERE id = ?', [$data['user_id']]);
            if (! K::canManage($this->user(), $o['department_id'] ?? null)) {
                throw new ValidationException(['user_id' => __('You can only give KPIs & OKRs to people in your department.')]);
            }
            $data['department_id'] = $o['department_id'] ?? null;
            $data['team_id']       = $o['team_id'] ?? null;
        }

        return $data;
    }
}
