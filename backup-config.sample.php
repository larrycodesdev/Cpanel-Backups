<?php
/**
 * Copy this file to backup-config.php (same folder), fill in real values,
 * then chmod it 600. NEVER commit backup-config.php to git — it holds
 * database passwords and R2 secret keys.
 *
 * Put backup-config.php OUTSIDE public_html so it can't be requested
 * over HTTP by anyone, e.g. /home/youruser/backup-scripts/backup-config.php
 */

return [

    // One entry per Laravel app / database on this cPanel account.
    'databases' => [
        [
            'name' => 'projectname_db',
            'host' => 'localhost',
            'user' => 'projectname_dbuser',
            'pass' => 'REPLACE_ME',
        ],
        // add more databases here...
    ],

    // One entry per project's file directory you want zipped and backed up.
    // Usually you want the app minus vendor/ and node_modules/ (they're
    // reproducible from composer.json / package.json and just waste space).
    'directories' => [
        [
            'label' => 'projectname',
            'path'  => '/home/youruser/projectname.prefix.ng',
            'exclude' => ['vendor', 'node_modules', 'storage/framework/cache', 'storage/logs'],
        ],
        // add more project directories here...
    ],

    // Cloudflare R2 (S3-compatible). Create the bucket + an API token
    // (R2 > Manage API Tokens > create with Object Read & Write) first.
    'r2' => [
        'account_id'  => 'REPLACE_ME',            // Cloudflare account ID
        'access_key'  => 'REPLACE_ME',
        'secret_key'  => 'REPLACE_ME',
        'bucket'      => 'REPLACE_ME',             // e.g. "cpanel1-backups"
        // Prefix keeps this cPanel account's backups separated inside the bucket
        // if you point all 3 cPanel accounts at the same bucket.
        'key_prefix'  => 'cpanel1/',
    ],

    // Local scratch space to build dumps/zips in before upload (needs enough
    // free disk quota for the largest single backup you'll produce).
    'tmp_dir' => '/home/youruser/backup-scripts/tmp',

    // Log file (rotate/prune manually now and then, or logrotate if available).
    'log_file' => '/home/youruser/backup-scripts/backup.log',

    // Optional: a healthchecks.io (or similar) "ping" URL. If set, the script
    // pings it on success so you get alerted the moment a cron run stops
    // happening or starts failing, instead of finding out during a disaster.
    'healthcheck_url' => null, // e.g. 'https://hc-ping.com/your-uuid-here'
];
