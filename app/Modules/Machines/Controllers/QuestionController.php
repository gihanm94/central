<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Support\DB;
use App\Core\Support\ValidationException;
use App\Modules\Machines\Support\Mx;

/** The question bank: every checklist item of every machine is one of these. */
class QuestionController extends MachinesController
{
    protected string $resource = 'machines_questions';
    protected string $table = 'question';
    protected string $type = 'question';
    protected string $singular = 'question';
    protected string $plural = 'questions';
    protected string $base = '/machines/questions';
    protected string $icon = 'list';
    protected string $orderBy = 't.id DESC';

    protected function label(array $row): string { return str_limit((string) $row['detail'], 60); }

    protected function searchable(): array { return ['t.detail', 't.description']; }

    protected function filters(): array
    {
        return ['kind' => ['label' => __('All answer kinds'), 'options' => ['1' => __('OK / NG'), '0' => __('Text or number')], 'sql' => 't.is_choice = (? = \'1\')']];
    }

    protected function fields(?array $row): array
    {
        return [
            'detail'      => ['label' => __('Question'), 'rules' => 'required|max:255', 'span' => 2, 'example' => 'Is the emergency stop working?'],
            'description' => ['label' => __('How to check'), 'type' => 'textarea', 'rules' => 'nullable|max:2000', 'span' => 2, 'help' => __('Shown under the question on the checklist.')],
            'is_choice'   => ['label' => __('OK / NG answer'), 'type' => 'checkbox', 'default' => true, 'help' => __('Untick when the answer is a number or text (e.g. a temperature).')],
        ];
    }

    protected function columns(): array
    {
        return [
            'detail' => ['label' => __('Question'), 'primary' => true, 'sort' => 't.detail', 'render' => fn ($r) => '<span class="font-medium">'.e($r['detail']).'</span>'.($r['description'] ? '<span class="block max-w-xl truncate text-xs text-steel">'.e($r['description']).'</span>' : '')],
            'kind'   => ['label' => __('Answer'), 'sort' => 't.is_choice', 'render' => fn ($r) => Mx::pill($r['is_choice'] ? __('OK / NG') : __('Text / number'), 'neutral')],
            'used'   => ['label' => __('Used on machines'), 'render' => fn ($r) => (string) DB::scalar('SELECT count(DISTINCT machine_code) FROM (SELECT machine_code FROM machine_checklist WHERE question_id = ? UNION SELECT machine_code FROM maintenance_checklist WHERE question_id = ?) x', [$r['id'], $r['id']], 'machines')],
        ];
    }

    protected function prepare(array $data, ?array $existing): array
    {
        $detail = trim((string) $data['detail']);
        if (DB::scalar('SELECT 1 FROM question WHERE lower(detail) = lower(?) AND deleted_at IS NULL'.($existing ? ' AND id <> ?' : ''), $existing ? [$detail, $existing['id']] : [$detail], 'machines')) {
            throw new ValidationException(['detail' => __('This question already exists.')]);
        }
        $data['detail'] = $detail;

        return $this->stamp($data, $existing);
    }

    protected function beforeDelete(array $row): void
    {
        if (DB::scalar('SELECT 1 FROM machine_checklist WHERE question_id = ? UNION SELECT 1 FROM maintenance_checklist WHERE question_id = ? LIMIT 1', [$row['id'], $row['id']], 'machines')) {
            back('error', __('This question is on a machine\'s checklist. Take it off the machines first.'));
        }
    }
}
