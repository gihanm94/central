<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Options;
use App\Core\Support\ValidationException;

class KpiController extends ResourceController
{
    public const STATUSES = ['active' => 'Active', 'achieved' => 'Achieved', 'missed' => 'Missed', 'archived' => 'Archived'];

    public static function statuses(): array { return array_map(fn ($l) => __($l), self::STATUSES); }

    protected string $resource = 'kpis';
    protected string $table = 'kpis';
    protected string $type = 'kpi';
    protected string $singular = 'KPI';
    protected string $plural = 'KPIs';
    protected string $base = '/kpis';
    protected string $icon = 'chart';
    protected string $orderBy = "CASE t.status WHEN 'active' THEN 0 ELSE 1 END, t.period_end DESC, t.id DESC";

    protected function select(): string
    {
        return 'SELECT t.*, o.name AS owner_name FROM kpis t LEFT JOIN users o ON o.id = t.user_id';
    }

    protected function fields(?array $row): array
    {
        return [
            'title'        => ['label' => __('KPI'), 'rules' => 'required|max:160', 'span' => 2, 'example' => 'On-time task completion'],
            'user_id'      => ['label' => __('Owner'), 'type' => 'select', 'options' => Options::users($this->user()), 'rules' => 'required',
                               'all_options' => $row ? [$row['user_id'] => $row['owner_name']] : []],
            'status'       => ['label' => __('Status'), 'type' => 'select', 'options' => self::statuses(), 'rules' => 'required', 'default' => 'active'],
            'target'       => ['label' => __('Target'), 'type' => 'number', 'rules' => 'required|numeric', 'example' => '95'],
            'actual'       => ['label' => __('Actual'), 'type' => 'number', 'rules' => 'required|numeric', 'default' => '0', 'example' => '80'],
            'unit'         => ['label' => __('Unit'), 'rules' => 'required|max:20', 'default' => '%', 'help' => __('e.g. %, hrs, THB, units'), 'example' => '%'],
            'period_start' => ['label' => __('Period start'), 'type' => 'date', 'rules' => 'required|date', 'default' => date('Y-m-01'), 'example' => date('Y-m-01')],
            'period_end'   => ['label' => __('Period end'), 'type' => 'date', 'rules' => 'required|date', 'default' => date('Y-m-t'), 'example' => date('Y-m-t')],
            'description'  => ['label' => __('How it is measured'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:2000'],
        ];
    }

    protected function columns(): array
    {
        return [
            'title'    => ['label' => __('KPI'), 'primary' => true, 'render' => fn ($r) => '<a href="'.url('/kpis/'.$r['id']).'" class="font-medium hover:text-signal-700">'.e($r['title']).'</a>'],
            'owner'    => ['label' => __('Owner'), 'render' => fn ($r) => e($r['owner_name'] ?? '—')],
            'progress' => ['label' => __('Progress'), 'render' => function ($r) {
                $pct = (float) $r['target'] > 0 ? (int) round((float) $r['actual'] / (float) $r['target'] * 100) : 0;

                return '<div class="flex min-w-40 items-center gap-3">'.partial('partials/progress', ['value' => $pct]).'<span class="w-10 text-right text-xs tabular-nums">'.$pct.'%</span></div>'
                    .'<p class="mt-1 text-xs text-steel">'.number_clean($r['actual']).' / '.number_clean($r['target']).' '.e($r['unit']).'</p>';
            }],
            'period'   => ['label' => __('Period'), 'render' => fn ($r) => '<span class="text-xs text-steel">'.format_date($r['period_start'], 'd M').' – '.format_date($r['period_end'], 'd M Y').'</span>'],
            'status'   => ['label' => __('Status'), 'render' => fn ($r) => '<span class="badge '.($r['status'] === 'achieved' ? 'bg-emerald-50 text-emerald-800' : ($r['status'] === 'missed' ? 'bg-signal-50 text-signal-800' : 'bg-graphite-900/6 text-graphite-800')).'">'.e(self::statuses()[$r['status']] ?? $r['status']).'</span>'],
        ];
    }

    protected function searchable(): array { return ['t.title', 'o.name']; }

    protected function filters(): array
    {
        return ['status' => ['label' => __('All statuses'), 'options' => self::statuses(), 'column' => 't.status']];
    }

    protected function owner(array $row): ?int { return (int) $row['user_id']; }

    protected function prepare(array $data, ?array $existing): array
    {
        if (isset($data['period_start'], $data['period_end']) && strtotime($data['period_end']) < strtotime($data['period_start'])) {
            throw new ValidationException(['period_end' => __('Period end must be on or after the start.')]);
        }
        if (! $existing) {
            $data['created_by'] = $this->user()->id;
        }
        if (! empty($data['user_id'])) {
            $o = DB::first('SELECT department_id, team_id FROM users WHERE id = ?', [$data['user_id']]);
            $data['department_id'] = $o['department_id'] ?? null;
            $data['team_id']       = $o['team_id'] ?? null;
        }

        return $data;
    }
}
