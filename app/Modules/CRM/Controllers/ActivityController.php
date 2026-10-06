<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Ui;

/** Calls, meetings, e-mails and tasks — linked to a company, a contact and/or an opportunity. Has files and comments. */
class ActivityController extends CrmController
{
    protected array $remote = ['lead_id' => 'leads', 'contact_id' => 'contacts', 'opportunity_id' => 'opportunities'];

    protected string $resource = 'crm_activities';
    protected string $table = 'activities';
    protected string $type = 'activity';
    protected string $entity = 'activity';
    protected string $codePrefix = 'AC';
    protected string $singular = 'activity';
    protected string $plural = 'activities';
    protected string $base = '/crm/activities';
    protected string $icon = 'clock';
    protected string $orderBy = 't.start_at DESC NULLS LAST, t.id DESC';
    protected bool $comments = true;
    protected bool $files = true;

    protected function select(): string
    {
        return 'SELECT t.*, l.name_en AS lead_name, l.name_th AS lead_name_th, l.image AS lead_image, c.name_en AS contact_name, o.name AS opportunity_name FROM activities t
                LEFT JOIN leads l ON l.id = t.lead_id
                LEFT JOIN contacts c ON c.id = t.contact_id
                LEFT JOIN opportunities o ON o.id = t.opportunity_id';
    }

    protected function label(array $row): string { return (string) $row['topic']; }

    protected function searchable(): array { return ['t.topic', 't.code', 'l.name_en', 'c.name_en', 'o.name']; }

    protected function filters(): array
    {
        return [
            'type'   => ['label' => __('All types'), 'options' => Catalog::tr(Catalog::ACTIVITY_TYPES), 'column' => 't.activity_type'],
            'status' => ['label' => __('All statuses'), 'options' => Catalog::tr(Catalog::ACTIVITY_STATUSES), 'column' => 't.status'],
            'when'   => ['label' => __('Any time'), 'options' => ['overdue' => __('Overdue'), 'today' => __('Today'), 'upcoming' => __('Upcoming')],
                         'sql' => "CASE ? WHEN 'overdue' THEN t.status = 'PLANNED' AND t.start_at < now() WHEN 'today' THEN t.start_at::date = CURRENT_DATE ELSE t.status = 'PLANNED' AND t.start_at >= now() END"],
        ] + $this->commonFilters();
    }

