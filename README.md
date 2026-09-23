<p align="center">
  <img src="assets/images/icon--wp-fileshelf.svg" alt="WP FileShelf logo" width="110">
</p>

<h1 align="center">WP FileShelf</h1>

<p align="center"><strong>Staff-managed WordPress file storage with stable public links and easy file replacement.</strong></p>

WP FileShelf gives a WordPress site a simple document shelf outside the normal Media Library. Staff can upload files from WordPress admin or from a password-protected front-end page, while public file URLs stay predictable even when a file is replaced later.

## What can WP FileShelf do?

- Store managed files outside the normal WordPress Media Library.
- Give files stable, configurable public URLs such as `https://example.com/hr-docs/nj.pdf`.
- Let administrators upload, search, sort, rename, replace, delete, and copy file links from one screen.
- Give staff a password-protected uploader at `/wpfileshelf/` without requiring a WordPress account.
- Detect duplicate filenames and let authenticated staff intentionally replace the existing file.
- Keep the existing filename and public URL when a file is replaced.
- Limit uploads to file types selected under **Advanced**.
- Receive normal WordPress plugin updates from GitHub Releases.

## Quick Start

1. Download the latest WP FileShelf ZIP from the GitHub **Releases** page.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP, install it, and activate **WP FileShelf**.
4. Open **WP FileShelf → Settings**.
5. Set the staff upload password and public file link path.
6. Open **Advanced** and choose which file types can be uploaded.
7. Open **Files** to upload and manage documents.

PDF is enabled by default.

## Files

The **Files** screen contains the admin uploader and the FileShelf directory in one place.

Each stored file shows:

- Filename
- Optional internal **Name / Description**
- Original upload date
- Last modified date
- File size and MIME type
- **Edit** action
- **Copy Link** action
- **Delete** action

The directory supports AJAX search, sortable columns, and 20-item pagination.

### Replacing a file

Use **Edit** to replace an existing file without changing its public link.

For example, if this link already exists:

```text
https://example.com/hr-docs/nj.pdf
```

an administrator can upload a newer PDF as its replacement. FileShelf stores the new contents under the existing `nj.pdf` filename, so links already used in pages, emails, or documents keep working.

The replacement must use the same file extension as the existing FileShelf file.

## Staff Upload Page

The front-end uploader is always available at:

```text
https://example.com/wpfileshelf/
```

It is locked until an administrator sets an upload password under **WP FileShelf → Settings**.

Anyone who has the URL and password can upload the file types enabled under **Advanced**. A successful login creates a signed HttpOnly session cookie for up to eight hours.

Both the WordPress admin password field and the front-end login field include **View / Hide** controls while a password is being typed. Saved passwords are hashed by WordPress and are never stored in a recoverable form, so an existing saved password cannot be displayed later.

### Duplicate filenames

If authenticated staff upload a filename that already exists, FileShelf asks whether they want to replace it.

Confirming replacement keeps the existing:

- Filename
- Public URL
- Name / Description
- Original upload date

The file contents and modified date are updated.

## Public File Links

The public link prefix is configurable under **Settings**.

If the link path is set to:

```text
hr-docs
```

then a file named `nj.pdf` is available at:

```text
https://example.com/hr-docs/nj.pdf
```

The URL is virtual. The actual file is stored separately by FileShelf, so the public link does not expose the physical storage directory.

## File Storage

Physical files live in:

```text
WP_CONTENT_DIR/wp-fileshelf-uploads/
```

On a standard WordPress installation, that resolves to `/wp-content/wp-fileshelf-uploads/`. FileShelf uses `WP_CONTENT_DIR` rather than hard-coding `wp-content`, so customized WordPress content-directory layouts continue to work. The shelf remains separate from the normal `/wp-content/uploads/` Media Library structure.

FileShelf creates a marker file plus direct-access deny rules inside the storage directory. Apache/LiteSpeed can use the generated `.htaccess` rules, and IIS can use the generated `web.config`. Nginx ignores those files, so an Nginx site that must completely block the physical storage URL should also deny the FileShelf storage path at the server level. Public FileShelf links continue to use the configured virtual route, such as `/hr-docs/nj.pdf`.


### Storage migration from v0.1.2 and earlier

Versions before v0.1.3 stored files at the WordPress root in `/wp-fileshelf-uploads/`. On upgrade, FileShelf automatically migrates a marker-verified legacy shelf into `WP_CONTENT_DIR/wp-fileshelf-uploads/`. It first attempts a direct directory move; if the host requires a copy instead, FileShelf verifies the copied files before removing the old directory. If the migration cannot complete safely, the legacy shelf is left intact and FileShelf continues using it until migration succeeds.

## Allowed File Types

Open **WP FileShelf → Advanced** to choose the allowed upload types.

The list is built from WordPress's supported MIME types instead of maintaining a separate hard-coded list. The selected types apply to both the admin uploader and `/wpfileshelf/`.

PDF is enabled by default on a new installation.

## Updating

WP FileShelf checks the GitHub repository's normal releases and integrates with the standard WordPress plugin updater.

To force a check:

1. Open **WP FileShelf → Settings**.
2. Find **Plugin Updates**.
3. Click **Check for Updates**.

The latest normal GitHub release is then made available through WordPress when its version is newer than the installed version.

## Uninstalling

Deactivating WP FileShelf never deletes stored files.

By default, deleting the plugin also preserves its files, database metadata, and settings so a later reinstall can reconnect to the existing shelf.

For a complete removal, enable:

**Advanced → Delete all FileShelf data when this plugin is deleted**

When that option is enabled, uninstall removes the verified FileShelf storage directory under `WP_CONTENT_DIR`, FileShelf database table, settings, and update cache. It also safely checks for the older pre-v0.1.3 root-level directory in case a storage migration was interrupted.

## Requirements

- WordPress 6.4+
- PHP 8.0+

## Developer Notes

WP FileShelf is intentionally separate from the WordPress Media Library. Managed files are tracked in a small FileShelf database table and routed through FileShelf's own public rewrite endpoint.

Primary plugin classes live in `includes/`:

- `class-wfs-db.php` — database setup
- `class-wfs-files.php` — storage, validation, and file CRUD
- `class-wfs-router.php` — virtual public file URLs
- `class-wfs-frontend.php` — password-protected staff uploader
- `class-wfs-admin.php` — AJAX admin interface
- `class-wfs-updater.php` — GitHub Release updater

## Data Storage

File metadata is stored in the WordPress table:

```text
{prefix}wfs_files
```

Plugin settings are stored as normal WordPress options using the `wfs_` prefix.

## GitHub Release Workflow

For a normal release:

1. Update the plugin version in `wp-fileshelf.php`.
2. Update `changelog.md`.
3. Commit and push the release code.
4. Create a Git tag such as `v0.1.3`.
5. Publish a normal GitHub Release for that tag.

WP FileShelf's updater ignores draft and prerelease releases.

## Changelog

See [`changelog.md`](changelog.md) for release history.

## License

GPL-3.0-or-later. See [`LICENSE`](LICENSE).

## Credits

Built by **Asenka Interactive**.

Primary developer: **Brian McLendon** — [GitHub](https://github.com/eyeofbri)
