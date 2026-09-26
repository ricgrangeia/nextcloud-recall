# Recall

A Nextcloud app for **episodic memory**: what happened, when it happened, and who or what it was
linked to — so it can be recalled later by date.

> Status: early. The data layer (schema, entities, mappers) is in place. The OCS API, the service
> layer and the UI are not written yet.

## Why not just use Notes

Two differences, and both are the reason this app exists.

**The date that matters is when it *happened*, not when you wrote it down.** Both are stored, and
they are different columns. A note written today about a trip in 2019 is a 2019 memory.

**An episode is not an island.** It links to contacts, photos, calendar events and tasks, and those
links are queryable in *both* directions — not only "what is attached to this memory", but "every
memory involving this person".

## The query that justifies it

"What happened in this window, in previous years?" — the thing you cannot ask a notes app, and the
thing that makes an assistant able to remind you of something nobody thought to ask about.

It is served by a dedicated endpoint rather than by scanning everything, which is what makes it
usable by an agent on a schedule instead of only on demand.

## Design decisions worth knowing

- **`occurred_at` vs `created_at`** — see above. Kept separate, always.
- **`occurred_year` / `occurred_md` are denormalised integers.** A Nextcloud app must run on MySQL,
  PostgreSQL and SQLite, whose date functions differ enough that portable month/day extraction in
  SQL is painful. Storing the year and `MMDD` as indexed integers turns "same window in previous
  years" into integer arithmetic, identical on all three engines.
- **`occurred_precision`** (`day` / `month` / `year`) — many memories are vague ("summer 2019").
  Without this the schema forces a precision that does not exist.
- **Links store a human-readable `label` captured at link time.** The photo will be deleted, the
  event removed, the contact will disappear — and the memory must still read correctly when the
  thing it points at is gone.
- **Links reference stable identifiers, never paths.** File IDs, CardDAV UIDs, CalDAV UIDs. Paths
  change on move or rename and the link dies silently.

## Data model

`recall_episodes` — the episode itself, indexed on `(user_id, occurred_at)`,
`(user_id, occurred_md)` and `(user_id, type)`.

`recall_links` — `(episode_id, kind, ref, label)`, where `kind` is one of `contact`, `photo`,
`event`, `task`. Indexed on `episode_id` and on `(kind, ref)` for the reverse lookup.

## Privacy

The code is public; the data never is. Everything lives in your own Nextcloud database. Note that
the repository deliberately ignores SQL dumps, database files and local fixtures — if you
contribute, use invented example data, never real memories.

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
