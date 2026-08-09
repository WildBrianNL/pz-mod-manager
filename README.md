# Project Zomboid Mod Manager

A [Pelican Panel](https://pelican.dev) plugin that turns the two cryptic lines in
a Project Zomboid server config (`WorkshopItems=` and `Mods=`) into a mod manager
your whole team can use.

It adds a **Mods** page to the server panel. The page is visible to anyone with
file access to the server — not just admins — and only appears for Project
Zomboid servers.

![The Mods page, with its main features numbered: real load status, adding by ID or collection, auto-sort, alerts that carry their own fix, bulk selection, load-order locks, per-mod update detection and per-mod actions](docs/hero.png)

## Why

Managing Project Zomboid mods by hand is error-prone:

- `WorkshopItems=` decides what gets **downloaded**, `Mods=` decides what gets
  **loaded**, and the two silently drift apart. A mod missing from
  `WorkshopItems=` is never downloaded by connecting clients.
- The internal mod id you need for `Mods=` only exists inside `mod.info` *after*
  the mod has been downloaded.
- Build 42 mods ship several versions in one folder (`common/`, `42.14/`), and
  one Workshop item can contain several separate mods.
- A mod with an unmet dependency is silently skipped by the server.

This plugin handles all of that and shows you what the server is *actually*
doing.

## Features

**Adding mods**
- Paste a Workshop ID, an item URL, or a **whole collection URL** — collections
  expand to every item they contain.
- Optional *auto-enable*: the plugin reads the mod id advertised on Steam so a
  single restart is often enough. The guess is verified against the real
  `mod.info` after download and corrected automatically if it was wrong.

**Honest status**
The plugin reads the server log to see which mods the running server actually
loaded, so every mod reports the truth:

| Status | Meaning |
| --- | --- |
| running | enabled and loaded by the server |
| restart to apply | enabled, on disk, not loaded yet |
| downloads on restart | enabled, not downloaded yet |
| server could not load it | the server tried and refused — usually a missing dependency |
| not found | listed in `Mods=` but nowhere on the server |

**Load order**
- Drag a mod to a new position, or use the arrows for single steps and keyboard
  use. The order in the list is the load order.
- **Auto-sort** performs a topological sort on the `require=` dependencies in
  each `mod.info`, with frameworks first.
- **Lock a mod to its position** and auto-sort works around it. Sorting can only
  see `require=` and `category=framework`, and not every framework declares
  either, so some mods have to be held in place by hand. A lock pins against
  auto-sort, not against your own edits: drag a locked mod and its lock moves
  with it.
- Known framework ids are hoisted to the front of `Mods=` on every write. The
  server skips a mod whose dependency has not loaded yet without reporting it,
  and a list rebuilt from a directory scan could otherwise leave a framework
  sitting behind the mods that need it.
- Warns when the load order has changed but has not been applied yet.

**Working in bulk**
- Tick mods in either list and enable, disable or delete the whole selection in
  one action.
- *Select all shown* follows the search box, so it never reaches past what you
  are looking at.
- A bulk delete is one config write and one delete call, not one per mod, and
  mods sharing a Workshop item are taken out together because their files leave
  together.

**Keeping the config correct**
- Workshop IDs missing from `WorkshopItems=` are restored automatically.
- Duplicate entries are removed.
- Disabling a mod also removes its Workshop ID, so clients stop downloading it,
  while the files stay on the server for a quick re-enable.
- Deleting a mod removes it from the config *and* deletes its files.

**Problem detection** — each with a one-click fix where one exists:
missing dependencies, mods that failed to load, build mismatches, mods that
threw errors during the last boot, map mods absent from `Map=`, fatal
world-loading crashes, and pending downloads.

**Nice to have**
- Titles, categories and thumbnails from Steam.
- Installed version, install date, and an *update available* badge when Steam has
  a newer build than the files on disk.
- Auto-update status card with a verbose toggle that shows the latest
  check summary and detailed step-by-step diagnostics.
- Workshop changelog viewer.
- Restart the server from the page (respects the `control.restart` permission).
- English and Dutch, through standard Laravel language files.

## Auto-restart when Steam has a newer version

A Workshop mod that updates while the server is running leaves the server on the
old files while every client holds the new ones. Project Zomboid refuses the
mismatch, so nobody new can join. Players already on notice nothing, which is
what makes it nasty: the server looks healthy while turning away everyone who
tries to connect. Only a restart clears it, and the same is true of a game
update.

Switch it on under **Auto-restart on updates** on the Mods page. It is off by
default, per server.

What happens when something is outdated:

1. Everyone in game is warned, five minutes ahead by default.
2. The world is saved and a panel backup starts, so the snapshot holds a saved
   game rather than whatever was in memory.
3. A second warning goes out a minute before.
4. A ten second countdown, then the server restarts through the normal power
   action, so the egg's own `quit` saves and shuts down cleanly.
5. Once it is back, the plugin checks whether the update actually landed.

Warning text, all timings and the countdown are settings. So is the minimum gap
between restarts, which stops a modder publishing five updates in a row from
costing five restarts. An empty message means "say nothing".

If nobody is online the warnings and countdown are skipped, because there is
nobody to warn.

### AUTO_UPDATE is not optional

The steamcmd images only re-run steamcmd on boot when the egg variable
`AUTO_UPDATE` is `1`. Without it a restart downloads nothing, the update is still
outstanding afterwards, and the next check restarts again. That is a reboot loop
on a live server, so the plugin refuses to switch auto-restart on until the
variable is set, and offers to set it for you. It takes effect at the next start.

### It stops rather than trying twice

After the restart the plugin waits for the server to come back and re-runs the
check. If the update still has not been applied it disables itself and shows
why, rather than restarting again. A server that needs a human beats a server in
a loop.

Every lookup that fails is treated as "no information", never as "no update":
Steam unreachable, log unreadable, player count unknown. Nothing restarts on a
failed lookup. If the player count cannot be established the full warning
sequence runs anyway, since announcing to an empty server costs nothing and
restarting without warning costs somebody their progress.

### Steam request volume

One check is one request, not one per mod: `GetPublishedFileDetails` takes fifty
ids per call, and the whole panel is batched into a single warm-up call before
any server is ticked, so six servers sharing mods still cost one request. At the
five minute default that is under 300 requests a day for a panel, against an
endpoint whose informal ceiling is in the tens of thousands.

There is no API key to add. `GetPublishedFileDetails` is keyless and rate limited
by IP, so the lever is fewer calls rather than a bigger quota. If a fetch does
fail, Steam is left alone for ten minutes and cached metadata is used in the
meantime, which means an outage degrades to "no update detected" rather than to a
retry storm.

The game build check is cached per app id and shared across servers, so it costs
roughly six requests an hour to `api.steamcmd.net` no matter how many servers run
Project Zomboid.

### Detecting a game update

The installed build comes from `steamapps/appmanifest_<appid>.acf`, which
SteamCMD writes with the build id it last downloaded. The current public build
comes from `api.steamcmd.net`, because Valve does not publish an app's build id
through the Web API without authenticating as an owner. That is a third party, so
a failed lookup skips the game check and leaves the mod check running. It can be
switched off entirely.

## Requirements

- Pelican Panel (tested on `1.0.0-beta35`), with its scheduler running
  (`artisan schedule:run` every minute) if you want auto-restart
- A Project Zomboid egg (the page only appears for eggs whose name contains
  "zomboid")
- Outbound HTTPS from the panel container for Steam metadata — the plugin works
  without it, just without titles, categories and thumbnails

## Installation

**Via the panel** — download the release zip and use the Import button on the
plugin list.

**Manually** — place the plugin in your panel's `plugins` directory. The folder
name must match the plugin id:

```bash
cd /var/www/pelican/plugins        # or /opt/pelican/plugins for the Docker setup
git clone https://github.com/WildBrianNL/pz-mod-manager.git
php artisan p:plugin:install pz-mod-manager
php artisan optimize:clear
```

For the official Docker Compose setup the panel runs in a container, so run the
artisan commands inside it:

```bash
docker exec pelican-panel-1 php artisan p:plugin:install pz-mod-manager
docker exec pelican-panel-1 php artisan optimize:clear
```

### A note on copying files in by hand

The panel writes a `meta` block into `plugin.json` when it installs a plugin, and
reads the enabled state back out of that file. Overwriting `plugin.json` with the
copy from git therefore uninstalls the plugin, and the Mods page then answers
"route could not be found". If you deploy by copying files, run
`php artisan p:plugin:install pz-mod-manager` afterwards, or keep the `meta`
block. The Import button does the right thing on its own.

## Updating

From 2.4.1 the panel handles this itself. `plugin.json` declares an
`update_url`, Pelican fetches it every ten minutes, compares the version there
against the installed one, and flags the row on the admin plugin list when a
newer release exists. Updating is the button next to that flag, or:

```bash
php artisan p:plugin:update pz-mod-manager
```

Two things are worth knowing before waiting for a notice that will not come.

**Update checks are disabled on canary panels.** Pelican returns early when
`config('app.version')` is `canary`, both for the check and for the download
URL, so nothing appears and nothing errors either. Tagged panel releases are
fine.

**Installs older than 2.4.1 will not announce it.** They carry
`"update_url": null`, so there is nothing for the panel to poll. Update by hand
once; every release after this one arrives on its own.

## Permissions

| Action | Required subuser permission |
| --- | --- |
| View the page | `file.read` |
| Add, enable, disable, delete, reorder, lock, bulk actions | `file.update` |
| Restart button | `control.restart` |
| Change auto-restart settings | `file.update` |
| Set `AUTO_UPDATE` from the panel | `startup.update` |

The scheduled restart itself runs as the panel, not as a user, so it is not
subject to subuser permissions. Whoever can change the settings can cause a
restart.

## How it works

The server `.ini` is the single source of truth — the plugin keeps no mod list of
its own and re-reads the config before every change, so two people editing at the
same time cannot clobber each other's work. It refuses to write whenever the
config could not be read first.

Installed mods are discovered through a single Wings search for `mod.info`, which
handles every Build 42 layout. Parsed results are cached against a fingerprint of
the files on disk, so a finished download appears immediately while repeat visits
stay fast.

## Configuration

`config/pz-mod-manager.php` holds the Steam app id, the fallback build (the
running server's build is detected automatically), the egg name to match, and
cache lifetimes. It also exposes auto-update tuning:

- `auto_update.check_interval_minutes` (default `1`)
- `auto_update.max_steam_meta_age_seconds` (default `60`)

## Building a release zip

The folder inside the zip must be named `pz-mod-manager`:

```bash
zip -r pz-mod-manager.zip pz-mod-manager -x '*.git*'
```

`update.json` on `main` is what every installed copy polls, so it has to name a
release that already exists. Publish the release and its `pz-mod-manager.zip`
asset first, then update the manifest. The other order points every install at a
download that 404s, and the update button fails for everyone until it is fixed.

## Contributing

Issues and pull requests are welcome. Please keep changes focused.

## Credits

Built by [WildBrianNL](https://github.com/WildBrianNL) for a public Project
Zomboid server, with substantial help from Claude (Anthropic). Not affiliated
with The Indie Stone or Valve.

## License

MIT — see [LICENSE](LICENSE).
