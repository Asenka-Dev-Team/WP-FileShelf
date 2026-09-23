# Changelog

## 0.1.3

- Moved FileShelf physical storage from the WordPress root to `WP_CONTENT_DIR/wp-fileshelf-uploads/`.
- Kept FileShelf storage separate from the normal WordPress Media Library uploads directory.
- Added automatic migration for marker-verified pre-v0.1.3 root-level storage, with verified copy fallback when a direct directory move is unavailable.
- Added a safe legacy-storage fallback so files remain available if migration cannot complete immediately.
- Updated Apache/LiteSpeed and IIS direct-access protection files for the new storage location.
- Updated uninstall cleanup to verify and remove the new storage location and, when present, an interrupted legacy location.
- Added the FileShelf storage-version option used to track migration state.

## 0.1.2

- Moved the WordPress admin uploader into the Files screen above the file directory and removed the separate Upload tab.
- Added View / Hide controls to the admin upload-password field and the front-end password field.
- Kept saved upload passwords securely hashed; the admin visibility control applies only to newly entered password text.
- Restyled file actions with compact Dashicon + label buttons modeled after WP FileTrace.
- Restyled update actions to use the same button-with-icon pattern.
- Reworked the README into a clearer FileTrace-style guide with logo branding, quick-start instructions, workflow explanations, update notes, and developer reference.

## 0.1.1

- Fixed activation fatal error on PHP 8.0 and PHP 8.1 caused by use of the PHP 8.2-only standalone `true` return type.
- Updated the affected return declaration to remain compatible with the plugin's stated PHP 8.0+ requirement.

## 0.1.0

Initial development release.

- Added FileShelf database and root-level storage directory.
- Added configurable virtual public file links.
- Added password-protected `/wpfileshelf/` staff upload page.
- Added duplicate-file replace confirmation flow.
- Added admin Files, Upload, Settings, and Advanced tabs.
- Added AJAX search, sorting, pagination, upload, edit/replace, delete, copy-link, and settings actions.
- Added WordPress MIME-derived allowed file type settings with PDF enabled by default.
- Added safe opt-in uninstall cleanup.
- Added GitHub Release updater modeled after WP FileTrace.
