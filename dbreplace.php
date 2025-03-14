<?php

// php 5.3 date timezone requirement, shouldn't affect anything
date_default_timezone_set('Europe/London');

$opts = [
    'h:' => 'host:',
    'n:' => 'name:',
    'u:' => 'user:',
    'p:' => 'pass:',
    'c:' => 'char:',
    's:' => 'search:',
    'r:' => 'replace:',
    't:' => 'tables:',
    'i:' => 'include-cols:',
    'x:' => 'exclude-cols:',
    'g' => 'regex',
    'l:' => 'page-size:',
    'z' => 'dry-run',
    'e:' => 'alter-engine:',
    'a:' => 'alter-collation:',
    'v::' => 'verbose::',
    'port:',
    'help',
];

$required = [
    'h:',
    'n:',
    'u:',
    'p:',
];

function strip_colons($string)
{
    return str_replace(':', '', $string);
}

// store arg values
//$arg_count = $_SERVER['argc'];
//$args_array = $_SERVER['argv'];

$short_opts = array_keys($opts);
$short_opts_normal = array_map('strip_colons', $short_opts);

$long_opts = array_values($opts);
$long_opts_normal = array_map('strip_colons', $long_opts);

// store array of options and values
$options = getopt(implode('', $short_opts), $long_opts);

if (isset($options['help'])) {
    echo "
#####################################################################

interconnect/it Safe Search & Replace tool

#####################################################################

This script allows you to search and replace strings in your database
safely without breaking serialised PHP.

Please report any bugs or fork and contribute to this script via
Github: https://github.com/interconnectit/search-replace-db

Argument values are strings unless otherwise specified.

ARGS
  -h, --host
    Required. The hostname of the database server.
  -n, --name
    Required. Database name.
  -u, --user
    Required. Database user.
  -p, --pass
    Required. Database user's password.
  --port
    Optional. Port on database server to connect to.
    The default is 3306. (MySQL default port).
  -s, --search
    String to search for or `preg_replace()` style
    regular expression.
  -r, --replace
    None empty string to replace search with or
    `preg_replace()` style replacement.
  -t, --tables
    If set only runs the script on the specified table, comma
    separate for multiple values.
  -i, --include-cols
    If set only runs the script on the specified columns, comma
    separate for multiple values.
  -x, --exclude-cols
    If set excludes the specified columns, comma separate for
    multiple values.
  -g, --regex [no value]
    Treats value for -s or --search as a regular expression and
    -r or --replace as a regular expression replacement.
  -l, --page-size
    How rows to fetch at a time from a table.
  -z, --dry-run [no value]
    Prevents any updates happening so you can preview the number
    of changes to be made
  -e, --alter-engine
    Changes the database table to the specified database engine
    eg. InnoDB or MyISAM. If specified search/replace arguments
    are ignored. They will not be run simultaneously.
  -a, --alter-collation
    Changes the database table to the specified collation
    eg. utf8_unicode_ci. If specified search/replace arguments
    are ignored. They will not be run simultaneously.
  -v, --verbose [true|false]
    Defaults to true, can be set to false to run script silently.
  --help
    Displays this help message ;)
";
    exit;
}

// missing field flag, show all missing instead of 1 at a time
$missing_arg = false;

// check required args are passed
foreach ($required as $key) {
    $short_opt = strip_colons($key);
    $long_opt = strip_colons($opts[$key]);
    if (!isset($options[$short_opt]) && !isset($options[$long_opt])) {
        fwrite(STDERR, "Error: Missing argument, -{$short_opt} or --{$long_opt} is required.\n");
        $missing_arg = true;
    }
}

// bail if requirements not met
if ($missing_arg) {
    fwrite(STDERR, "Please enter the missing arguments.\n");
    exit(1);
}

// new args array
$args = [
    'verbose' => true,
    'dry_run' => false,
];

// create $args array
foreach ($options as $key => $value) {
    // transpose keys
    if (($is_short = array_search($key, $short_opts_normal)) !== false) {
        $key = $long_opts_normal[$is_short];
    }

    // true/false string mapping
    if (is_string($value) && in_array($value, ['false', 'no', '0'])) {
        $value = false;
    }
    if (is_string($value) && in_array($value, ['true', 'yes', '1'])) {
        $value = true;
    }

    // boolean options as is, eg. a no value arg should be set true
    if (in_array($key, $long_opts)) {
        $value = true;
    }

    // change to underscores
    $key = str_replace('-', '_', $key);

    $args[$key] = $value;
}

$report = new icit_srdb_cli($args);

// Only print a separating newline if verbose mode is on to separate verbose output from result
if ($args['verbose']) {
    echo "\n";
}

if ($report && ((isset($args['dry_run']) && $args['dry_run']) || empty($report->errors['results']))) {
    echo "And we're done!\n";
} else {
    echo "Check the output for errors. You may need to ensure verbose output is on by using -v or --verbose.\n";
}

class icit_srdb
{

    /**
     * @var array List of all the tables in the database
     */
    public $all_tables = [];

    /**
     * @var array Tables to run the replacement on
     */
    public $tables = [];

    /**
     * @var string Search term
     */
    public $search = false;

    /**
     * @var string Replacement
     */
    public $replace = false;

    /**
     * @var bool Use regular expressions to perform search and replace
     */
    public $regex = false;

    /**
     * @var bool Leave guid column alone
     */
    public $guid = false;


    /**
     * @var array Available engines
     */
    public $engines = [];

    /**
     * @var bool|string Convert to new engine
     */
    public $alter_engine = false;

    /**
     * @var bool|string Convert to new collation
     */
    public $alter_collation = false;

    /**
     * @var array Column names to exclude
     */
    public $exclude_cols = [];

