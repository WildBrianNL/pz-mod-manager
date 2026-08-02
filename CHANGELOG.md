# Changelog

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
