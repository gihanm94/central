package crm

import (
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// Responsible: the people responsible for a project (project_members) or a task (task_assignees), several at once.
// Whoever stops being responsible is written to ownership_history, so the record keeps who had it before.

type respDef struct{ Table, FK, Path string }

var respMap = map[string]respDef{
	"project": {"project_members", "project_id", "projects"},
	"task":    {"task_assignees", "task_id", "tasks"},
}

func respIDs(typ string, id int) []int {
	m := respMap[typ]
	var out []int
	for _, v := range db.On("crm").Column("SELECT user_id FROM "+m.Table+" WHERE "+m.FK+" = ? ORDER BY created_at, user_id", id) {
		out = append(out, conv.Int(v))
	}
	return out
}

// respIDsFor: [record id => [user ids]] for a page of records.
func respIDsFor(typ string, recordIDs []any) map[int][]int {
	out := map[int][]int{}
	seen := map[int]bool{}
	var ids []any
	for _, v := range recordIDs {
		n := conv.Int(v)
		if !seen[n] {
			seen[n] = true
			ids = append(ids, n)
		}
	}
	if len(ids) == 0 {
		return out
	}
	m := respMap[typ]
	in := strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",")
	for _, r := range db.On("crm").Select("SELECT "+m.FK+" AS rid, user_id FROM "+m.Table+" WHERE "+m.FK+" IN ("+in+") ORDER BY created_at, user_id", ids...) {
		out[r.Int("rid")] = append(out[r.Int("rid")], r.Int("user_id"))
	}
	return out
}

// respSync: make the list exactly wanted; tell the new people; remember the ones who left.
func respSync(c *app.Ctx, typ string, id int, wanted []int, by int, label string, notify bool) {
	m := respMap[typ]
	pm := peopleMap(people())
	var want []int
	seen := map[int]bool{}
	for _, u := range wanted {
		if _, ok := pm[u]; ok && !seen[u] {
			seen[u] = true
			want = append(want, u)
		}
	}
	have := respIDs(typ, id)
	hasInt := func(l []int, v int) bool {
		for _, x := range l {
			if x == v {
				return true
			}
		}
		return false
	}
	for _, uid := range want {
		if hasInt(have, uid) {
			continue
		}
		db.On("crm").Exec("INSERT INTO "+m.Table+" ("+m.FK+", user_id, added_by) VALUES (?, ?, ?) ON CONFLICT DO NOTHING", id, uid, by)
		if notify && uid != by {
			respTell(c, typ, id, uid, by, label)
		}
	}
	for _, uid := range have {
		if hasInt(want, uid) {
			continue
		}
		db.On("crm").Exec("DELETE FROM "+m.Table+" WHERE "+m.FK+" = ? AND user_id = ?", id, uid)
		respRemember(typ, id, uid, 0, by, "", "responsible")
	}
}

// respReplace hands one person's part to somebody else (keeps the history).
func respReplace(typ string, id, from, to, by int, note string) bool {
	m := respMap[typ]
	if db.On("crm").Scalar("SELECT 1 FROM "+m.Table+" WHERE "+m.FK+" = ? AND user_id = ?", id, from) == nil {
		return false
	}
	db.On("crm").Exec("DELETE FROM "+m.Table+" WHERE "+m.FK+" = ? AND user_id = ?", id, from)
	db.On("crm").Exec("INSERT INTO "+m.Table+" ("+m.FK+", user_id, added_by) VALUES (?, ?, ?) ON CONFLICT DO NOTHING", id, to, by)
	respRemember(typ, id, from, to, by, note, "responsible")
	return true
}

func nz(n int) any {
	if n == 0 {
		return nil
	}
	return n
}

// respRemember writes one hand-over. The names are saved with the ids, so they stay readable after a user is deleted.
func respRemember(typ string, id, from, to, by int, note, role string) {
	ns := names("users", []any{from, to, by})
	nameOf := func(u int) any {
		if u == 0 {
			return nil
		}
		if n, ok := ns[u]; ok {
			return n
		}
		return nil
	}
	var n any
	if note != "" {
		n = conv.Substr(note, 0, 255)
	}
	db.On("crm").Insert("ownership_history", db.Cols{{Name: "entity_type", Value: typ}, {Name: "entity_id", Value: id}, {Name: "role", Value: role},
		{Name: "from_user_id", Value: nz(from)}, {Name: "from_user_name", Value: nameOf(from)},
		{Name: "to_user_id", Value: nz(to)}, {Name: "to_user_name", Value: nameOf(to)},
		{Name: "changed_by", Value: by}, {Name: "changed_by_name", Value: nameOf(by)},
		{Name: "note", Value: n}})
}

var respWho = []struct{ K, IDCol, NameCol string }{{"from", "from_user_id", "from_user_name"}, {"to", "to_user_id", "to_user_name"}, {"by", "changed_by", "changed_by_name"}}

// respHistory: who had the record before, newest first. Each person as from_/to_/by_ name, id and gone (user deleted).
func respHistory(typ string, id int) []db.Row {
	rows := db.On("crm").Select("SELECT * FROM ownership_history WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 30", typ, id)
	var ids []any
	seen := map[int]bool{}
	for _, r := range rows {
		for _, w := range respWho {
			if n := r.Int(w.IDCol); n != 0 && !seen[n] {
				seen[n] = true
				ids = append(ids, n)
			}
		}
	}
	live := map[int]db.Row{}
	if len(ids) > 0 {
		in := strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",")
		for _, u := range db.Select("SELECT id, name, deleted_at FROM users WHERE id IN ("+in+")", ids...) {
			live[u.Int("id")] = u
		}
	}
	for _, r := range rows {
		var fill db.Cols
		for _, w := range respWho {
			uid := r.Int(w.IDCol)
			saved := r[w.NameCol]
			if uid != 0 && saved == nil {
				if l, ok := live[uid]; ok {
					saved = l.Str("name")
					fill = append(fill, db.Col{Name: w.NameCol, Value: saved})
				}
			}
			if uid != 0 {
				r[w.K+"_id"] = uid
			} else {
				r[w.K+"_id"] = nil
			}
			if saved != nil {
				r[w.K+"_name"] = conv.Str(saved)
			} else if uid != 0 {
				r[w.K+"_name"] = "#" + conv.Str(uid)
			} else {
				r[w.K+"_name"] = nil
			}
			l, ok := live[uid]
			r[w.K+"_gone"] = uid != 0 && (!ok || l["deleted_at"] != nil)
		}
		if len(fill) > 0 {
			db.On("crm").Update("ownership_history", fill, db.Cols{{Name: "id", Value: r["id"]}})
		}
	}
	return rows
}

func respTell(c *app.Ctx, typ string, id, userID, by int, label string) {
	actor, ok := names("users", []any{by})[by]
	if !ok {
		actor = c.T("Someone")
	}
	app.Deliver(c, "crm", "assigned", userID,
		app.Text(`:name made you responsible for :type ":label"`, "name", actor, "type", typ, "label", label),
		app.Text(":label", "label", label),
		c.URL("/crm/"+respMap[typ].Path+"/"+conv.Str(id)), by)
}
