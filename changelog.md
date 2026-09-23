# Changelog

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