    /**
     * @var array Column names to include
     */
    public $include_cols = [];

    /**
     * @var bool True if doing a dry run
     */
    public $dry_run = true;

    /**
     * @var string Database connection details
     */
    public $name = '';
    public $user = '';
    public $pass = '';
    public $host = '127.0.0.1';
    public $port = 0;
    public $charset = 'utf8';
    public $collate = '';


    /**
     * @var array Stores a list of exceptions
     */
    public $errors = [
        'search' => [],
        'db' => [],
        'tables' => [],
        'results' => [],
    ];

    public $error_type = 'search';


    /**
     * @var array Stores the report array
     */
    public $report = [];


    /**
     * @var int Number of modifications to return in report array
     */
    public $report_change_num = 30;


    /**
     * @var bool Whether to echo report as script runs
     */
    public $verbose = false;


    /**
     * @var PDO|mysqli Database connection
     */
    public $db;


    /**
     * @var $use_pdo
     */
    public $use_pdo = true;


    /**
     * @var int How many rows to select at a time when replacing
     */
    public $page_size = 50000;


    /**
     * Searches for WP or Drupal context
     * Checks for $_POST data
     * Initialises database connection
     * Handles ajax
     * Runs replacement
     *
     * @param string $name database name
     * @param string $user database username
     * @param string $pass database password
     * @param string $host database hostname
     * @param string $port database connection port
     * @param string $search search string / regex
     * @param string $replace replacement string
     * @param array $tables tables to run replcements against
     * @param bool $live live run
     * @param array $exclude_cols tables to run replcements against
     */
    public function __construct($args)
    {
        $args = array_merge([
                                'name' => '',
                                'user' => '',
                                'pass' => '',
                                'host' => '',
                                'port' => 3306,
                                'search' => '',
                                'replace' => '',
                                'tables' => [],
                                'exclude_cols' => [],
                                'include_cols' => [],
                                'dry_run' => true,
                                'regex' => false,
                                'page_size' => 50000,
                                'alter_engine' => false,
                                'alter_collation' => false,
                                'verbose' => false,
                            ], $args);

        // handle exceptions
        set_exception_handler([$this, 'exceptions']);

        // handle errors
        set_error_handler([$this, 'errors'], E_ERROR | E_WARNING);

        // allow a string for columns
        foreach (['exclude_cols', 'include_cols', 'tables'] as $maybe_string_arg) {
            if (is_string($args[$maybe_string_arg])) {
                $args[$maybe_string_arg] = array_filter(array_map('trim', explode(',', $args[$maybe_string_arg])));
            }
        }

        // verify that the port number is logical		
        // work around PHPs inability to stringify a zero without making it an empty string
        // AND without casting away trailing characters if they are present.
        $port_as_string = (string)$args['port'] ? (string)$args['port'] : "0";
        if ((string)abs((int)$args['port']) !== $port_as_string) {
            $port_error = 'Port number must be a positive integer if specified.';
            $this->add_error($port_error, 'db');
            if (defined('STDIN')) {
                echo 'Error: ' . $port_error;
            }
            return '';
        }

        // set class vars
        foreach ($args as $name => $value) {
            if (is_string($value)) {
                $value = stripcslashes($value);
            }
            if (is_array($value)) {
                $value = array_map('stripcslashes', $value);
            }
            $this->set($name, $value);
        }

        // only for non cli call, cli set no timeout, no memory limit
        if (!defined('STDIN')) {
            // increase time out limit
            @set_time_limit(60 * 10);

            // try to push the allowed memory up, while we're at it
            @ini_set('memory_limit', '1024M');
        }

        // set up db connection
        $this->db_setup();

        if ($this->db_valid()) {
            // update engines
            if ($this->alter_engine) {
                $report = $this->update_engine($this->alter_engine, $this->tables);
            } // update collation
            elseif ($this->alter_collation) {
                $report = $this->update_collation($this->alter_collation, $this->tables);
            } // default search/replace action
            else {
                $report = $this->replacer($this->search, $this->replace, $this->tables);
            }
        } else {
            $report = $this->report;
        }

        // store report
        $this->set('report', $report);
        return $report;
    }


    /**
     * Terminates db connection
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->db_valid()) {
            $this->db_close();
        }
    }


    public function get($property)
    {
        return $this->$property;
    }

    public function set($property, $value)
    {
        $this->$property = $value;
    }

    /**
     * @param $exception Exception
     */
    public function exceptions($exception)
    {
        echo $exception->getMessage() . "\n";
    }

    public function errors(
        /** @noinspection PhpUnusedParameterInspection */
        $no,
        $message,
        $file,
        $line
    ) {
        echo $message . "\n";
    }


    public function log($type = '')
    {
        $args = array_slice(func_get_args(), 1);
        if ($this->get('verbose')) {
            echo "{$type}: ";
            print_r($args);
            echo "\n";
        }
        return $args;
    }


    public function add_error($error, $type = null)
    {
        if ($type !== null) {
            $this->error_type = $type;
        }
        $this->errors[$this->error_type][] = $error;
        $this->log('error', $this->error_type, $error);
    }


    public function use_pdo()
    {
        return $this->get('use_pdo');
    }


