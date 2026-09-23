# WP FileShelf

WP FileShelf is a staff-managed WordPress file repository built as a sibling to WP FileTrace.

It stores files outside the normal WordPress Media Library in:

```text
/wp-fileshelf-uploads/
```

and exposes them through a configurable virtual link path, for example:

```text
https://example.com/hr-docs/nj.pdf
```

## v0.1.0 features

- GitHub Release-based plugin updates from `Asenka-Dev-Team/WP-FileShelf`.
- Top-level WordPress admin screen with AJAX tabs and CRUD actions.
- Files directory with 20-per-page pagination, search, sortable columns, edit/replace, delete, and copy-link controls.
- Admin upload tab.
- Password-protected staff upload page at `/wpfileshelf/`.
- Duplicate filename detection on the staff uploader with an explicit replace confirmation flow.
- Replacement preserves the existing FileShelf filename/link and original upload date while updating modified date.
- Configurable public link prefix.
- Allowed file types derived from WordPress's supported upload MIME types; PDF is enabled by default.
- Optional full data/file removal when the plugin is deleted. This is disabled by default.

## Install

1. Upload the `wp-fileshelf` folder or ZIP through WordPress Plugins.
2. Activate **WP FileShelf**.
3. Open **WP FileShelf → Settings**.
4. Set the staff upload password and public file link path.
5. Open **Advanced** to choose allowed file types.

## Staff upload page

The upload page is always:

```text
https://example.com/wpfileshelf/
```

A configured password is required before uploads are enabled.

Successful authentication is stored in an HttpOnly signed cookie for up to 8 hours. Changing or clearing the upload password invalidates existing FileShelf upload sessions.

## File storage

Physical files live in:

```text
ABSPATH/wp-fileshelf-uploads/
```

WP FileShelf creates a marker file and Apache/LiteSpeed/IIS deny rules inside the directory. Nginx ignores `.htaccess`, so sites that require the physical path to be completely inaccessible should also deny `/wp-fileshelf-uploads/` at the Nginx server level.

Public FileShelf links are served through WordPress's rewrite system rather than by exposing the physical storage URL.

## Replacement behavior

### Staff frontend

If an uploaded filename already exists, FileShelf asks whether the uploader wants to replace it. Confirming replacement keeps the original FileShelf filename, link, display name, and upload date and updates the file contents and modified date.

### WordPress admin

The pencil action allows an administrator to change the display name and optionally upload a replacement file. A replacement can have a different source filename, but it must use the same extension as the existing FileShelf file. FileShelf stores it under the existing filename so the public link does not change.

## Uninstall behavior

By default, deleting the plugin preserves all FileShelf files, database metadata, and settings.

Under **Advanced → Uninstall Behavior**, an administrator can explicitly enable complete cleanup. When enabled, deleting the plugin removes the FileShelf database table, FileShelf settings/cache, and the verified `/wp-fileshelf-uploads/` directory.

Deactivating WP FileShelf never deletes files.

## Requirements

- WordPress 6.4+
- PHP 8.0+

## License

GPL-3.0-or-later.
