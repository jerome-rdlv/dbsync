# dbsync

A MySQL migration command line tool with support for replacements,
GZIP transfers and SSH tunneling. Its written in PHP but it executes bash commands.

I’m publishing this here because it might be useful to others,
but USE OF THIS SCRIPT IS ENTIRELY AT YOUR OWN RISK. I accept no liability from its use.

That said, I’ve been successfully using this tool for several years to migrate WordPress
MySQL databases between development, pre-production and production environments.

*dbsync* uses a configuration file to  define environments (like dev, prod, preprod, etc.)
and replacements (different values for different environments).
Have a look at the `dbsync.yml` example config. If you put this file in your project directory
be careful NOT TO add it to the versioning system (it contains passwords).

Use `dbsync --help` to get help.

A typical usage is `dbsync --source dev --target preprod` to migrate the local `dev` database
to the remote `preprod` database (moving data from `dev` to `preprod` and
replacing `dev` values with `preprod` values).

## Config example

```yaml
# working only with tables that begin with “wp_”
tables: ^wp_

environments:
    dev:
        user: dev-user
        pass: dev-pass
        host: localhost
        base: sync-db-test_dev
        port: 3306
    prod:
        user: prod-user
        pass: prod-pass
        base: sync-db-test_prod

        # db host (often localhost and used with ssh param)
        host: localhost

        # connection will be done through ssh tunneling using these params
        ssh: jerome@example.org

        # path to a PHP binary on prod env (example for OVH)
        php: /usr/local/bin/php.ORIG.5_4

        # if targeting prod, a confirmation will be asked before proceeding
        protected: true

replacements:
    
    # replace only on `option_value` column
-   include-cols: option_value
    dev: jerome-dev@example.org
    prod: jerome-prod@example.org
    
    # replace only in `wp_options` table
-   tables: wp_options
    dev: dev.example.org
    prod: prod.example.org
```

This example config file define two environments. For the *dev* one, only *user*, *pass*
and *base* are needed. For the *prod* one, we use an ssh proxy (`jerome@example.org`)
and a specific PHP version (`/usr/local/bin/php.ORIG.5_4`).

## Tables filtering

Tables to migrate can be defined with the regex based directive `tables`. The
example config migrates only tables which names begin with `wp_`. It’s possible
to target tables like this :

```yaml
tables: ^(wp_post|wp_options|my_table)$
```

The value of this parameter is given to the PHP function `preg_match`.

## SSH tunneling

This tool can works with remote database through SSH. Simply define `ssh` entry
in the chosen environment. If your SSH key is set, no password will be prompted.

The value of the `ssh` directive is the connection string you would use to
connect your ssh server. In the case of the example config, you could connect
with `ssh jerome@example.org`.

## PHP executable

The PHP executable is used to execute replacements on the target database.
It can be configured on a per env basis, using the `php` key
in env configuration and giving the PHP executable path as value.

## Search / replace

*dbsync* use [interconnectit/Search-Replace-DB](https://github.com/interconnectit/Search-Replace-DB)
for the replacements work.

Search-Replace-DB, *srdb*, is sent on the target env for local execution,
and dropped after replacements are done.

Some Search-Replace-DB command line options are usable: `tables`, `include-cols`,
`exclude-cols` and `regex`. They must be defined for each replacement (see `dbsync.yml`)
using long option name.

## Files

It is possible to use a file name in place of the environment name, either as source
or as target. The replacements are done when the import is from a file to an environment
(replacements are always done on target database).

To dump a database to a file: `dbsync --source prod --target prod-dump.sql`

To restore from a file: `dbsync --source prod-dump.sql --target prod`

If the file ends with `.gz` it will be gzipped. It is possible to use a gzipped file as source file.

## Charset fix

You can use the `--fix` option to try a charset repair of your database.
It may solve issues with double utf8 encoded characters
using [this technique](http://blog.hno3.org/2010/04/22/fixing-double-encoded-utf-8-data-in-mysql/).

## Backups

To backup a database is easy and quick with this tool, so do not hesitate to do so
before any risky operation.

```
dbsync --source prod --target prod_20170512_1235.sql.gz
```

## Troubleshooting

If replacements failed, it may be due to a bad PHP version on the target env.
The SRDB script require PHP >= 5.3.0. In that case, you can use the `php` directive to
force use of another PHP binary on the target env (if such binary exists).

## Todo

Allow to use STDIN and STDOUT as source and target.

Improve this doc…

## Author

Jérôme Mulsant [https://rue-de-la-vieille.fr](https://rue-de-la-vieille.fr)