    /**
     * Setup connection, populate tables array
     * Also responsible for selecting the type of connection to use.
     *
     * @return boolean
     */
    public function db_setup()
    {
        $mysqli_available = class_exists('mysqli');
        $pdo_available = class_exists('PDO');

        $connection_type = '';

        // Default to mysqli type.
        // Only advance to PDO if all conditions are met.
        if ($mysqli_available) {
            $connection_type = 'mysqli';
        }

        if ($pdo_available) {
            // PDO is the interface, but it may not have the 'mysql' module.
            $mysql_driver_present = in_array('mysql', pdo_drivers());

            if ($mysql_driver_present) {
                $connection_type = 'pdo';
            }
        }

        // Abort if mysqli and PDO are both broken.
        if ('' === $connection_type) {
            $this->add_error('Could not find any MySQL database drivers. (MySQLi or PDO required.)', 'db');
            return false;
        }

        // connect
        $this->set('db', $this->connect($connection_type));
        return true;
    }


    /**
     * Database connection type router
     *
     * @param string $type
     *
     * @return callback
     */
    public function connect($type = '')
    {
        $method = "connect_{$type}";
        return $this->$method();
    }


    /**
     * Creates the database connection using newer mysqli functions
     *
     * @return resource|bool
     */
    public function connect_mysqli()
    {
        // switch off PDO
        $this->set('use_pdo', false);

        $connection = @mysqli_connect($this->host, $this->user, $this->pass, $this->name, $this->port);

        // unset if not available
        if (!$connection) {
            $this->add_error(mysqli_connect_error(), 'db');
            $connection = false;
        }

        return $connection;
    }


    /**
     * Sets up database connection using PDO
     *
     * @return PDO|bool
     */
    public function connect_pdo()
    {
        try {
            $connection = new PDO("mysql:host={$this->host};port={$this->port};dbname={$this->name}", $this->user,
                                  $this->pass);
        } catch (PDOException $e) {
            $this->add_error($e->getMessage(), 'db');
            $connection = false;
        }

        // check if there's a problem with our database at this stage
        if ($connection && !$connection->query('SHOW TABLES')) {
            $error_info = $connection->errorInfo();
            if (!empty($error_info) && is_array($error_info)) {
                $this->add_error(array_pop($error_info), 'db');
            } // Array pop will only accept a $var..
            $connection = false;
        }

        return $connection;
    }


    /**
     * Retrieve all tables from the database
     *
     * @return array
     */
    public function get_tables()
    {
        // get tables

        // A clone of show table status but with character set for the table.
        $show_table_status = "SELECT
		  t.`TABLE_NAME` as Name,
		  t.`ENGINE` as `Engine`,
		  t.`version` as `Version`,
		  t.`ROW_FORMAT` AS `Row_format`,
		  t.`TABLE_ROWS` AS `Rows`,
		  t.`AVG_ROW_LENGTH` AS `Avg_row_length`,
		  t.`DATA_LENGTH` AS `Data_length`,
		  t.`MAX_DATA_LENGTH` AS `Max_data_length`,
		  t.`INDEX_LENGTH` AS `Index_length`,
		  t.`DATA_FREE` AS `Data_free`,
		  t.`AUTO_INCREMENT` as `Auto_increment`,
		  t.`CREATE_TIME` AS `Create_time`,
		  t.`UPDATE_TIME` AS `Update_time`,
		  t.`CHECK_TIME` AS `Check_time`,
		  t.`TABLE_COLLATION` as Collation,
		  c.`CHARACTER_SET_NAME` as Character_set,
		  t.`Checksum`,
		  t.`Create_options`,
		  t.`table_Comment` as `Comment`
		FROM information_schema.`TABLES` t
			LEFT JOIN information_schema.`COLLATION_CHARACTER_SET_APPLICABILITY` c
				ON ( t.`TABLE_COLLATION` = c.`COLLATION_NAME` )
		  WHERE t.`TABLE_SCHEMA` = '{$this->name}';
		";

        $all_tables_mysql = $this->db_query($show_table_status);
        $all_tables = [];

        if (!$all_tables_mysql) {
            $this->add_error($this->db_error(), 'db');
        } else {
            // set the character set
            //$this->db_set_charset( $this->get( 'charset' ) );

            while ($table = $this->db_fetch($all_tables_mysql)) {
                // ignore views
                if ($table['Comment'] == 'VIEW') {
                    continue;
                }

                $all_tables[$table[0]] = $table;
            }
        }

        return $all_tables;
    }


