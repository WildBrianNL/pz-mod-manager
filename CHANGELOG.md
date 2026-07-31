# Changelog

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
