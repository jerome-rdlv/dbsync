# dbsync

A database synchronization command line tool with support for replacements and SSH tunneling. Its written in PHP but it executes bash commands.

I’m publishing this here because it might be useful to others, but USE OF THIS SCRIPT IS ENTIRELY AT YOUR OWN RISK. I accept no liability from its use..

*dbsync* uses a configuration file to  define environments (like dev, prod, preprod, etc.) and replacements (different values for different environments). Have a look at the `dbsync.json` example config.

Use `dbsync --help` to get help.

A typical usage is `dbsync --source dev --target preprod` to synchronize the `dev` database with the `preprod` database (moving from `dev` to `preprod` and replacing `dev` values with `preprod` values).

## ssh tunneling

This tool can works with remote database through SSH. Simply define `ssh` entry in the choosen environment. If your SSH key is set, no password will be prompted.

## search / replace

This tool use [interconnectit/Search-Replace-DB
](https://github.com/interconnectit/Search-Replace-DB) for the replacements work. It is stored in *base64* in the *dbsync* command file.

Search-Replace-DB, *srdb*, is updated through composer and stored in *dbsync* with a composer script: *update-srdb*.

Some Search-Replace-DB command line options are usable: `tables`, `include-cols`, `exclude-cols` and `regex`. They must be defined for each replacement (see `dbsync.json`) using long option name.

## files

It is possible to use a file name in place of the environment name, either as source or as target. The replacements are done when synchronizing from a file to an environment (replacements are always done on target database).

To dump a database to a file: `dbsync --source prod --target prod-dump.sql`

To restore from a file: `dbsync --source prod-dump.sql --target prod`

## charset fix

You can use the `--fix` option to try a charset repair of your database. It may solve issues with double utf8 encoded characters using [this technique](http://blog.hno3.org/2010/04/22/fixing-double-encoded-utf-8-data-in-mysql/).