    /**
     * Get the character set for the current table
     *
     * @param string $table_name The name of the table we want to get the char
     * set for
     *
     * @return string    The character encoding;
     */
    public function get_table_character_set($table_name = '')
    {
        $table_name = $this->db_escape($table_name);
        $schema = $this->db_escape($this->name);

        $charset = $this->db_query("SELECT c.`character_set_name`
			FROM information_schema.`TABLES` t
				LEFT JOIN information_schema.`COLLATION_CHARACTER_SET_APPLICABILITY` c
				ON (t.`TABLE_COLLATION` = c.`COLLATION_NAME`)
			WHERE t.table_schema = {$schema}
				AND t.table_name = {$table_name}
			LIMIT 1;");

        $encoding = false;
        if (!$charset) {
            $this->add_error($this->db_error(), 'db');
        } else {
            $result = $this->db_fetch($charset);
            $encoding = isset($result['character_set_name']) ? $result['character_set_name'] : false;
        }

        return $encoding;
    }


    /**
     * Retrieve all supported database engines
     *
     * @return array
     */
    public function get_engines()
    {
        // get available engines
        $mysql_engines = $this->db_query('SHOW ENGINES;');
        $engines = [];

        if (!$mysql_engines) {
            $this->add_error($this->db_error(), 'db');
        } else {
            while ($engine = $this->db_fetch($mysql_engines)) {
                if (in_array($engine['Support'], ['YES', 'DEFAULT'])) {
                    $engines[] = $engine['Engine'];
                }
            }
        }

        return $engines;
    }


    public function db_query($query)
    {
        if ($this->use_pdo()) {
            return $this->db->query($query);
        } else {
            return mysqli_query($this->db, $query);
        }
    }

    public function db_update($query)
    {
        if ($this->use_pdo()) {
            return $this->db->exec($query);
        } else {
            return mysqli_query($this->db, $query);
        }
    }

    public function db_error()
    {
        if ($this->use_pdo()) {
            $error_info = $this->db->errorInfo();
            return !empty($error_info) && is_array($error_info) ? array_pop($error_info) : 'Unknown error';
        } else {
            return mysqli_error($this->db);
        }
    }

    public function db_fetch($data)
    {
//        if ($this->use_pdo())
        if ($data instanceof PDOStatement) {
            return $data->fetch();
        } else {
            return mysqli_fetch_array($data);
        }
    }

    public function db_escape($string)
    {
//        if ($this->use_pdo())
        if ($this->db instanceof PDO) {
            return $this->db->quote($string);
        } else {
            return "'" . mysqli_real_escape_string($this->db, $string) . "'";
        }
    }

    public function db_free_result($data)
    {
//        if ($this->use_pdo())
        if ($data instanceof PDOStatement) {
            $data->closeCursor();
        } else {
            mysqli_free_result($data);
        }
    }

    public function db_set_charset($charset = '')
    {
        if (!empty($charset)) {
            if (!$this->use_pdo() && function_exists('mysqli_set_charset')) {
                mysqli_set_charset($this->db, $charset);
            } else {
                $this->db_query('SET NAMES ' . $charset);
            }
        }
    }

    public function db_close()
    {
        if ($this->use_pdo()) {
            unset($this->db);
        } else {
            mysqli_close($this->db);
        }
    }

    public function db_valid()
    {
        return (bool)$this->db;
    }


    /**
     * Walk an array replacing one element for another. ( NOT USED ANY MORE )
     *
     * @param string $find The string we want to replace.
     * @param string $replace What we'll be replacing it with.
     * @param array $data Used to pass any subordinate arrays back to the
     * function for searching.
     *
     * @return array    The original array with the replacements made.
     */
//    public function recursive_array_replace($find, $replace, $data)
//    {
//        if (is_array($data)) {
//            foreach ($data as $key => $value) {
//                if (is_array($value)) {
//                    $this->recursive_array_replace($find, $replace, $data[$key]);
//                } else {
//                    // have to check if it's string to ensure no switching to string for booleans/numbers/nulls - don't need any nasty conversions
//                    if (is_string($value))
//                        $data[$key] = $this->str_replace($find, $replace, $value);
//                }
//            }
//        } else {
//            if (is_string($data))
//                $data = $this->str_replace($find, $replace, $data);
//        }
//    }


    /**
     * Take a serialised array and unserialise it replacing elements as needed and
     * unserialising any subordinate arrays and performing the replace on those too.
     *
     * @param string $from String we're looking to replace.
     * @param string $to What we want it to be replaced with
     * @param array $data Used to pass any subordinate arrays back to in.
     * @param bool $serialised Does the array passed via $data need serialising.
     *
     * @return array    The original array with all elements replaced as needed.
     */
//    public function recursive_unserialize_replace($from = '', $to = '', $data = '', $serialised = false, $done = [])
//    {
//
//        // some unserialised data cannot be re-serialised eg. SimpleXMLElements
//        try {
//
//            if (is_string($data) && ($unserialized = @unserialize($data)) !== false) {
//                $data = $this->recursive_unserialize_replace($from, $to, $unserialized, true, $done);
//            } elseif (is_array($data)) {
//                $_tmp = array();
//                foreach ($data as $key => $value) {
//                    $_tmp[$key] = $this->recursive_unserialize_replace($from, $to, $value, false, $done);
//                }
//
//                $data = $_tmp;
//                unset($_tmp);
//            } // Submitted by Tina Matter
//            elseif (is_object($data)) {
//                if (!in_array(spl_object_hash($data), $done)) {
//                    $done[] = spl_object_hash($data);
//                    $props = get_object_vars($data);
//                    foreach ($props as $key => &$value) {
//                        $alt = preg_replace('/^[^\p{L}\p{N}]+/', '', $key);
//                        if ($alt == $key) {
//                            $this->recursive_unserialize_replace($from, $to, $value, false, $done);
//                        }
//                    }
//                }
//            } else {
//                if (is_string($data)) {
//                    $data = $this->str_replace($from, $to, $data);
//
//                }
//            }
//
//            if ($serialised)
//                return serialize($data);
//
//        } catch (Exception $error) {
//
//            $this->add_error($error->getMessage(), 'results');
//
//        }
//
//        return $data;
//    }


    /**
     * Regular expression callback to fix serialised string lengths
     *
     * @param array $matches matches from the regular expression
     *
     * @return string
     */
    public function preg_fix_serialised_count($matches)
    {
        $length = mb_strlen($matches[2]);
        if ($length !== intval($matches[1])) {
            return "s:{$length}:\"{$matches[2]}\";";
        }
        return $matches[0];
    }


    /**
     * The main loop triggered in step 5. Up here to keep it out of the way of the
     * HTML. This walks every table in the db that was selected in step 3 and then
     * walks every row and column replacing all occurences of a string with another.
     * We split large tables into 50,000 row blocks when dealing with them to save
     * on memmory consumption.
     *
     * @param string $search What we want to replace
     * @param string $replace What we want to replace it with.
     * @param array $tables The tables we want to look at.
     *
     * @return array|boolean    Collection of information gathered during the run or false
     */
    public function replacer($search = '', $replace = '', $tables = [])
    {
        // check we have a search string, bail if not
        if (empty($search)) {
            $this->add_error('Search string is empty', 'search');
            return false;
        }

        $report = [
            'tables' => 0,
            'rows' => 0,
            'change' => 0,
            'updates' => 0,
            'start' => microtime(),
            'end' => microtime(),
            'errors' => [],
            'table_reports' => [],
        ];

        $table_report = [
            'rows' => 0,
            'change' => 0,
            'changes' => [],
            'updates' => 0,
            'start' => microtime(),
            'end' => microtime(),
            'errors' => [],
        ];

        $dry_run = $this->get('dry_run');

        if ($this->get('dry_run'))    // Report this as a search-only run.
        {
            $this->add_error('The dry-run option was selected. No replacements will be made.', 'results');
        }

        // if no tables selected assume all
        if (empty($tables)) {
            $all_tables = $this->get_tables();
            $tables = array_keys($all_tables);
        }

        if (is_array($tables) && !empty($tables)) {
            $parser = new Parser();

            foreach ($tables as $table) {
                $encoding = $this->get_table_character_set($table);
                switch ($encoding) {
                    // Tables encoded with this work for me only when I set names to utf8. I don't trust this in the wild so I'm going to avoid.
                    case 'utf16':
                    case 'utf32':
                        //$encoding = 'utf8';
                        $this->add_error("The table \"{$table}\" is encoded using \"{$encoding}\" which is currently unsupported.",
                                         'results');
                        continue 2;
                    default:
                        $this->db_set_charset($encoding);
                        break;
                }


                $report['tables']++;

                // get primary key and columns
                list($primary_key, $columns) = $this->get_columns($table);

                if ($primary_key === null) {
                    $this->add_error("The table \"{$table}\" has no primary key. Changes will have to be made manually.",
                                     'results');
                    continue;
                }

                // create new table report instance
                $new_table_report = $table_report;
                $new_table_report['start'] = microtime();

                $this->log('search_replace_table_start', $table, $search, $replace);

                // Count the number of rows we have in the table if large we'll split into blocks, This is a mod from Simon Wheatley
                $row_count = $this->db_query("SELECT COUNT(*) FROM `{$table}`");
                $rows_result = $this->db_fetch($row_count);
                $row_count = $rows_result[0];

                $page_size = $this->get('page_size');
                $pages = ceil($row_count / $page_size);

                for ($page = 0; $page < $pages; $page++) {
                    $start = $page * $page_size;

                    // Grab the content of the table
                    $data = $this->db_query(sprintf('SELECT * FROM `%s` LIMIT %d, %d', $table, $start, $page_size));

                    if (!$data) {
                        $this->add_error($this->db_error(), 'results');
                    }

                    while ($row = $this->db_fetch($data)) {
                        $report['rows']++; // Increment the row counter
                        $new_table_report['rows']++;

                        $update_sql = [];
                        $where_sql = [];
                        $update = false;

                        foreach ($columns as $column) {
                            $edited_data = $data_to_fix = $row[$column];

                            if ($primary_key == $column) {
                                $where_sql[] = "`{$column}` = " . $this->db_escape($data_to_fix);
                                continue;
                            }

                            // exclude cols
                            if (in_array($column, $this->exclude_cols)) {
                                continue;
                            }

                            // include cols
                            if (!empty($this->include_cols) && !in_array($column, $this->include_cols)) {
                                continue;
                            }

                            // Run a search replace on the data that'll respect the serialisation.
//                            $edited_data = $this->recursive_unserialize_replace($search, $replace, $data_to_fix);
                            try {
                                if (is_string($data_to_fix)) {
                                    $unserialized = $parser->parse($data_to_fix);
                                    $unserialized->replace(function ($data) use ($search, $replace) {
                                        return $this->str_replace($search, $replace, $data);
                                    });
                                    $edited_data = $unserialized->toString();
                                }
                            } catch (Exception $e) {
                                if (is_string($data_to_fix)) {
                                    $edited_data = $this->str_replace($search, $replace, $data_to_fix);
                                }
                            }

                            // Something was changed
                            if ($edited_data != $data_to_fix) {
                                $report['change']++;
                                $new_table_report['change']++;

                                // log first x changes
                                if ($new_table_report['change'] <= $this->get('report_change_num')) {
                                    $new_table_report['changes'][] = [
                                        'row' => $new_table_report['rows'],
                                        'column' => $column,
//                                        'from'   => utf8_encode($data_to_fix),
//                                        'to'     => utf8_encode($edited_data),
                                        'from' => $data_to_fix,
                                        'to' => $edited_data,
                                    ];
                                }

                                $update_sql[] = "`{$column}` = " . $this->db_escape($edited_data);
                                $update = true;
                            }
                        }

                        if ($dry_run) {
                            // nothing for this state
                        } elseif ($update && !empty($where_sql)) {
                            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ',
                                                                          $update_sql) . ' WHERE ' . implode(' AND ',
                                                                                                             array_filter($where_sql));
                            try {
                                $result = $this->db_update($sql);

                                if (!is_int($result) && !$result) {
                                    $this->add_error($this->db_error(), 'results');
                                } else {
                                    $report['updates']++;
                                    $new_table_report['updates']++;
                                }
                            } catch (Exception $e) {
                                $this->add_error(sprintf('row %d: %s', $row[key($row)], $e->getMessage()), 'error');
                            }
                        }
                    }

                    $this->db_free_result($data);
                }

                $new_table_report['end'] = microtime();

                // store table report in main
                $report['table_reports'][$table] = $new_table_report;

                // log result
                $this->log('search_replace_table_end', $table, $new_table_report);
            }
        }

        $report['end'] = microtime();

        $this->log('search_replace_end', $search, $replace, $report);

        return $report;
    }


