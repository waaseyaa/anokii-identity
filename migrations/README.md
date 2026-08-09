# Identity schema

The `identity_pillar` table is created and synchronized from the entity metadata
by Waaseyaa's deterministic schema commands. This directory is declared as the
package migration inventory so future data migrations have a stable home.

Revision storage also persists `entity_id`, `revision_author`,
`revision_created`, and `revision_log`. Host applications must classify those
four fields explicitly. The Sheg integration classifies them as `internal` and
requires field-access preflight to stay ready before cutover.
