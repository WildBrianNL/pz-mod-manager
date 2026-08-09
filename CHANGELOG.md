# Changelog

## 2.5.0

- **Auto-restart when Steam has a newer version.** A Workshop mod that updates
  while the server is running leaves the server on the old files, and Project
  Zomboid then refuses to let new players join. Existing players notice nothing,
  so the server looks fine while quietly turning away everyone who tries to
  connect. Only a restart clears it.

  With this on, the plugin checks Steam on an interval, and when something is
  outdated it warns everyone in game, takes a backup, counts down and restarts.
  Off by default: this restarts servers.

- **It refuses to run without AUTO_UPDATE.** The steamcmd images only re-run
  steamcmd on boot when that egg variable is 1. Without it a restart downloads
  nothing, the update is still outstanding afterwards, and the next check
  restarts again, forever. The settings panel checks the variable, refuses to
  switch the feature on without it, and offers to set it.

- **One attempt, then it stops.** After restarting, the plugin waits for the
  server to come back and checks whether the update actually landed. If it did
  not, it disables itself and says why instead of trying again.

- **Game updates too.** The installed build id comes from
  `appmanifest_<appid>.acf` on disk and is compared against the public branch. If
  that lookup fails the check is skipped rather than guessed at, because no
  server should restart on the word of an endpoint that might be down.

- Backups start at the first warning, right after a `save` so the snapshot holds
  a saved world rather than whatever was in memory. The restart waits a bounded
  amount of time for the backup and then goes ahead regardless, so a slow backup
  can delay a restart but not hold one hostage.

- Warning text, timings, countdown length and the gap between restarts are all
  settings, per server, stored beside the server files rather than in the panel
  config. Two servers on one panel can use different windows, and
  `optimize:clear` cannot silently re-enable the feature.

- Fixed, Dutch: the two bulk confirmation prompts were filed under `tooltip`
  instead of `confirm`, so Dutch users saw the English fallback on both.

  Auto-update grew out of [#1](https://github.com/WildBrianNL/pz-mod-manager/pull/1)
  by [AlfElFriki](https://github.com/AlfElFriki), whose scheduler wiring, player
  counting and Steam cache-age fix are still in here. Two things changed on the
  way in: the console command was `say`, which Project Zomboid does not have, so
  warnings went nowhere; and commands were posted to `/command` rather than
  Wings' `/commands`.

## 2.4.1

- **In-panel update notifications.** The plugin now declares an `update_url`, so
  Pelican checks for new releases itself, marks the row in the admin plugin list
  when one exists, and offers its own update button. No new code in the plugin:
  the panel has had this machinery all along and this release simply points at
  the manifest it was waiting for.

  Two caveats. Pelican disables update checks entirely on `canary` panels, so
  nothing appears there and nothing fails either. And the notice only reaches
  installs that already carry an `update_url`, so 2.4.0 will not announce this
  release: update to 2.4.1 by hand once, and later versions arrive on their own.

## 2.4.0

- **Bulk selection.** Tick mods in either list and enable, disable or delete the
  whole selection in one action. *Select all shown* follows the search box
  rather than reaching past it: on a list of a hundred and twenty mods, a
  select-all that ignores the filter is the most dangerous control on the page.
- A bulk delete is one config write and one Wings call, not one per mod. Looping
  the single-mod path would mean forty reads, forty writes and forty round
  trips, and a failure halfway would leave the config describing files that no
  longer exist.
- Deleting a mod that shares a Workshop item with others now takes its siblings
  out of the config too, because the item's files leave as a unit.
- Framework mods are hoisted to the front of `Mods=` on every write. Project
  Zomboid silently skips a mod whose dependency has not loaded yet, and a list
  rebuilt from a directory scan could otherwise leave a framework sitting behind
  the mods that need it. Ids are listed in `IniService::PRIORITY_MODS`.

## 2.3.0

- Lock a mod to its load-order position. Auto-sort works around locked mods
  instead of moving them: dependency sorting only knows about `require=` and
  `category=framework`, and not every framework declares either, so some mods
  have to stay put by hand.
- A lock pins against auto-sort, not against manual edits. Dragging or arrowing a
  locked mod moves its lock with it, so a lock always means "keep it where it is"
  rather than "keep it at the number it had three edits ago". Locks for mods that
  leave the load order are dropped.
- Locked mods are re-inserted in ascending index order after a sort, so several
  locks land correctly, and an index past the end of the list clamps to the end
  rather than dropping the mod from `Mods=`.

## 2.2.0

- Drag and drop to reorder the load list. Moving a framework from the bottom of
  a 100+ mod list used to take one click and one round trip per position; it is
  now a single gesture and a single request. The arrows are still there for
  precise single steps and for keyboard use.
- Reordering validates that the incoming list is a permutation of what is on
  disk. If the page was showing stale state it reloads instead of writing an
  order that silently drops or duplicates a mod, which would only surface on the
  next server start.
- Dragging is disabled while the search box is in use, because a filtered list
  does not reflect real load positions.

## 2.1.0

- Disable and Delete are now separate, always-visible actions. Disabling also
  removes the Workshop ID from `WorkshopItems=`, so clients stop downloading the
  mod while its files stay on the server.
- Installed mod version (`modversion`), install date and "update available"
  detection by comparing the Steam publish date with the files on disk.
- On-demand Workshop changelog viewer.
- Mods that the server refused to load (usually a missing dependency) now report
  **"server could not load it"** instead of an endless "restart to apply".
- Mods bundled in one Workshop item are named individually instead of all
  showing the same Steam title.
- Alert when the load order changed but has not been applied yet.
- Target build is detected from the running server instead of assumed.
- All structural styling is inline; plugin views are not covered by the panel's
  Tailwind build, which previously made thumbnails render at full size.

## 2.0.0

Rewrite focused on reliability.

- Installed mods are discovered with a single Wings search and the real Build 42
  layout is handled (`mods/<mod>/`, `common/`, `42.x/`). Mods shipping build
  folders were previously reported as "not downloaded" forever.
- Status is derived from what the server actually loaded, so *running*,
  *restart to apply* and *downloads on restart* are distinguished.
- Self-healing: missing Workshop IDs are restored, duplicates removed, and
  optimistic mod-id guesses corrected against `mod.info` after download.
- Dependency-aware auto-sort, categories and thumbnails from Steam, per-mod boot
  errors, collection import, restart button, English and Dutch translations.

## 1.0.0

First version: read and write `WorkshopItems=` / `Mods=` from the server panel.