    public function get_columns($table)
    {
        $primary_key = null;
        $columns = [];

        // Get a list of columns in this table
        $fields = $this->db_query("DESCRIBE {$table}");
        if (!$fields) {
            $this->add_error($this->db_error(), 'db');
        } else {
            while ($column = $this->db_fetch($fields)) {
                $columns[] = $column['Field'];
                if ($column['Key'] == 'PRI') {
                    $primary_key = $column['Field'];
                }
            }
        }

        return [$primary_key, $columns];
    }


    public function do_column()
    {
    }


    /**
     * Convert table engines
     *
     * @param string $engine Engine type
     * @param array $tables
     *
     * @return array    Modification report
     */
    public function update_engine($engine = 'MyISAM', $tables = [])
    {
        $report = false;

        if (empty($this->engines)) {
            $this->set('engines', $this->get_engines());
        }

        if (in_array($engine, $this->get('engines'))) {
            $report = ['engine' => $engine, 'converted' => []];

            if (empty($tables)) {
                $all_tables = $this->get_tables();
                $tables = array_keys($all_tables);
            }

            foreach ($tables as $table) {
                $table_info = $all_tables[$table];

                // are we updating the engine?
                if ($table_info['Engine'] != $engine) {
                    $engine_converted = $this->db_query("alter table {$table} engine = {$engine};");
                    if (!$engine_converted) {
                        $this->add_error($this->db_error(), 'results');
                    } else {
                        $report['converted'][$table] = true;
                    }
                    continue;
                } else {
                    $report['converted'][$table] = false;
                }

                if (isset($report['converted'][$table])) {
                    $this->log('update_engine', $table, $report, $engine);
                }
            }
        } else {
            $this->add_error('Cannot convert tables to unsupported table engine &rdquo;' . $engine . '&ldquo;',
                             'results');
        }

        return $report;
    }


