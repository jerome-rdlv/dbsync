# dbsync

A database synchronization command line tool with support for replacements, GZIP transfers and SSH tunneling. Its written in PHP but it executes bash commands.

I’m publishing this here because it might be useful to others, but USE OF THIS SCRIPT IS ENTIRELY AT YOUR OWN RISK. I accept no liability from its use.

That said, I’ve been successfully using this tool for several years to synchronize WordPress databases between my development, pre-production and production environments.  personnaly use this tool.

*dbsync* uses a configuration file to  define environments (like dev, prod, preprod, etc.) and replacements (different values for different environments). Have a look at the `dbsync.json` example config.

Use `dbsync --help` to get help.

A typical usage is `dbsync --source dev --target preprod` to synchronize the `dev` database with the `preprod` database (moving from `dev` to `preprod` and replacing `dev` values with `preprod` values).

## config example

```json
{
    "tables": "^wp_",
    "environments": {
        "dev": {
            "user": "dev",
            "pass": "dev",
            "base": "sync-db-test_dev"
        },
        "prod": {
            "user": "dev",
            "pass": "dev",
            "base": "sync-db-test_prod",
            "ssh": "jerome@example.org",
            "php": "/usr/local/bin/php.ORIG.5_4"
        }
    },
    "replacements": [
        {
            "include-cols": "option_value",
            "dev": "jerome-dev@example.org",
            "prod": "jerome-prod@example.org"
        },
        {
            "tables": "wp_options",
            "dev": "dev.example.org",
            "prod": "prod.example.org"
        }
    ]
}
```

This example config file define two environments. For the *dev* one, only *user*, *pass*
and *base* are needed. For the *prod* one, we use an ssh proxy (`jerome@example.org`)
and a specific PHP version (`/usr/local/bin/php.ORIG.5_4`).

## tables filtering

Tables to sync can be defined with the regex based directive `tables`. The
example config sync only tables which names begin with `wp_`. It’s possible
to name tables like this :

```
    "tables": "^(wp_post|wp_options|my_table)$"
```

## SSH tunneling

This tool can works with remote database through SSH. Simply define `ssh` entry
in the choosen environment. If your SSH key is set, no password will be prompted.

The value of the `ssh` directive is the connection string you would use to
connect your ssh server. In the case of the example config, you could connect
with `ssh jerome@example.org`.

## PHP executable

The PHP executable is used to execute replacements on the target database.
It can be configured on a per env basis, using the `php` key
in env configuration and giving the PHP executable path as value.

## search / replace

This tool use [interconnectit/Search-Replace-DB](https://github.com/interconnectit/Search-Replace-DB) for the replacements work. It is stored in *base64* in the *dbsync* command file.

Search-Replace-DB, *srdb*, is updated through composer and stored in *dbsync* with a composer script: *update-srdb*.

Some Search-Replace-DB command line options are usable: `tables`, `include-cols`, `exclude-cols` and `regex`. They must be defined for each replacement (see `dbsync.json`) using long option name.

## files

It is possible to use a file name in place of the environment name, either as source or as target. The replacements are done when synchronizing from a file to an environment (replacements are always done on target database).

To dump a database to a file: `dbsync --source prod --target prod-dump.sql`

To restore from a file: `dbsync --source prod-dump.sql --target prod`

If the file ends with `.gz` it will be gzipped. It is possible to use a gzipped file as source file.

## charset fix

You can use the `--fix` option to try a charset repair of your database.It may solve issues with double utf8 encoded characters using [this technique](http://blog.hno3.org/2010/04/22/fixing-double-encoded-utf-8-data-in-mysql/).

## backups

To backup a database is easy and quick with this tool, so do not hesitate to do so
before any risky operation.

```
    dbsync --source prod --target prod_20170512_1235.sql.gz
```

## Troubleshooting

If replacements failed, it may be due to a bad PHP version on the target env.
The SRDB script require PHP >= 5.3.0. In that case, you can use the `php` directive to
force use of another PHP binary on the target env (if such binary exists).

## TODO

Allow arbitrary queries in dbsync.json for specific updates / faster and more secure than find/replace.

Allow to use STDIN and STDOUT as source and target.

Improve this doc…
