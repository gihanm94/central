<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Attachments;

/**
 * Things every CRM record can have: comments, files and sharing.
 * Routes look like /crm/{leads|contacts|opportunities|campaigns|activities}/{id}/comments …
 * The record itself is always opened through its own controller, so the "can this person see it" rule is the same everywhere.
 */
final class RecordController extends Controller
{
    private const CONTROLLERS = [
        'lead' => LeadController::class, 'contact' => ContactController::class, 'opportunity' => OpportunityController::class,
        'campaign' => CampaignController::class, 'activity' => ActivityController::class,
        'project' => ProjectController::class, 'task' => TaskController::class,
    ];

    /** @return array{0: CrmController, 1: array} controller and the record (404 if hidden from this person) */
    private function open(string $kind, int $id): array
    {
        $type = Access::typeForPath($kind) ?? abort(404);
        $ctl  = new (self::CONTROLLERS[$type])();

        return [$ctl, $ctl->openRecord($id)];
    }

    private function back(CrmController $ctl, int $id, string $anchor = ''): never
    {
        redirect('/crm/'.Access::ENTITIES[$ctl->entityType()]['path'].'/'.$id.$anchor);
    }

    /* ----------------------------------------------------------- comments */

    public static function commentsFor(string $type, int $id): array
    {
        $rows  = DB::select('SELECT * FROM comments WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC', [$type, $id], 'crm');
        $names = Access::names('users', array_column($rows, 'created_by'));
        foreach ($rows as &$r) {
            $r['author'] = $names[$r['created_by']] ?? __('Former member');
        }

        return $rows;
    }

    public function comment(string $kind, int $id): never
    {
        [$ctl, $row] = $this->open($kind, $id);
        if (! $ctl->hasComments()) {
            abort(404);
        }
        $data = $this->validate(['body' => 'required|max:5000'], ['body' => __('comment')]);
        DB::insert('comments', ['entity_type' => $ctl->entityType(), 'entity_id' => $id, 'body' => $data['body'], 'created_by' => $this->user()->id], 'crm');

        Activity::log('commented', $ctl->entityType(), $id, $ctl->recordLabel($row), ['Commented on :type ":label"', ['type' => $ctl->entityType(), 'label' => $ctl->recordLabel($row)]],
            ['comment' => str_limit($data['body'], 200)], $row['owner_id'] ? (int) $row['owner_id'] : null, null, false, 'crm');
        // The owner hears about it (bell, e-mail — whatever is switched on), unless they wrote it themselves.
        if (! empty($row['owner_id']) && (int) $row['owner_id'] !== $this->user()->id) {
            Notifier::deliver('crm', 'commented', (int) $row['owner_id'],
                ['key' => ':name commented on :type ":label"', 'params' => ['name' => $this->user()->name, 'type' => $ctl->entityType(), 'label' => $ctl->recordLabel($row)]],
                ['key' => ':comment', 'params' => ['comment' => str_limit($data['body'], 300)]],
                url('/crm/'.Access::ENTITIES[$ctl->entityType()]['path'].'/'.$id.'#comments'), $this->user()->id);
        }
        Session::flash('success', __('Comment added.'));
        $this->back($ctl, $id, '#comments');
    }

    public function deleteComment(string $kind, int $id, int $commentId): never
    {
        [$ctl] = $this->open($kind, $id);
        $c = DB::first('SELECT * FROM comments WHERE id = ? AND entity_type = ? AND entity_id = ?', [$commentId, $ctl->entityType(), $id], 'crm') ?? abort(404);
        if ((int) $c['created_by'] !== $this->user()->id && ! $this->user()->isAdmin()) {
            abort(403, __('You can only delete your own comments.'));
        }
        DB::exec('DELETE FROM comments WHERE id = ?', [$commentId], 'crm');
        Session::flash('success', __('Comment deleted.'));
        $this->back($ctl, $id, '#comments');
    }

    /* -------------------------------------------------------------- files */

    public function upload(string $kind, int $id): never
    {
        [$ctl, $row] = $this->open($kind, $id);
        if (! $ctl->hasFiles()) {
            abort(404);
        }
        if (! $ctl->allows($row, 'edit')) {
            abort(403, __("You can't add files to this record."));
        }
        $files = Attachments::incoming('files');
        if (! $files) {
            throw new ValidationException(['files' => __('Choose at least one file.')]);
        }
        $names = [];
        foreach ($files as $file) {
            $names[] = Attachments::store($file, $ctl->entityType(), $id, $this->user()->id)['original_name'];
        }
        Activity::log('attached', $ctl->entityType(), $id, $ctl->recordLabel($row), ['Attached :count file(s) to :type ":label"', ['count' => count($names), 'type' => $ctl->entityType(), 'label' => $ctl->recordLabel($row)]],
            ['files' => $names], $row['owner_id'] ? (int) $row['owner_id'] : null, null, false, 'crm');
        Session::flash('success', __(':count file(s) uploaded.', ['count' => count($names)]));
        $this->back($ctl, $id, '#files');
    }