    /**
     * Updates the characterset and collation on the specified tables
     *
     * @param string $collate table collation
     * @param array $tables tables to modify
     *
     * @return array    Modification report
     */
    public function update_collation($collation = 'utf8_unicode_ci', $tables = [])
    {
        $report = false;

        if (is_string($collation)) {
            $report = ['collation' => $collation, 'converted' => []];

            if (empty($tables)) {
                $all_tables = $this->get_tables();
                $tables = array_keys($all_tables);
            }

            // charset is same as collation up to first underscore
            $charset = preg_replace('/^([^_]+).*$/', '$1', $collation);

            foreach ($tables as $table) {
                $table_info = $all_tables[$table];

                // are we updating the engine?
                if ($table_info['Collation'] != $collation) {
                    $engine_converted = $this->db_query("alter table {$table} convert to character set {$charset} collate {$collation};");
                    if (!$engine_converted) {
                        $this->add_error($this->db_error(), 'results');
                    } else {
                        $report['converted'][$table] = true;
                    }
                    continue;
                } else {
                    $report['converted'][$table] = false;
                }

                if (isset($report['converted'][$table])) {
                    $this->log('update_collation', $table, $report, $collation);
                }
            }
        } else {
            $this->add_error('Collation must be a valid string', 'results');
        }

        return $report;
    }


    /**
     * Replace all occurrences of the search string with the replacement string.
     *
     * @param mixed $search
     * @param mixed $replace
     * @param mixed $subject
     * @param int $count
     * @return mixed
     * @copyright Copyright 2012 Sean Murphy. All rights reserved.
     * @license http://creativecommons.org/publicdomain/zero/1.0/
     * @link http://php.net/manual/function.str-replace.php
     *
     * @author Sean Murphy <sean@iamseanmurphy.com>
     */
    public static function mb_str_replace($search, $replace, $subject, &$count = 0)
    {
        if (!is_array($subject)) {
            // Normalize $search and $replace so they are both arrays of the same length
            $searches = is_array($search) ? array_values($search) : [$search];
            $replacements = is_array($replace) ? array_values($replace) : [$replace];
            $replacements = array_pad($replacements, count($searches), '');

            foreach ($searches as $key => $search) {
                $parts = mb_split(preg_quote($search), $subject) ?: [];
                $count += count($parts) - 1;
                $subject = implode($replacements[$key], $parts);
            }
        } else {
            // Call mb_str_replace for each subject in array, recursively
            foreach ($subject as $key => $value) {
                $subject[$key] = self::mb_str_replace($search, $replace, $value, $count);
            }
        }

        return $subject;
    }


    /**
     * Wrapper for regex/non regex search & replace
     *
     * @param string $search
     * @param string $replace
     * @param string $string
     * @param int $count
     *
     * @return string
     */
    public function str_replace($search, $replace, $string, &$count = 0)
    {
        if ($this->get('regex')) {
            return preg_replace($search, $replace, $string, -1, $count);
        } elseif (function_exists('mb_split')) {
            return self::mb_str_replace($search, $replace, $string, $count);
        } else {
            return str_replace($search, $replace, $string, $count);
        }
    }

    /**
     * Convert a string containing unicode into HTML entities for front end display
     *
     * @param string $string
     *
     * @return string
     */
    public function charset_decode_utf_8($string)
    {
        /* Only do the slow convert if there are 8-bit characters */
        /* avoid using 0xA0 (\240) in ereg ranges. RH73 does not like that */
        if (!preg_match("/[\200-\237]/", $string) and !preg_match("/[\241-\377]/", $string)) {
            return $string;
        }

        // decode three byte unicode characters
        $string = preg_replace("/([\340-\357])([\200-\277])([\200-\277])/e",
                               "'&#'.((ord('\\1')-224)*4096 + (ord('\\2')-128)*64 + (ord('\\3')-128)).';'",
                               $string);

        // decode two byte unicode characters
        $string = preg_replace("/([\300-\337])([\200-\277])/e",
                               "'&#'.((ord('\\1')-192)*64+(ord('\\2')-128)).';'",
                               $string);

        return $string;
    }

}

