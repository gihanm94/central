<?php
declare(strict_types=1);

namespace App\Modules\Machines\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\ValidationException;

/** The rules of a checklist: which questions a person gets, how answers are checked, who approves, who is told. */
final class Checklists
{
    /**
     * What this person has to do on this machine right now.
     * - The responsible person, while the machine's check is PENDING (a new week/month) and not on a weekend: their re-check.
     *   They get every item that is not yet ticked this period (general items + those that came due). It then goes to approval.
     * - Everybody else (and the responsible person on other days): the general items only.
     *
     * @return array{recheck: bool, items: array, empty: bool, weekend: bool}
     */
    public static function plan(array $m, CurrentUser $u, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $weekend = (int) $now->format('N') >= 6;
        $recheck = (int) $m['responsible_person_id'] === $u->id && $m['check_status'] === 'PENDING' && ! $weekend;
        $sql = 'SELECT i.id, i.question_id, i.reset_time, i.check_status, q.detail, q.description, q.is_choice FROM machine_checklist i JOIN question q ON q.id = i.question_id
                WHERE i.machine_code = ? AND q.deleted_at IS NULL AND '.($recheck ? 'NOT i.check_status' : 'i.reset_time = ?').' ORDER BY i.id';
        $items = DB::select($sql, $recheck ? [$m['machine_code']] : [$m['machine_code'], Mx::GENERAL], 'machines');

        return ['recheck' => $recheck, 'items' => $items, 'empty' => ! $items, 'weekend' => $weekend];
    }

    /**
     * Posted answers → the list saved with the record. Every item needs an answer; "NG" needs a remark.
     * @param array $items rows of plan()['items']; $posted answers[<item id>] = ['answer' => OK|NG|NA|text, 'remark' => '']
     */
    public static function answers(array $items, array $posted): array
    {
        if (! $items) {
            throw new ValidationException(['answers' => __('This machine has no checklist questions yet. Ask its manager to add them.')]);
        }
        $out = [];
        $missing = 0;
        foreach ($items as $i) {
            $p = (array) ($posted[$i['id']] ?? []);
            $a = trim((string) ($p['answer'] ?? ''));
            $remark = mb_substr(trim((string) ($p['remark'] ?? '')), 0, 500);
            if ($i['is_choice']) {
                if (! in_array($a, ['OK', 'NG', 'NA'], true)) { $missing++; continue; }
                if ($a === 'NG' && $remark === '') {
                    throw new ValidationException(['answers' => __('Write what is wrong for ":q".', ['q' => $i['detail']])]);
                }
            } elseif ($a === '') {
                $missing++; continue;
            } else {
                $a = mb_substr($a, 0, 200);
            }
            $out[] = ['id' => (int) $i['id'], 'question_id' => (int) $i['question_id'], 'detail' => $i['detail'], 'is_choice' => (bool) $i['is_choice'], 'reset_time' => $i['reset_time'], 'answer' => $a, 'remark' => $remark];
        }
        if ($missing) {
            throw new ValidationException(['answers' => __('Answer every question (:n left).', ['n' => $missing])]);
        }

        return $out;
    }

    public static function canApprove(array $r, CurrentUser $u): bool
    {
        return ($r['checklist_status'] === 'PENDING SUPERVISOR' && (int) $r['supervisor'] === $u->id)
            || ($r['checklist_status'] === 'PENDING MANAGER' && (int) $r['manager'] === $u->id);
    }

    /** The approval steps for the detail page: [[label, person, date, state]] with state done | now | waiting | skipped | late */
    public static function chain(array $r): array
    {
        $names = Mx::names([$r['created_by'], $r['supervisor'], $r['manager']]);
        $steps = [[__('Checked'), $names[(int) $r['created_by']] ?? $r['user_name'], $r['created_at'], 'done']];
        if (! $r['recheck']) {
            return $steps;
        }
        $st = (string) $r['checklist_status'];
        $late = str_ends_with($st, '-OVERDUE');
        $base = str_replace('-OVERDUE', '', $st);
        if ($r['supervisor']) {
            $steps[] = [__('Supervisor'), $names[(int) $r['supervisor']] ?? '—', $r['date_supervisor_checked'], $r['date_supervisor_checked'] ? 'done' : ($base === 'PENDING SUPERVISOR' ? ($late ? 'late' : 'now') : 'waiting')];
        }
        if ($r['manager']) {
            $steps[] = [__('Manager'), $names[(int) $r['manager']] ?? '—', $r['date_manager_checked'], $r['date_manager_checked'] ? 'done' : ($base === 'PENDING MANAGER' ? ($late ? 'late' : 'now') : 'waiting')];
        }

        return $steps;
    }

    /** Tell the right people: the approver of a re-check, and the team when something is wrong. */
    public static function notifyAfterCheck(array $m, array $rec, int $id, int $ng, CurrentUser $actor): void
    {
        $url = url('/machines/checklists/'.$id);
        try {
            if ($rec['recheck']) {
                $to = $rec['checklist_status'] === 'PENDING SUPERVISOR' ? (int) $m['supervisor_id'] : (int) $m['manager_id'];
                if ($to && $to !== $actor->id) {
                    Notifier::deliver('machines', 'assigned', $to, ['key' => 'Checklist of :code waits for your approval', 'params' => ['code' => $m['machine_code']]],
                        ['key' => ':who checked :code · :name. Please approve it.', 'params' => ['who' => $actor->name, 'code' => $m['machine_code'], 'name' => $m['machine_name']]], $url, $actor->id);
                }
            }
            if ($ng > 0 || $rec['machine_status'] !== 'OPERATIONAL') {
                foreach (Mx::team($m) as $uid) {
                    if ($uid !== $actor->id) {
                        Notifier::deliver('machines', 'updated', $uid, ['key' => 'Problem reported on :code', 'params' => ['code' => $m['machine_code']]],
                            ['key' => ':who checked :code · :name: :n items are NG, machine is :status.', 'params' => ['who' => $actor->name, 'code' => $m['machine_code'], 'name' => $m['machine_name'], 'n' => $ng, 'status' => strtolower($rec['machine_status'])]], $url, $actor->id);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[machines] notify after check: '.$e->getMessage());
        }
    }

    public static function notifyApproval(array $r, string $next, CurrentUser $actor): void
    {
        $url = url('/machines/checklists/'.$r['id']);
        try {
            if ($next === 'PENDING MANAGER' && $r['manager'] && (int) $r['manager'] !== $actor->id) {
                Notifier::deliver('machines', 'assigned', (int) $r['manager'], ['key' => 'Checklist of :code waits for your approval', 'params' => ['code' => $r['machine_code']]],
                    ['key' => ':who approved the check of :code. Please give the final approval.', 'params' => ['who' => $actor->name, 'code' => $r['machine_code']]], $url, $actor->id);
            } elseif ($next === 'COMPLETED' && $r['created_by'] && (int) $r['created_by'] !== $actor->id) {
                Notifier::deliver('machines', 'updated', (int) $r['created_by'], ['key' => 'Your check of :code is approved', 'params' => ['code' => $r['machine_code']]],
                    ['key' => ':who approved the check of :code. It is complete.', 'params' => ['who' => $actor->name, 'code' => $r['machine_code']]], $url, $actor->id);
            }
        } catch (\Throwable $e) {
            error_log('[machines] notify approval: '.$e->getMessage());
        }
    }
}
