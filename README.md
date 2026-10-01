# FolderNest

English | [繁體中文](README.zh-TW.md) | [简体中文](README.zh-CN.md)

**Real folders for the WordPress media library — without ever touching a file.**

FolderNest adds nested, drag-and-drop folders, tags, batch tools and a usage scanner to the WordPress media library. It only writes classification data: your files are never moved, renamed or re-URLed, and deactivating the plugin leaves your site exactly as it was.

📦 **Install from WordPress.org** (once approved) · 🏠 [Product site](https://foldernest.plugnest.dev/) · 📖 [Documentation](https://foldernest.plugnest.dev/docs.html)

## Highlights

- **Folders that behave like folders** — nest them, drag media in, pin favorites, give them colors. Browse the whole library or just one folder (with its subfolders).
- **Everywhere you pick media** — the same folder filters work in the media modal used by Gutenberg, the Classic editor, Elementor and WooCommerce, and uploads can land straight into the folder you are viewing.
- **Tags, batch edit, batch move** — multi-select anything, then edit titles/alt text, tag, move or delete in one action.
- **Usage scanning & cleanup** — scans post content (including Elementor JSON and escaped URLs) so "unused" really means unused. Review before deleting; nothing is removed without your click.
- **Auto-assign rules** — filename/mime rules with dry-run preview.
- **Full audit log with undo** — every batch action keeps a snapshot; one click restores it.
- **Three languages** — English, 繁體中文, 简体中文 out of the box.
- **Clean uninstall** — removes its taxonomy, terms and options on demand.

## Free vs Pro

The free version is a complete media manager. [FolderNest Pro](https://foldernest.plugnest.dev/) adds: unused-media cleanup wizard, missing-alt batch workflow, folder import/export, one-click import from FileBird / Real Media Library / HappyFiles and other folder plugins, folder access roles, and delete-folder-with-files.

## Installation

1. In your WordPress admin, go to **Plugins → Add New** and search for "FolderNest" (or upload the zip).
2. Activate — a "FolderNest" item appears in the admin menu.
3. Create folders on the left, drag media in, done.

## Frequently asked questions

**Does it change my file paths or URLs?** No. Folders are a classification layer (a taxonomy), not physical directories. Deactivate and everything is exactly as before.

**What happens to my media when I deactivate it?** Nothing. Assignments are stored as taxonomy relationships and simply become dormant.

**Is it compatible with Elementor / Gutenberg / WooCommerce?** Yes — the folder filter is injected into the standard media modal those editors use.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

© PlugNest — [plugnest.dev](https://www.plugnest.dev/)
