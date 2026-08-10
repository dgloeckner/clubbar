Club Bar - Member-Managed Bar/Club POS System
==============================================

Installation:
1. Upload all files to your web hosting document root (e.g. public_html/)
2. Make sure backend/storage/ and backend/logs/ are writable
3. Open your domain in a browser — you will be redirected to /install.php
4. Follow the installation wizard

File permissions:
  This package ships the modes it should be installed with: 0700 on
  backend/storage/ and backend/logs/, 0755 on the document root, and the
  installer writes config.php with 0600. On shared hosting the other accounts
  on the machine are the reason — a world-readable storage/ is readable by
  every one of them, and it holds your members' scanned mandates.

  You should not need to chmod anything. If your upload tool applied its own
  permissions and the installer reports storage/ or logs/ as not writable, use
  0700 first, and only widen (0770, then 0777) if your host runs PHP as a
  different user than the one that owns your files. The installer narrows what
  it can and verifies afterwards, so it will never leave a directory it broke;
  Settings -> Security in the admin panel shows the modes it ended up with.

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