    protected function fields(?array $row): array
    {
        $types = Catalog::tr(Catalog::ACTIVITY_TYPES);
        $want  = (string) Request::query('type');

        return [
            '_what'      => ['section' => __('Activity')],
            'activity_type' => ['label' => __('Type'), 'type' => 'select', 'options' => $types, 'rules' => 'required', 'default' => isset($types[$want]) ? $want : 'CALL', 'search' => false],
            'status'     => ['label' => __('Status'), 'type' => 'select', 'options' => Catalog::tr(Catalog::ACTIVITY_STATUSES), 'rules' => 'required', 'default' => 'PLANNED'],
            'topic'      => ['label' => __('Topic'), 'rules' => 'required|max:200', 'span' => 2, 'example' => 'Follow-up call about the quotation'],
            'start_at'   => ['label' => __('Starts'), 'type' => 'datetime', 'rules' => 'nullable|date'],
            'code'       => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'help' => __('Leave empty to number it automatically.')],

            'call_direction'   => ['label' => __('Direction'), 'type' => 'select', 'options' => Catalog::tr(Catalog::CALL_DIRECTIONS), 'rules' => 'nullable', 'show_when' => 'activity_type=CALL'],
            'call_duration'    => ['label' => __('Call length (minutes)'), 'type' => 'number', 'rules' => 'nullable|integer', 'show_when' => 'activity_type=CALL'],
            'meeting_type'     => ['label' => __('Meeting type'), 'type' => 'select', 'options' => Catalog::tr(Catalog::MEETING_TYPES), 'rules' => 'nullable', 'show_when' => 'activity_type=MEETING'],
            'meeting_duration' => ['label' => __('Meeting length (minutes)'), 'type' => 'number', 'rules' => 'nullable|integer', 'show_when' => 'activity_type=MEETING'],
            'meeting_location' => ['label' => __('Location or link'), 'rules' => 'nullable|max:255', 'span' => 2, 'show_when' => 'activity_type=MEETING'],

            '_related'   => ['section' => __('Related to')],
            'lead_id'    => $this->remoteField('lead_id', $row, ['label' => __('Lead'), 'rules' => 'required', 'span' => 2,
                             'display' => fn ($r) => $r['lead_name'] ?? null, 'href' => fn ($r) => $r['lead_id'] ? '/crm/leads/'.$r['lead_id'] : null]),
            'contact_id' => $this->remoteField('contact_id', $row, ['label' => __('Contact'), 'rules' => 'nullable', 'depends' => 'lead_id',
                             'display' => fn ($r) => $r['contact_name'] ?? null, 'href' => fn ($r) => $r['contact_id'] ? '/crm/contacts/'.$r['contact_id'] : null]),
            'opportunity_id' => $this->remoteField('opportunity_id', $row, ['label' => __('Opportunity'), 'rules' => 'nullable', 'depends' => 'lead_id',
                             'display' => fn ($r) => $r['opportunity_name'] ?? null, 'href' => fn ($r) => $r['opportunity_id'] ? '/crm/opportunities/'.$r['opportunity_id'] : null]),

            '_reminder'  => ['section' => __('Reminder')],
            'notify_me'  => ['label' => __('Remind me'), 'type' => 'checkbox', 'help' => __('The reminder shows on your CRM dashboard.')],
            'notify_before' => ['label' => __('Remind me before'), 'type' => 'select', 'rules' => 'nullable', 'default' => '15', 'search' => false,
                             'options' => ['0' => __('At the start'), '5' => __('5 minutes'), '15' => __('15 minutes'), '30' => __('30 minutes'), '60' => __('1 hour'), '1440' => __('1 day')],
                             'display' => fn ($r) => $r['notify_me'] ? ($r['notify_before'] ? $r['notify_before'].' min' : __('At the start')) : null],

            '_more'      => ['section' => __('Notes and files')],
            'description' => ['label' => __('Notes'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable'],
            'files'      => ['label' => __('Attachments'), 'type' => 'custom', 'partial' => 'crm/fields/files', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable'],
        ] + $this->ownershipFields($row);
    }

    protected function columns(): array
    {
        $tones = ['PLANNED' => 'info', 'DONE' => 'success', 'CANCELLED' => 'neutral'];

        return [
            'topic'  => ['label' => __('Activity'), 'primary' => true, 'sort' => 't.topic', 'render' => fn ($r) => Ui::person($r['topic'], __(Catalog::ACTIVITY_TYPES[$r['activity_type']] ?? $r['activity_type']).' · '.($r['code'] ?? ''), null, '/crm/activities/'.$r['id'])],
            'when'   => ['label' => __('When'), 'sort' => 't.start_at', 'render' => function ($r) {
                if (! $r['start_at']) { return Ui::dash(); }
                $late = $r['status'] === 'PLANNED' && strtotime($r['start_at']) < time();

                return '<span class="tabular-nums '.($late ? 'font-medium text-signal-700' : 'text-steel').'">'.e(format_date($r['start_at'], 'd M Y H:i')).'</span>'.($late ? ' <span class="badge bg-signal-50 text-signal-800">'.e(__('Overdue')).'</span>' : '');
            }],
            'lead'   => ['label' => __('Lead'), 'sort' => 'l.name_en', 'tone' => 'amber', 'render' => fn ($r) => $r['lead_id'] ? Ui::person((string) $r['lead_name'], $r['opportunity_name'] ?: $r['contact_name'], $r['lead_image'], '/crm/leads/'.$r['lead_id']) : Ui::dash()],
            'status' => ['label' => __('Status'), 'sort' => 't.status', 'render' => fn ($r) => Ui::badge(__(Catalog::ACTIVITY_STATUSES[$r['status']] ?? $r['status']), $tones[$r['status']] ?? 'neutral')],
            'created' => $this->createdColumn(),
        ];
    }

    protected function prepareMore(array $data, ?array $existing): array
    {
        $type = $data['activity_type'] ?? $existing['activity_type'] ?? 'CALL';
        if ($type !== 'CALL') {
            $data['call_direction'] = $data['call_duration'] = null;
        }
        if ($type !== 'MEETING') {
            $data['meeting_type'] = $data['meeting_duration'] = $data['meeting_location'] = null;
        }
        $data['notify_before'] = (int) ($data['notify_before'] ?? 0);
        // a changed time or reminder setting means the reminder has not been sent yet
        $same = $existing
            && ($existing['start_at'] ? strtotime((string) $existing['start_at']) : null) === (! empty($data['start_at']) ? strtotime((string) $data['start_at']) : null)
            && (int) $existing['notify_before'] === $data['notify_before'] && filter_var($existing['notify_me'], FILTER_VALIDATE_BOOL) === ! empty($data['notify_me']);
        if (! $same) {
            $data['reminder_sent_at'] = null;
        }

        // Fill in the chain: opportunity → its company and contact; contact → its company
        if (! empty($data['opportunity_id'])) {
            $o = DB::first('SELECT lead_id, contact_id FROM opportunities WHERE id = ?', [$data['opportunity_id']], 'crm');
            $data['lead_id']    = $data['lead_id'] ?: ($o['lead_id'] ?? null);
            $data['contact_id'] = $data['contact_id'] ?: ($o['contact_id'] ?? null);
        }
        if (! empty($data['contact_id']) && empty($data['lead_id'])) {
            $data['lead_id'] = DB::scalar('SELECT lead_id FROM contacts WHERE id = ?', [$data['contact_id']], 'crm');
        }

        return $data;
    }

    /** Mark done / cancel / re-open with one click. */
    public function setStatus(int $id): never
    {
        $row    = $this->findForChange($id, 'edit');
        $status = (string) Request::input('status');
        if (! isset(Catalog::ACTIVITY_STATUSES[$status])) {
            throw new ValidationException(['status' => __('Unknown status.')]);
        }
        DB::exec('UPDATE activities SET status = ?, updated_by = ?, updated_at = now() WHERE id = ?', [$status, $this->user()->id, $id], 'crm');
        Activity::log('updated', 'activity', $id, $row['topic'], ['Marked activity ":label" as :status', ['label' => $row['topic'], 'status' => Catalog::ACTIVITY_STATUSES[$status]]],
            ['changes' => ['status' => ['from' => __(Catalog::ACTIVITY_STATUSES[$row['status']]), 'to' => __(Catalog::ACTIVITY_STATUSES[$status])]]], $this->owner($row), module: 'crm');
        Session::flash('success', __('Marked as :status.', ['status' => mb_strtolower(__(Catalog::ACTIVITY_STATUSES[$status]))]));
        redirect('/crm/activities/'.$id);
    }

    protected function badges(array $row): array
    {
        $tones = ['PLANNED' => 'info', 'DONE' => 'success', 'CANCELLED' => 'neutral'];
        $b = [Ui::badge(__(Catalog::ACTIVITY_TYPES[$row['activity_type']] ?? $row['activity_type']), 'violet'), Ui::badge(__(Catalog::ACTIVITY_STATUSES[$row['status']] ?? $row['status']), $tones[$row['status']] ?? 'neutral')];
        if ($row['status'] === 'PLANNED' && $row['start_at'] && strtotime($row['start_at']) < time()) { $b[] = Ui::badge(__('Overdue'), 'danger'); }

        return $b;
    }

    protected function subtitleFor(array $row): string
    {
        return implode(' · ', array_filter([$row['code'], $row['start_at'] ? format_date($row['start_at'], 'd M Y H:i') : null, $row['opportunity_name'] ?: $row['contact_name'] ?: $row['lead_name']]));
    }

    protected function showTop(array $row): string
    {
        $edit = can($this->resource, 'edit') && $this->canModify($row, 'edit');

        return $edit && $row['status'] === 'PLANNED' ? partial('crm/panels/activity-actions', ['row' => $row]) : '';
    }

    protected function statCards(): array
    {
        return [
            $this->card(__('Planned'), $this->figure('count(*)', "t.status = 'PLANNED'"), null, 'info', 'clock'),
            $this->card(__('Today'), $this->figure('count(*)', "t.status = 'PLANNED' AND t.start_at::date = CURRENT_DATE"), null, 'neutral', 'clock', '/crm/activities?when=today'),
            $this->card(__('Overdue'), $this->figure('count(*)', "t.status = 'PLANNED' AND t.start_at < now()"), null, 'danger', 'clock', '/crm/activities?when=overdue'),
            $this->card(__('Done this month'), $this->figure('count(*)', "t.status = 'DONE' AND date_trunc('month', t.updated_at) = date_trunc('month', now())"), null, 'success', 'check'),
        ];
    }
}