    private function file(CrmController $ctl, int $id, int $fileId): array
    {
        return DB::first('SELECT * FROM attachments WHERE id = ? AND entity_type = ? AND entity_id = ?', [$fileId, $ctl->entityType(), $id], 'crm') ?? abort(404);
    }

    public function download(string $kind, int $id, int $fileId): never
    {
        [$ctl, $row] = $this->open($kind, $id);
        $f = $this->file($ctl, $id, $fileId);
        Activity::log('download', $ctl->entityType(), $id, $f['original_name'], ['Downloaded file ":label"', ['label' => $f['original_name']]], [], $row['owner_id'] ? (int) $row['owner_id'] : null, null, false, 'crm');
        Attachments::send($f);
    }

    public function deleteFile(string $kind, int $id, int $fileId): never
    {
        [$ctl, $row] = $this->open($kind, $id);
        $f = $this->file($ctl, $id, $fileId);
        if ((int) $f['uploaded_by'] !== $this->user()->id && ! Access::isManager($this->user(), $row)) {
            abort(403, __('Only the person who uploaded it or the record owner can remove a file.'));
        }
        Attachments::remove($f);
        Activity::log('updated', $ctl->entityType(), $id, $ctl->recordLabel($row), ['Removed file ":label"', ['label' => $f['original_name']]], [], null, null, false, 'crm');
        Session::flash('success', __('File removed.'));
        $this->back($ctl, $id, '#files');
    }

    /* ------------------------------------------------------------ sharing */

    public function share(string $kind, int $id): never
    {
        [$ctl, $row] = $this->open($kind, $id);
        $u = $this->user();
        if (! Access::isManager($u, $row)) {
            abort(403, __('Only the owner, the department head or an administrator can share this record.'));
        }
        $type   = Request::input('target_type') === 'department' ? 'department' : 'user';
        $access = Request::input('access') === 'edit' ? 'edit' : 'view';
        $target = (int) Request::input($type === 'user' ? 'user_id' : 'department_id');
        $field  = $type === 'user' ? 'user_id' : 'department_id';

        if ($type === 'user' && ! isset(Access::people()[$target])) {
            throw new ValidationException([$field => __('Pick a person from the list.')]);
        }
        if ($type === 'department' && ! isset(Access::departments()[$target])) {
            throw new ValidationException([$field => __('Pick a department from the list.')]);
        }
        if ($type === 'department' && (int) $row['department_id'] === $target && $access === 'view') {
            throw new ValidationException([$field => __('That department can already see this record.')]);
        }
        if ($type === 'user' && (int) $row['owner_id'] === $target) {
            throw new ValidationException([$field => __('That person already owns this record.')]);
        }

        Access::addShare($ctl->entityType(), $id, $type, $target, $access, $u->id);
        $name = $type === 'user' ? Access::people()[$target]['name'] : Access::departments()[$target];
        Activity::log('shared', $ctl->entityType(), $id, $ctl->recordLabel($row),
            ['Shared :type ":label" with :who (:access)', ['type' => $ctl->entityType(), 'label' => $ctl->recordLabel($row), 'who' => $name, 'access' => $access === 'edit' ? 'can edit' : 'view only']],
            ['target' => $type, 'target_id' => $target, 'access' => $access], $row['owner_id'] ? (int) $row['owner_id'] : null, null, false, 'crm');
        // tell the person (or everyone in the department) that something was shared with them
        $recipients = $type === 'user' ? [$target] : array_map('intval', array_column(DB::select('SELECT id FROM users WHERE department_id = ? AND is_active AND deleted_at IS NULL AND id <> ? LIMIT 60', [$target, $u->id]), 'id'));
        foreach ($recipients as $to) {
            if ($to !== $u->id) {
                Notifier::deliver('crm', 'shared', $to,
                    ['key' => ':name shared :type ":label" with you', 'params' => ['name' => $u->name, 'type' => $ctl->entityType(), 'label' => $ctl->recordLabel($row)]],
                    ['key' => $access === 'edit' ? 'You can edit it.' : 'You can view it.', 'params' => []],
                    url('/crm/'.Access::ENTITIES[$ctl->entityType()]['path'].'/'.$id), $u->id);
            }
        }
        Session::flash('success', __('Shared with :who.', ['who' => $name]));
        $this->back($ctl, $id, '#sharing');
    }

    public function unshare(string $kind, int $id, int $shareId): never
    {
        [$ctl, $row] = $this->open($kind, $id);
        if (! Access::isManager($this->user(), $row)) {
            abort(403, __('Only the owner, the department head or an administrator can change who this record is shared with.'));
        }
        DB::exec('DELETE FROM record_shares WHERE id = ? AND entity_type = ? AND entity_id = ?', [$shareId, $ctl->entityType(), $id], 'crm');
        Activity::log('shared', $ctl->entityType(), $id, $ctl->recordLabel($row), ['Stopped sharing :type ":label"', ['type' => $ctl->entityType(), 'label' => $ctl->recordLabel($row)]], [], null, null, false, 'crm');
        Session::flash('success', __('Access removed.'));
        $this->back($ctl, $id, '#sharing');
    }
}