// modify the log output
class icit_srdb_cli extends icit_srdb
{

    public function log($type = '')
    {
        $args = array_slice(func_get_args(), 1);

        $output = "";

        switch ($type) {
            case 'error':
                list($error_type, $error) = $args;
                $output .= "$error_type: $error";
                break;
            case 'search_replace_table_start':
                list($table, $search, $replace) = $args;
                $output .= "{$table}: replacing {$search} with {$replace}";
                break;
            case 'search_replace_table_end':
                list($table, $report) = $args;
                $time = number_format((int)$report['end'] - (int)$report['start'], 8);
                $output .= "{$table}: {$report['rows']} rows, {$report['change']} changes found, {$report['updates']} updates made in {$time} seconds";
                break;
            case 'search_replace_end':
                list($search, $replace, $report) = $args;
                $time = number_format((int)$report['end'] - (int)$report['start'], 8);
                $dry_run_string = $this->dry_run ? "would have been" : "were";
                $output .= "
Replacing {$search} with {$replace} on {$report['tables']} tables with {$report['rows']} rows
{$report['change']} changes {$dry_run_string} made
{$report['updates']} updates were actually made
It took {$time} seconds";
                break;
            case 'update_engine':
                list($table, $report, $engine) = $args;
                $output .= $table . ($report['converted'][$table] ? ' has been' : 'has not been') . ' converted to ' . $engine;
                break;
            case 'update_collation':
                list($table, $report, $collation) = $args;
                $output .= $table . ($report['converted'][$table] ? ' has been' : 'has not been') . ' converted to ' . $collation;
                break;
        }

        if ($this->verbose) {
            echo $output . "\n";
        }
    }

}


//
//
//
//
//$raw = file_get_contents('serialized.txt');
//$parser = new Parser();
//try {
//    $data = $parser->parse($raw);
//} catch (Exception $e) {
//    die('Error occurred: '. $e->getMessage());
//}
////echo $data;
////echo "\n\n\n--------------\n\n\n";
//$generated = $data->toString();
////echo $generated;
//
//if ($raw == $generated) {
//    echo "C’est bon\n";
//}
//
//$data->replace(function ($content) {
//    return preg_replace('/application\/json/', 'application/octet-stream', $content);
//});
//
//file_put_contents('generated.txt', $data->toString());

abstract class Node
{
    /** @return string */
    abstract public function toString();

    /** @return string|integer */
    abstract public function getContent();

    /**
     * Replace string in data using callback
     * @param $callback
     * @return integer Replacement count
     */
    abstract public function replace($callback);
}

abstract class NodeArrayContent extends Node
{
    /** @var Node[] */
    public $content;

    /**
     * @param $array Node[]
     */
    protected function arrayToString()
    {
        $output = '';
        foreach ($this->content as $key => $item) {
            if (is_string($key)) {
                $output .= sprintf('s:%d:"%s";', strlen($key), $key);
            } else {
                $output .= sprintf('i:%d;', intval($key));
            }
            $output .= $item->toString();
        }
        return $output;
    }

    /**
     * @param $array Node[]
     * @param $search string
     * @param $replacement string
     */
    protected function replaceInArray($callback)
    {
        $count = 0;

        // replace in keys
        $new = [];
        foreach ($this->content as $key => $node) {
            if (is_string($key)) {
                $newkey = $callback($key);
                if ($newkey != $key) {
                    ++$count;
                    $key = $newkey;
                }
            }
            $new[$key] = $node;
        }
        $this->content = $new;

        $count += array_reduce($this->content, function ($count, $item) use ($callback) {
            /** @var $item Node */
            return $count + $item->replace($callback);
        },                     0);

        return $count;
    }
}

class NodeObject extends NodeArrayContent
{
    const FORMAT = 'O:%d:"%s":%d:{%s}';

    /** @var string */
    public $type;

    public function toString()
    {
        $output = sprintf(
            self::FORMAT,
            strlen($this->type),
            $this->type,
            count($this->content),
            $this->arrayToString()
        );
        return $output;
    }

    /** @return string */
    public function getContent()
    {
        return '';
    }

    public function replace($callback)
    {
        return $this->replaceInArray($callback);
    }
}

class NodeArray extends NodeArrayContent
{
    const FORMAT = 'a:%d:{%s}';

    /** @return string */
    public function toString()
    {
        return sprintf(
            self::FORMAT,
            count($this->content),
            $this->arrayToString()
        );
    }

    public function getContent()
    {
        return '';
    }

    public function replace($callback)
    {
        return $this->replaceInArray($callback);
    }
}

class NodeString extends Node
{
    const FORMAT = 's:%d:"%s";';

    /** @var string */
    public $content;

    /** @return string */
    public function toString()
    {
        return sprintf(
            self::FORMAT,
            strlen($this->content),
            $this->content
        );
    }

    public function getContent()
    {
        return $this->content;
    }

    /**
     * Replace string in data using callback
     * @param $callback
     * @return integer Replacement count
     */
    public function replace($callback)
    {
        $replaced = $callback($this->content);
        if ($replaced != $this->content) {
            $this->content = $replaced;
            return 1;
        }
        return 0;
    }
}

class NodeBoolean extends Node
{
    const FORMAT = 'b:%d;';

    /** @var boolean */
    public $content;

    public function toString()
    {
        return sprintf(self::FORMAT, $this->content ? 1 : 0);
    }

    public function getContent()
    {
        return '';
    }

