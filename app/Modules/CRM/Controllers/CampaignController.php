<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Ui;

/** Marketing campaigns with budget tracking and attachments. */
class CampaignController extends CrmController
{
    protected string $resource = 'crm_campaigns';
    protected string $table = 'campaigns';
    protected string $type = 'campaign';
    protected string $entity = 'campaign';
    protected string $codePrefix = 'CP';
    protected string $singular = 'campaign';
    protected string $plural = 'campaigns';
    protected string $base = '/crm/campaigns';
    protected string $icon = 'chat';
    protected string $orderBy = "CASE t.status WHEN 'ACTIVE' THEN 0 WHEN 'PLANNED' THEN 1 WHEN 'DRAFT' THEN 2 ELSE 3 END, t.start_date DESC NULLS LAST, t.id DESC";
    protected bool $files = true;

    protected function label(array $row): string { return (string) $row['name']; }

    protected function searchable(): array { return ['t.name', 't.code', 't.description']; }

    protected function filters(): array
    {
        return [
            'type'   => ['label' => __('All types'), 'options' => Catalog::tr(Catalog::CAMPAIGN_TYPES), 'column' => 't.type'],
            'status' => ['label' => __('All statuses'), 'options' => Catalog::tr(Catalog::CAMPAIGN_STATUSES), 'column' => 't.status'],
        ] + $this->commonFilters();
    }

    protected function fields(?array $row): array
    {
        return [
            '_campaign'  => ['section' => __('Campaign')],
            'name'       => ['label' => __('Name'), 'rules' => 'required|max:200', 'span' => 2, 'example' => 'Spring packaging expo'],
            'type'       => ['label' => __('Type'), 'type' => 'select', 'options' => Catalog::tr(Catalog::CAMPAIGN_TYPES), 'rules' => 'required', 'default' => 'EMAIL'],
            'status'     => ['label' => __('Status'), 'type' => 'select', 'options' => Catalog::tr(Catalog::CAMPAIGN_STATUSES), 'rules' => 'required', 'default' => 'DRAFT'],
            'start_date' => ['label' => __('Starts'), 'type' => 'date', 'rules' => 'nullable|date'],
            'end_date'   => ['label' => __('Ends'), 'type' => 'date', 'rules' => 'nullable|date'],
            'code'       => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'help' => __('Leave empty to number it automatically.')],

            '_money'     => ['section' => __('Budget and results')],
            'budget'           => ['label' => __('Budget'), 'type' => 'number', 'rules' => 'nullable|numeric'],
            'actual_cost'      => ['label' => __('Actual cost'), 'type' => 'number', 'rules' => 'nullable|numeric'],
            'expected_revenue' => ['label' => __('Expected revenue'), 'type' => 'number', 'rules' => 'nullable|numeric'],
            'expected_response_rate' => ['label' => __('Expected response rate (%)'), 'type' => 'number', 'rules' => 'nullable|numeric'],
            'actual_response_rate'   => ['label' => __('Actual response rate (%)'), 'type' => 'number', 'rules' => 'nullable|numeric'],

            '_more'      => ['section' => __('Notes and files')],
            'description' => ['label' => __('Description'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable'],
            'files'      => ['label' => __('Attachments'), 'type' => 'custom', 'partial' => 'crm/fields/files', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable'],
        ] + $this->ownershipFields($row);
    }

    private function tone(string $s): string
    {
        return ['ACTIVE' => 'success', 'PLANNED' => 'info', 'DRAFT' => 'neutral', 'COMPLETED' => 'violet', 'CANCELLED' => 'danger'][$s] ?? 'neutral';
    }

    protected function columns(): array
    {
        return [
            'name'   => ['label' => __('Campaign'), 'primary' => true, 'sort' => 't.name', 'render' => fn ($r) => Ui::person($r['name'], __(Catalog::CAMPAIGN_TYPES[$r['type']] ?? ($r['type'] ?? '')).' · '.($r['code'] ?? ''), null, '/crm/campaigns/'.$r['id'])],
            'status' => ['label' => __('Status'), 'sort' => 't.status', 'render' => fn ($r) => Ui::badge(__(Catalog::CAMPAIGN_STATUSES[$r['status']] ?? $r['status']), $this->tone($r['status']))],
            'period' => ['label' => __('Period'), 'sort' => 't.start_date', 'render' => fn ($r) => '<span class="tabular-nums text-steel">'.e(format_date($r['start_date'], 'd M').' → '.format_date($r['end_date'], 'd M Y')).'</span>'],
            'budget' => ['label' => __('Budget'), 'sort' => 't.budget', 'render' => fn ($r) => '<span class="tabular-nums">'.Ui::money($r['budget']).'</span>'],
            'cost'   => ['label' => __('Spent'), 'sort' => 't.actual_cost', 'render' => fn ($r) => $r['actual_cost'] === null ? Ui::dash()
                : ($r['budget'] > 0 ? partial('crm/meter', ['value' => min(100, (float) $r['actual_cost'] / (float) $r['budget'] * 100), 'label' => number_clean($r['actual_cost'])]) : '<span class="tabular-nums">'.Ui::money($r['actual_cost']).'</span>')],
            'created' => $this->createdColumn(),
        ];
    }

    protected function badges(array $row): array
    {
        return [Ui::badge(__(Catalog::CAMPAIGN_STATUSES[$row['status']] ?? $row['status']), $this->tone($row['status'])), Ui::badge(__(Catalog::CAMPAIGN_TYPES[$row['type']] ?? (string) $row['type']), 'violet')];
    }

    protected function subtitleFor(array $row): string
    {
        return implode(' · ', array_filter([$row['code'], $row['start_date'] ? format_date($row['start_date'], 'd M Y').' → '.format_date($row['end_date'], 'd M Y') : null]));
    }

    protected function showMain(array $row): string
    {
        return partial('crm/panels/campaign-stats', ['row' => $row]);
    }

    protected function statCards(): array
    {
        return [
            $this->card(__('Active'), $this->figure('count(*)', "t.status = 'ACTIVE'"), null, 'success', 'chat'),
            $this->card(__('Planned'), $this->figure('count(*)', "t.status IN ('PLANNED','DRAFT')"), null, 'info', 'chat'),
            $this->card(__('Budget'), Ui::compact($this->figure('sum(t.budget)')), null, 'neutral', 'chart'),
            $this->card(__('Spent'), Ui::compact($this->figure('sum(t.actual_cost)')), null, 'warn', 'chart'),
        ];
    }
}
