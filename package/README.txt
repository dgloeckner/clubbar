Club Bar - Member-Managed Bar/Club POS System
==============================================

Installation:
1. Upload all files to your web hosting document root (e.g. public_html/)
2. Make sure backend/storage/ and backend/logs/ are writable
3. Open your domain in a browser — you will be redirected to /install.php
4. Follow the installation wizard

Where your data is kept:
  The installer looks for a writable directory above your document root and, if
  it finds one, puts config.php (database password), the scanned SEPA mandates
  and the logs there — out of reach of the webserver. It creates a small file
  called data-path.php in the document root naming that directory.

  If your hosting has no writable directory above the document root, those files
  stay in backend/ and are protected by the .htaccess rules shipped with Club
  Bar. That works, but it depends on your host honouring .htaccess. The
  installer tells you which of the two you got, on the last screen.

  Do not delete data-path.php: without it the application looks for its config
  in the document root and will send you back to the installer.

Requirements:
- PHP 8.3 or higher
- MySQL 5.7+ or MariaDB 10.5+
- Apache with mod_rewrite enabled
- PHP extensions: pdo_mysql, json, mbstring, fileinfo

Updating:
1. Download the new release ZIP
2. Upload and overwrite all files (your config, data-path.php, mandates and
   logs are preserved)
3. Visit /upgrade.php to apply pending migrations. The upgrade also offers to
   move your data out of the document root if it is still inside it — an
   explicit step you can decline, and undo later.

More info: https://github.com/dgloeckner/clubbar