    /**
     * Replace string in data using callback
     * @param $callback
     * @return integer Replacement count
     */
    public function replace($callback)
    {
        return 0;
    }
}

class NodeInt extends Node
{
    const FORMAT = '%s:%d;';

    /** @var string */
    public $type = 'i';

    /** @var integer */
    public $content;

    /** @return string */
    public function toString()
    {
        return sprintf(self::FORMAT, $this->type, $this->content);
    }

    public function getContent()
    {
        return (string)$this->content;
    }

    /**
     * Replace string in data using callback
     * @param $callback
     * @return integer Replacement count
     */
    public function replace($callback)
    {
        $replaced = $callback($this->content);
        if ($replaced != $this->content) {
            $this->content = $replaced;
            return 1;
        }
        return 0;
    }
}

class NodeNull extends Node
{
    const FORMAT = 'N;';

    /** @return string */
    public function toString()
    {
        return self::FORMAT;
    }

    public function getContent()
    {
        return '';
    }

    /**
     * Replace string in data using callback
     * @param $callback
     * @return integer Replacement count
     */
    public function replace($callback)
    {
        return 0;
    }
}

class Parser
{
    protected $pos = 0;
    protected $max = 0;
    protected $string = [];

    // Private and protected object properties prefixes
    const PRIVATE_PREFIX = "\0*\0";

    /**
     * Read the next character from the supplied string.
     * Return null when we have run out of characters.
     */
    private function readOne()
    {
        if ($this->pos <= $this->max) {
            $value = $this->string[$this->pos];
            $this->pos += 1;
        } else {
            $value = null;
        }
        return $value;
    }

    /**
     * Read characters until we reach the given character $char.
     * By default, discard that final matching character and return
     * the rest.
     */
    private function readUntil($char, $discard_char = true)
    {
        $value = '';
        while (null !== ($one = $this->readOne())) {
            if ($one !== $char || !$discard_char) {
                $value .= $one;
            }
            if ($one === $char) {
                break;
            }
        }
        return $value;
    }

    /**
     * Read $count characters, or until we have reached the end,
     * whichever comes first.
     * By default, remove enclosing double-quotes from the result.
     */
    private function read($count, $strip_quotes = true)
    {
        $value = '';
        while ($count > 0 && null != ($one = $this->readOne())) {
            $value .= $one;
            $count -= 1;
        }
        return $strip_quotes ? $this->stripQuotes($value) : $value;
    }

    /**
     * Remove a single set of double-quotes from around a string.
     *  abc => abc
     *  "abc" => abc
     *  ""abc"" => "abc"
     *
     * @param string string
     * @returns string
     */
    private function stripQuotes($string)
    {
        // Only remove exactly one quote from the start and the end,
        // and then only if there is one at each end.
        if (strlen($string) < 2 || substr($string, 0, 1) !== '"' || substr($string, -1, 1) !== '"') {
            // Too short, or does not start or end with a quote.
            return $string;
        }
        // Return the middle of the string, from the second character to the second-but-last.
        return substr($string, 1, -1);
    }

    /**
     * Parse a string containing a serialized data structure.
     * This is the initial entry point into the recursive parser.
     * @return Node
     * @throws Exception
     */
    public function parse($string = null)
    {
        if ($string !== null) {
            $this->pos = 0;
            $this->string = str_split($string);
            $this->max = count($this->string) - 1;
            return $this->parse();
        }

        // May be : or ; as a terminator, depending on data type
        $prefix = $this->read(2);
        if (substr($prefix, 1, 1) != ':') {
            throw new \Exception(sprintf('Unable to unserialize'));
        }
        $type = substr($prefix, 0, 1);
        switch ($type) {
            case 'a':
                $val = new NodeArray();

                // Associative array: a:length:{[index][value]...}
                $count = (int)$this->readUntil(':');
                // Eat the opening "{" of the array.
                $this->read(1);
                $val->content = [];
                for ($i = 0; $i < $count; $i++) {
                    $array_key = $this->parse();
                    $array_value = $this->parse();
//                    $key = $array_key instanceof NodeString ? $array_key
                    $val->content[$array_key->getContent()] = $array_value;
                }
                // Eat "}" terminating the array.
                $this->read(1);
                break;
            case 'O':
                $val = new NodeObject();

                // Object: O:length:"class":length:{[property][value]...}
                $len = (int)$this->readUntil(':');
                // +2 for quotes
                $val->type = $this->read(2 + $len);

                // Eat the separator
                $this->read(1);
                // Do the properties.
                // Initialise with the original name of the class.
                // Read the number of properties.
                $len = (int)$this->readUntil(':');
                // Eat "{" holding the properties.
                $this->read(1);
                $val->content = [];
                for ($i = 0; $i < $len; $i++) {
                    $prop_key = $this->parse();
                    $prop_value = $this->parse();
                    $val->content[$prop_key->getContent()] = $prop_value;
                }
                // Eat "}" terminating properties.
                $this->read(1);
                break;
            case 's':
                $val = new NodeString();
                $len = (int)$this->readUntil(':');
                // Eat quote
                $this->read(1);
                $val->content = $this->read($len);
                // Eat the ending quote and the separator
                $this->read(2);
                break;
            case 'i':
            case 'r':
            case 'd':
                $val = new NodeInt();
                $val->type = $type;
                $val->content = (int)$this->readUntil(';');
                break;
            case 'b':
                $val = new NodeBoolean();
                // Boolean is 0 or 1
                $val->content = $this->read(1);
                $this->read(1);
                break;
            case 'N':
                $val = new NodeNull();
                break;
            default:
                throw new \Exception(sprintf('Unable to unserialize type "%s"', $type));
        }
        return $val;
    }
}