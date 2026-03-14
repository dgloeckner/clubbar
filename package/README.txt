Club Bar - Member-Managed Bar/Club POS System
==============================================

Installation:
1. Upload all files to your web hosting document root (e.g. public_html/)
2. Make sure storage/ and logs/ directories inside api/ are writable
3. Open your domain in a browser — you will be redirected to /install.php
4. Follow the installation wizard

Requirements:
- PHP 8.3 or higher
- MySQL 5.7+ or MariaDB 10.5+
- Apache with mod_rewrite enabled
- PHP extensions: pdo_mysql, json, mbstring

Updating:
1. Download the new release ZIP
2. Upload and overwrite all files (config.php is preserved)
3. Visit /install.php — enter your admin password to run pending migrations

More info: https://github.com/dgloeckner/clubbar
