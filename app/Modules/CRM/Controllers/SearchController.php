<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Modules\CRM\Support\Access;

/**
 * Feeds the searchable dropdowns (leads, contacts, opportunities): 20 at a time, only what the person may see,
 * so a long list can be searched and scrolled without loading it all.
 *   GET /crm/search/leads?q=siam&page=2        (contacts and opportunities can be narrowed with &lead=ID)
 */
class SearchController extends Controller
{
    private const PER_PAGE = 20;

    /** @return array{0: string, 1: string} resource permission and entity type */
    private function kind(string $kind): array
    {
        return match ($kind) {
            'leads'         => ['crm_leads', 'lead'],
            'contacts'      => ['crm_contacts', 'contact'],
            'opportunities' => ['crm_opportunities', 'opportunity'],
            default         => abort(404),
        };
    }

    public function find(string $kind): never
    {
        [$perm, $type] = $this->kind($kind);
        $this->authorize($perm, 'view');

        $q      = trim((string) Request::query('q', ''));
        $page   = max(1, (int) Request::query('page', 1));
        $lead   = (int) Request::query('lead', 0);
        $vis    = Access::visible($this->user(), 't', $type);
        $where  = ['t.deleted_at IS NULL', $vis['sql']];
        $params = $vis['params'];
        $like   = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

        switch ($kind) {
            case 'leads':
                $select = 't.id, t.name_en AS label, t.name_th AS sub, t.image AS img';
                $from   = 'leads t';
                $order  = 't.name_en, t.id';
                if ($q !== '') {
                    $where[] = '(t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.code ILIKE ? OR t.tax_id ILIKE ?)';
                    array_push($params, $like, $like, $like, $like);
                }
                break;
            case 'contacts':
                $select = "t.id, trim(coalesce(t.salutation || ' ', '') || t.name_en) AS label, concat_ws(' · ', l.name_en, t.job_title) AS sub, t.avatar AS img";
                $from   = 'contacts t LEFT JOIN leads l ON l.id = t.lead_id';
                $order  = 't.name_en, t.id';
                if ($lead) {
                    $where[] = 't.lead_id = ?';
                    $params[] = $lead;
                }
                if ($q !== '') {
                    $where[] = '(t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.email ILIKE ? OR l.name_en ILIKE ?)';
                    array_push($params, $like, $like, $like, $like);
                }
                break;
            default:
                $select = "t.id, t.name AS label, concat_ws(' · ', t.code, l.name_en) AS sub, NULL AS img";
                $from   = 'opportunities t LEFT JOIN leads l ON l.id = t.lead_id';
                $order  = 't.id DESC';
                if ($lead) {
                    $where[] = 't.lead_id = ?';
                    $params[] = $lead;
                }
                if ($q !== '') {
                    $where[] = '(t.name ILIKE ? OR t.code ILIKE ? OR l.name_en ILIKE ?)';
                    array_push($params, $like, $like, $like);
                }
        }

        $rows = DB::select("SELECT {$select} FROM {$from} WHERE ".implode(' AND ', $where)." ORDER BY {$order} LIMIT ".(self::PER_PAGE + 1).' OFFSET '.(($page - 1) * self::PER_PAGE), $params, 'crm');
        $more = count($rows) > self::PER_PAGE;
        $items = array_map(fn ($r) => ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'sub' => $r['sub'] ?: null, 'img' => upload_url($r['img'])], array_slice($rows, 0, self::PER_PAGE));

        json_response(['items' => $items, 'more' => $more]);
    }
}
