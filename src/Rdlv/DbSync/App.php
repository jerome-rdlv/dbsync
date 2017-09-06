<?php

namespace Rdlv\DbSync;

use PHPSQLParser\PHPSQLParser;
use ReflectionClass;

class App
{
    const OPT_CONF = 'c';
    const OPT_SOURCE = 's';
    const OPT_TARGET = 't';
    const OPT_REPLACEMENTS_ONLY = 'r';
    const OPT_NO_REPLACEMENT = 'n';
    const OPT_FIX = 'f';
    const OPT_INCLUDE = 'i';
    const OPT_EXCLUDE = 'x';
    const OPT_HELP = 'h';
    const OPT_DEBUG = 'd';
    const OPT_VERBOSE = 'v';
    const OPT_FORCE = 'f';

    const LOPT_CONF = 'conf';
    const LOPT_SOURCE = 'source';
    const LOPT_TARGET = 'target';
    const LOPT_REPLACEMENTS_ONLY = 'replacements-only';
    const LOPT_NO_REPLACEMENT = 'no-replacement';
    const LOPT_FIX = 'fix';
    const LOPT_INCLUDE = 'include';
    const LOPT_EXCLUDE = 'exclude';
    const LOPT_HELP = 'help';
    const LOPT_DEBUG = 'debug';
    const LOPT_VERBOSE = 'verbose';
    const LOPT_FORCE = 'force';

    const MYSQL_DEFAULT_PORT = 0;

    public static $confDefaultFile = 'dbsync.json';

    public static function run()
    {
        return new self();
    }

    private $config = null;

    private $srdb = 'srdb_%s.php';

    private $defaultPhp = 'php';

    // used to mark source env in dump file
    private $marker = '-- sync-db env: ';

    private $opts = array(
        self::OPT_CONF . ':'        => self::LOPT_CONF . ':',
        self::OPT_SOURCE . ':'      => self::LOPT_SOURCE . ':',
        self::OPT_TARGET . ':'      => self::LOPT_TARGET . ':',
        self::OPT_REPLACEMENTS_ONLY => self::LOPT_REPLACEMENTS_ONLY,
        self::OPT_NO_REPLACEMENT    => self::LOPT_NO_REPLACEMENT,
        self::OPT_FIX               => self::LOPT_FIX,
        self::OPT_INCLUDE . ':'     => self::LOPT_INCLUDE . ':',
        self::OPT_EXCLUDE . ':'     => self::LOPT_EXCLUDE . ':',
        self::OPT_HELP              => self::LOPT_HELP,
        self::OPT_DEBUG             => self::LOPT_DEBUG,
        self::OPT_VERBOSE           => self::LOPT_VERBOSE,
        self::OPT_FORCE             => self::LOPT_FORCE,
    );

    private $srdbOpts = array(
        't:' => 'tables:',
        'i:' => 'include-cols:',
        'x:' => 'exclude-cols:',
        'g'  => 'regex',
    );

    private $options = null;

    private $colors = array(
        'no'     => '00',
        'grey'   => '37',
        'yellow' => '33',
        'red'    => '31',
        'green'  => '32',
        'blue'   => '34',
        'purple' => '35',
        'ocean'  => '36',
        'dark'   => '30',
    );

    private $cmds = array(
        'test'      => 'echo "use {base};" | mysql -u "{user}" -p"{pass}" -h "{host}" -P {port} "{base}"',
        'gzip_test' => 'gzip -V',
        'tables'    => 'echo "show tables;" | mysql --skip-column-names -u "{user}" -p"{pass}" -h "{host}" -P {port} "{base}"',
        'source'    => 'mysqldump {options} -u "{user}" -p"{pass}" --add-drop-table --no-create-db -h "{host}" -P {port} "{base}" {tables} {gzip}',
        'target'    => '{gzip} mysql {options} -u "{user}" -p"{pass}" -h "{host}" -P {port} "{base}"',
        'markfile'  => '{ echo "{marker}{env}"; cat; } {gzip} > "{file}"',
        'tofile'    => ' cat {gzip} > "{file}"',
        'fromfile'  => ' cat "{file}" {gzip}',
        'cp'        => 'echo "{data}" | {php} -r "echo base64_decode(stream_get_contents(STDIN));" > {srdb}',
        'ssh_cp'    => 'echo "{data}" | {php} -r "echo base64_decode(stream_get_contents(STDIN));" | ssh {ssh} \'cat > "{srdb}"\'',
        'chmod'     => 'chmod +x {srdb}',
        'rm'        => 'rm {srdb}',
        'srdb_test' => '{php} -f {srdb} -- -v -n "{base}" -u "{user}" -p"{pass}" -h "{host}" --port {port}',
        'replace'   => '{php} -f {srdb} -- -n "{base}" -u "{user}" -p"{pass}" -h "{host}" --port {port} -s"{search}" -r"{replace}" {tables} {options}',
    );

    private $envs = null;

    private $path = null;

    private $gzip = false;

    private function __construct()
    {
        global $argv;

        $this->script  = basename($argv[0]);
        $this->options = getopt(
            implode('', array_keys($this->opts)),
            array_values($this->opts)
        );

        // srdb
        $this->srdb = sprintf($this->srdb, uniqid());

        // generate ssh cmds
        $cmds = array();
        foreach ($this->cmds as $key => &$cmd) {
            if (strpos($key, 'ssh_') === false && !array_key_exists('ssh_' . $key, $this->cmds)) {
                $cmds[$key]          = $cmd;
                $cmds['ssh_' . $key] = 'ssh {ssh} \'' . str_replace('\'', '\'"\'"\'', $cmd) . '\'';
            } else {
                $cmds[$key] = $cmd;
            }
        }

        $this->cmds = $cmds;

        // path
        $this->exec('pwd', $this->path);

        if ($this->getOpt(self::OPT_HELP)) {
            $this->help();
        }

        $this->loadConfig();
        $this->checkConnections();

        // tables option handling (after connection check)
        // now inclusion is treated before, then exclusion on the resulting table list
//        if ($this->getOpt(self::OPT_INCLUDE) && $this->getOpt(self::OPT_EXCLUDE)) {
//            $this->error('You can’t use include and exclude options at the same time.');
//        }

        // exclusive options
        if ($this->getOpt(self::OPT_NO_REPLACEMENT) && $this->getOpt(self::OPT_REPLACEMENTS_ONLY)) {
            $this->error('You can’t use no-replacement and replacements-only options at the same time.');
        }

        $this->envs['source']['tables'] = $this->getTables();

        // try to extract origin env from the file
        if ($this->envs['source']['file']) {
            if ($file = gzopen($this->envs['source']['file'], 'r')) {
                if ($line = gzread($file, 64)) {
                    if (preg_match('/' . $this->marker . '(.+)($|'. PHP_EOL .')/', $line, $matches)) {
                        $this->envs['source']['env'] = $matches[1];
                    }
                }
                gzclose($file);
            }
        }

        // everything ok, display confirm message
        if ($this->getOpt(self::OPT_FORCE) || $this->confirm()) {
            $this->proceed();
        }
    }

    private function confirm()
    {
        echo PHP_EOL;
        if ($this->getOpt(self::OPT_REPLACEMENTS_ONLY)) {
            echo "Ready to apply replacements (no transfert):";
        } elseif ($this->getOpt(self::OPT_NO_REPLACEMENT)) {
            echo "Ready to transfert (no replacement):";
        } else {
            echo "Ready to sync:";
        }
        echo PHP_EOL . PHP_EOL;

        $cData   = 'grey';
        $cSymbol = 'yellow';

        $items = array('from' => 'source', 'to' => 'target');
        $out   = array();

        // label
        $length = 0;
        foreach ($items as $key => $env) {
            $out[$env] = $key . ' ';
            if ($this->envs[$env]['file']) {
                $out[$env] .= $this->c('file', 'no');
            } else {
                $out[$env] .= $this->c($this->envs[$env]['env'], 'yellow');
            }
            $out[$env] .= ':';
            $length = max($length, strlen($out[$env]));
        }

        // detail
        foreach ($items as $key => $env) {

            echo str_pad($out[$env], $length + 2, ' ', STR_PAD_LEFT) . "  ";

            if ($this->envs[$env]['file']) {
                echo $this->c($this->envs[$env]['file'], $cData);
            } else {
                $config = $this->envs[$env];
                echo $this->c($config['user'], $cData) . $this->c('@', $cSymbol);

                echo $this->c($config['host'], $cData);
                echo $this->c('/', $cSymbol) . $this->c($config['base'], $cData);

                if ($config['ssh']) {
                    echo $this->c(' ssh:', $cSymbol) . $this->c($config['ssh'], $cData);
                }
            }
            echo PHP_EOL;
        }

        // tables
        if ($this->envs['source']['tables']) {
            $tables = implode(
                PHP_EOL. str_pad('', $length - 7),
                $this->envs['source']['tables']
            );
            echo PHP_EOL;
            echo str_pad( 'tables:', $length - 9, ' ', STR_PAD_LEFT ) . "  ";
            echo $this->c($tables, $cData);
            echo PHP_EOL;
        }

        if ($this->envs['target']['protected'] === true) {
            $token = strtoupper(substr(sha1(rand()), 0, 4));
            $answer = readline(
                $this->c(PHP_EOL ."Target env ". $this->envs['target']['env'] ." is protected!". PHP_EOL ."Type ", 'red') .
                $this->c($token, 'red', true) .
                $this->c(" to proceed anyway: ", 'red')
            );
            return $answer === $token;
        }
        else {
            $answer = readline(PHP_EOL ."Do you confirm? (yo/N) ");
            return preg_match('/[yo]/', $answer);
        }
    }

    /**
     * @param string $key The long option name to get
     *
     * @return string|null The option value, null if not found
     */
    private function getOpt($key)
    {
        foreach ($this->opts as $short => $long) {
            // option with value
            if ($key . ':' == $long || $key . ':' == $short) {
                $short = str_replace(':', '', $short);
                $long  = str_replace(':', '', $long);
                if (array_key_exists($long, $this->options)) {
                    return $this->options[$long];
                } elseif (array_key_exists($short, $this->options)) {
                    return $this->options[$short];
                } else {
                    return null;
                }
            } // option without value
            elseif ($key == $long || $key == $short) {
                if (array_key_exists($long, $this->options)) {
                    return true;
                } elseif (array_key_exists($short, $this->options)) {
                    return true;
                } else {
                    return false;
                }
            }
        }

        return null;
    }

    private function checkConnections()
    {
        if ($this->envs['source']['file'] && $this->envs['target']['file']
            && $this->envs['source']['file'] == $this->envs['target']['file']
        ) {
            $this->error('Source and target file can not be same file');
        }

        $gzipUseful = false;

        // gzip may be useful for db transfert only
        if (!$this->getOpt(self::OPT_REPLACEMENTS_ONLY)) {

            // gzip is useful if one of the end (at least) is remote
            foreach ($this->envs as $env => $config) {
                if ($config['ssh']) {
                    $gzipUseful = true;
                    break;
                }
            }
        }

        // gzip is available only if available on both ends
        $gzipAvailable = true;

        foreach ($this->envs as $env => &$config) {
            // don’t need to test source if only doing replacements on target
            if ($env !== 'source' || !$this->getOpt(self::OPT_REPLACEMENTS_ONLY)) {
                if ($config['file']) {

                    // check file
                    if ($env == 'source') {
                        $this->checkSourceFile($config);
                    } else {
                        $this->checkTargetFile($config);
                    }
                } else {

                    // check connection
                    $result = $this->exec($this->getCmd('test', $config['ssh'], array(
                        'base' => $config['base'],
                        'user' => $config['user'],
                        'pass' => $config['pass'],
                        'host' => $config['host'],
                        'port' => $config['port'],
                    )), $output, true);
                    if ($result !== 0) {
                        $this->error('Connection error for ' . $config['env'] . ' environment.', $result);
                    }

                    // check gzip support (for db transfert only ; only if useful)
                    if (!$this->getOpt(self::OPT_REPLACEMENTS_ONLY) && $gzipUseful && $gzipAvailable) {
                        $result = $this->exec($this->getCmd('gzip_test', $config['ssh']), $output, true);
                        if ($result !== 0) {
                            $gzipAvailable = false;
                        }
                    }
                }
            }
        }

        $this->gzip = $gzipUseful && $gzipAvailable;
    }

    private function getEnvList()
    {
        // generate environments list
        $list     = "Available environments: ";
        $envNames = array_keys($this->config['environments']);
        array_walk($envNames, function (&$item) {
            $item = $this->c($item, 'yellow');
        });
        $list .= implode(', ', $envNames) . PHP_EOL;

        return $list;
    }

    private function checkSourceFile($source)
    {
        if (!file_exists($source['file']) || !is_readable($source['file'])) {
            // maybe it was not a file
            $this->error(
                "Source error, environment not found or file does not exists",
                $this->getEnvList()
            );
        }
    }

    private function checkTargetFile($target)
    {
        $file = $target['file'];
        $dir  = dirname($file);
        if (file_exists($file)) {
            if (!is_writable($file)) {
                $this->error("Target file is not writable");
            } else {
                $this->warning('Warning: output file exists, it will be overwritten');
            }
        } elseif (!file_exists($dir) || !is_dir($dir)) {
            $this->error("Target file directory does not exist");
        } elseif (!is_writable($dir)) {
            $this->error("Target file directory is not writable");
        }
    }

    private function loadConfig()
    {
        $confFile = $this->getOpt(self::OPT_CONF);
        if (!$confFile) {
            $confFile = self::$confDefaultFile;
        }

        if (!file_exists($confFile)) {
            $this->error('Can not find config file ' . $confFile, true);
        }

        $this->config = json_decode(file_get_contents($confFile), true);

        $error = json_last_error();
        if ($error != JSON_ERROR_NONE) {
            $this->error('Error reading ' . $confFile, json_last_error_msg());
        }

        // loading environments
        if (!array_key_exists('environments', $this->config) || !$this->config['environments']) {
            $this->error('No environment found in config.');
        }

        $envList = $this->getEnvList();

        // must have source and target
        if (!$this->getOpt(self::OPT_SOURCE)) {
            $this->error('Missing source environment', $envList, true);
        }
        if (!$this->getOpt(self::OPT_TARGET)) {
            $this->error('Missing target environment or output file', $envList, true);
        }

        $this->envs = array();
        foreach (array('source', 'target') as $env) {
            $val              = $this->getOpt($env);
            $this->envs[$env] = array(
                'env' => false,
                'user' => false,
                'pass' => false,
                'base' => false,
                'host' => 'localhost',
                'port' => self::MYSQL_DEFAULT_PORT,
                'ssh' => false,
                'file' => false,
                'php' => $this->defaultPhp,
                'protected' => false,
                'gzip' => false,
            );
            if (!$val) {
                $this->error("Please give the $env option", $envList);
            } elseif (!array_key_exists($val, $this->config['environments'])) {
                // no env, consider it is a file path
                $this->envs[$env]['file'] = $val;

                if (preg_match('/\.gz$/', $val)) {
                    $this->envs[$env]['gzip'] = true;
                }
            } else {
                $this->envs[$env]        = array_merge($this->envs[$env], $this->config['environments'][$val]);
                $this->envs[$env]['env'] = $val;
            }
        }

        if (array_key_exists('replacements', $this->config)) {
            foreach ($this->config['replacements'] as $replacement) {
                foreach (array('source', 'target') as $env) {
                    if ($this->envs[$env]['env'] && array_key_exists($this->envs[$env]['env'], $replacement)) {
                        if (empty($replacement[$this->envs[$env]['env']])) {
                            $this->error("Replacement value can not be empty for env ". $this->envs[$env]['env']);
                        }
                    }
                }
            }
        }
    }

    private function error($msg, $detail = '', $help = false)
    {
        echo $this->c($msg, 'red') . PHP_EOL;
        if ($detail) {
            echo $detail . PHP_EOL;
        }

        if ($help) {
            $this->help();
        }
        exit;
    }

    private function warning($msg, $detail = '')
    {
        echo $this->c($msg, 'red') . PHP_EOL;
        if ($detail) {
            echo $detail . PHP_EOL;
        }
    }

    private function help()
    {
        echo "Usage : " . $this->script . " [-c config_file] -s source -t target". PHP_EOL;
        echo "    -". self::OPT_CONF .", --". self::LOPT_CONF . PHP_EOL;
        echo "      Configuration file to read. Defaults to " . self::$confDefaultFile . ".". PHP_EOL;
        echo "    -". self::OPT_SOURCE .", --". self::LOPT_SOURCE . PHP_EOL;
        echo "      Source environment name or file path". PHP_EOL;
        echo "    -". self::OPT_TARGET .", --". self::LOPT_TARGET . PHP_EOL;
        echo "      Target environment name or file path.". PHP_EOL;
        echo "    -". self::OPT_REPLACEMENTS_ONLY .", --". self::LOPT_REPLACEMENTS_ONLY . PHP_EOL;
        echo "      No database transfert, replacements on target only.". PHP_EOL;
        echo "    -". self::OPT_NO_REPLACEMENT .", --". self::LOPT_NO_REPLACEMENT . PHP_EOL;
        echo "      Do not execute replacements, database transfert only.". PHP_EOL;
        echo "    -". self::OPT_FIX.", --". self::LOPT_FIX ."". PHP_EOL;
        echo "      Try to fix database charset problems like double utf8 encoded strings.". PHP_EOL;
        echo "    -". self::OPT_INCLUDE .", --". self::LOPT_INCLUDE ."". PHP_EOL;
        echo "      Include only given tables in sync. This option may be used multiple times.". PHP_EOL;
        echo "    -". self::OPT_EXCLUDE .", --". self::LOPT_EXCLUDE . PHP_EOL;
        echo "      Exclude tables from sync. This option may be used multiple times.". PHP_EOL;
        echo "    -". self::OPT_DEBUG .", --". self::LOPT_DEBUG . PHP_EOL;
        echo "      Debug mode, print all commands. No shell_exec execution except for". PHP_EOL;
        echo "      config tests (connection to distant database, and tables listing).". PHP_EOL;
        echo "    -". self::OPT_VERBOSE .", --". self::LOPT_VERBOSE . PHP_EOL;
        echo "      Verbose mode, print all executed commands.". PHP_EOL;
        echo "    -". self::OPT_FORCE .", --". self::LOPT_FORCE . PHP_EOL;
        echo "      Do not prompt for confirmation.". PHP_EOL;
        echo "    -". self::OPT_HELP .", --". self::LOPT_HELP . PHP_EOL;
        echo "      Display this help.". PHP_EOL;
        exit;
    }

    private function doing($msg)
    {
        echo $this->c("  [ ] ", 'grey') . $msg;
    }

    private function done($msg = null)
    {
        echo "\r  ";
        echo $this->c("[✔]", 'green');
        if ($msg) {
            echo ' ' . $msg;
        }
        echo PHP_EOL;
    }

    private function fail($msg = null)
    {
        echo "\r  ";
        echo $this->c("[✘]", 'red');
        if ($msg) {
            echo ' ' . $msg;
        }
        echo PHP_EOL;
    }

    /**
     * @param $format
     * @param $vars
     */
    private function nvsprintf($format, $vars = array(), $regex = '/{([^{}]*?)}/')
    {
        return preg_replace_callback($regex, function ($matches) use ($vars) {
            if (array_key_exists($matches[1], $vars)) {
                return $vars[$matches[1]];
            } else {
                return $matches[0];
            }
        }, $format);
    }

    private function getTables()
    {
        $filter = isset($this->config['tables']) ? $this->config['tables'] : null;
        $include = $this->getOpt(self::OPT_INCLUDE);
        $exclude = $this->getOpt(self::OPT_EXCLUDE);

        // get tables
        $source = $this->envs['source'];
        if ($source['file']) {
            $tables = $this->loadTablesFromFile($source['file']);
        }
        else {
            $this->exec($this->getCmd('tables', $source['ssh'], array(
                'user' => $source['user'],
                'pass' => $source['pass'],
                'base' => $source['base'],
                'host' => $source['host'],
                'port' => $source['port'],
            )), $output, true);
            $tables = explode(PHP_EOL, $output);
        }

        if ($filter || $include || $exclude) {

            // filtering
            if ($filter) {
                $tables = array_filter($tables, function ($table) use ($filter) {
                    return preg_match('/'. addslashes($filter) .'/', $table);
                });
            }

            $available = "Available tables are:". PHP_EOL ." - " . implode(PHP_EOL. " - ", $tables);

            if ($include) {
                if (!is_array($include)) {
                    $include = array($include);
                }
                // just checking if all included tables exist
                foreach ($include as $table) {
                    if (array_search($table, $tables) === false) {
                        $this->error('The included table `' . $table . '` is not found', $available);
                    }
                }
                $tables = $include;
            }

            if ($exclude) {
                if (!is_array($exclude)) {
                    $exclude = array($exclude);
                }
                foreach ($exclude as $table) {
                    $offset = array_search($table, $tables);
                    if ($offset === false) {
                        $this->error('The excluded table `' . $table . '` is not found', $available);
                    } else {
                        unset($tables[$offset]);
                    }
                }
            }
        }

        return $tables;
    }

    private function getFileContents($file)
    {
        $handle = gzopen($file, 'r');

        $contents = '';
        while ($data = gzread($handle, 10000)) {
            $contents .= $data;
        }
        gzclose($handle);
        return $contents;
    }

    private function loadTablesFromFile($file)
    {
        $tables = array();
        preg_match_all('/^CREATE TABLE.*?;/ims', $this->getFileContents($file), $matches,  PREG_SET_ORDER);

        if ($matches) {
            $parser = new PHPSQLParser();

            foreach ($matches as $match) {
                $expression = $parser->parse($match[0]);
                $tables[] = $expression['TABLE']['no_quotes']['parts'][0];
            }
        }

        return $tables;
    }

    private function proceed()
    {
        echo PHP_EOL;
        $source = $this->envs['source'];
        $target = array_key_exists('target', $this->envs) ? $this->envs['target'] : null;

        if (!$this->getOpt(self::OPT_REPLACEMENTS_ONLY)) {
            $this->transfert($source, $target);
        }

        if (!$this->getOpt(self::OPT_NO_REPLACEMENT)
            && array_key_exists('replacements', $this->config)
            && $source['env']
            && $target['env']
            && $source['env'] != $target['env']
        ) {
            $this->replace($target, $source);
        }
        echo PHP_EOL;
    }

    private function transfert($source, $target)
    {
        $this->doing('database transfer...');

        // source
        if ($source['file']) {
            // file source

            $gzip = '';
            if ($this->gzip) {
                if (!$source['gzip']) {
                    $gzip = ' | gzip -9 ';
                }
            } elseif ($source['gzip']) {
                $gzip = ' | gzip -d ';
            }
            $sourceCmd = $this->nvsprintf($this->cmds['fromfile'], array(
                'file' => $source['file'],
                'gzip' => $gzip,
            ));
        } else {
            // mysql source
            $sourceCmd = $this->getCmd('source', $source['ssh'], array(
                'user' => $source['user'],
                'pass' => $source['pass'],
                'base' => $source['base'],
                'host' => $source['host'],
                'port' => $source['port'],
                'options' => $this->getOpt(self::OPT_FIX) ? '--skip-set-charset --default-character-set=latin1' : '',
                'tables' => implode(' ', $source['tables']),
                'gzip' => $this->gzip ? ' | gzip -9 ' : '',
            ));
        }

        // target
        if ($target['file']) {
            // file target
            $cmd = $source['env'] ? 'markfile' : 'tofile';
            $targetCmd = $this->nvsprintf($this->cmds[$cmd], array(
                'marker' => $this->marker,
                'env' => $source['env'],
                'file' => $target['file'],
                'gzip' => $target['gzip'] ? ' | gzip -9 ' : '',
            ));

            // if source is gzip, gunzip before write
            if ($this->gzip) {
                $targetCmd = ' gzip -d | ' . $targetCmd;
            }

        } else {
            // mysql target
            $targetCmd = $this->getCmd('target', $target['ssh'], array(
                'user' => $target['user'],
                'pass' => $target['pass'],
                'base' => $target['base'],
                'host' => $target['host'],
                'port' => $target['port'],
                'options' => $this->getOpt(self::OPT_FIX) ? '--default-character-set=utf8' : '',
                'gzip' => $this->gzip ? 'gzip -d | ' : '',
            ));
        }
        $result = $this->exec($sourceCmd . ' | ' . $targetCmd, $output);
        if ($result !== 0) {
            $this->fail();
            exit;
        }
        $this->done('database transfer succeeded      ');
    }

    private function replace($target, $source)
    {
        $this->doing('replacements in database...');

        // copy srdb to tmp
        $this->exec($this->getCmd('cp', $target['ssh'], array(
            'data' => $this->getSrdbData(),
            'srdb' => $this->srdb,
            'php' => $this->defaultPhp
        )));

        // chmod +x
//            $this->exec($this->getCmd('chmod', $target['ssh'], array(
//                'srdb' => $this->srdb,
//            )));

        $replaceCmd = $this->getCmd('replace', $target['ssh'], array(
            'srdb' => $this->srdb,
            'base' => $target['base'],
            'user' => $target['user'],
            'pass' => $target['pass'],
            'host' => $target['host'],
            'port' => $target['port'],
            'php' => $target['php'],
            'tables' => $source['tables'] ? '-t'. implode(',', $source['tables']) : ''
        ));

        // pre-replacement test
        $output = '';
        $result = $this->exec($this->getCmd('srdb_test', $target['ssh'], array(
            'srdb' => $this->srdb,
            'base' => $target['base'],
            'user' => $target['user'],
            'pass' => $target['pass'],
            'host' => $target['host'],
            'port' => $target['port'],
            'php' => $target['php']
        )), $output);

        if ($result !== 0) {
            $this->removeSrdb($target);
            $this->fail('replacements failed, errors follow...');
            echo PHP_EOL . $output . PHP_EOL;
            exit;
        }

        $count = 0;
        $errors = '';
        $changeCount = 0;
        foreach ($this->config['replacements'] as $replacement) {
            if (!array_key_exists($source['env'], $replacement)) {
                continue;
            }
            if (!array_key_exists($target['env'], $replacement)) {
                continue;
            }
            // options
            $options = '';
            foreach ($this->srdbOpts as $short => $long) {
                $optName = str_replace(':', '', $long);
                if (array_key_exists($optName, $replacement)) {
                    $options .= ' --' . $optName;
                    if ($optName != $long) {
                        $options .= ' "' . $replacement[$optName] . '"';
                    }
                }
            }
            $output = '';
            $result = $this->exec($this->nvsprintf($replaceCmd, array(
                'search' => $replacement[$source['env']],
                'replace' => $replacement[$target['env']],
                'options' => $options
            )), $output);

            if ($result !== 0) {
                $errors .= $output . PHP_EOL;
            } elseif (preg_match('/([0-9]+) *changes were made/', $output, $matches)) {
                $changeCount += (int)$matches[1];
            }

            ++$count;
        }

        $this->removeSrdb($target);

        if ($errors) {
            $this->fail('replacements failed, errors follow...');
            echo PHP_EOL . $errors . PHP_EOL;
            exit;
        } else {
            $this->done($changeCount . ' replacements executed        ');
        }
    }

    private function removeSrdb($target)
    {
        $this->exec($this->getCmd('rm', $target['ssh'], array(
            'srdb' => $this->srdb,
            'ssh' => $target['ssh']
        )));
    }

    private function getCmd($cmd, $ssh, $data = array())
    {
        if (!array_key_exists('ssh', $data)) {
            $data['ssh'] = $ssh;
        }

        return $this->nvsprintf(
            $this->cmds[$ssh ? 'ssh_' . $cmd : $cmd],
            $data
        );
    }

    private function exec($cmd, &$output = null, $force = false)
    {
        if ($this->getOpt(self::OPT_DEBUG) && !$force) {
            $this->printCmd($cmd);

            return 0;
        } else {
            if ($this->getOpt(self::OPT_DEBUG) || $this->getOpt(self::OPT_VERBOSE)) {
                $this->printCmd($cmd);
            }
            $output = '';
            $result = 0;
            exec($cmd, $output, $result);
            $output = implode(PHP_EOL, $output);

            return $result;
        }
    }

    private function printCmd($cmd)
    {
        $lmax = 512;
        $output = '';
        if (strlen($cmd) > $lmax) {
            $output .= PHP_EOL . substr($cmd, 0, $lmax / 2) . ' ... ';
            $output .= substr($cmd, strlen($cmd) - $lmax / 2) . PHP_EOL;
        } else {
            $output .= PHP_EOL . $cmd . PHP_EOL;
        }
        echo $this->c($output, 'purple');
    }

    private function c($text, $color = 'no', $bold = false)
    {
        if (array_key_exists($color, $this->colors)) {
            return "\033[" . ($bold ? '1' : '0') . ';' . $this->colors[$color] . 'm' . $text . "\033[0m";
        } else {
            return $text;
        }
    }

    /**
     * @return string SRDB script as base64 string for transfert
     */
    private function getSrdbData()
    {
        $classPath = (new ReflectionClass('icit_srdb'))->getFileName();
        $cliPath = dirname($classPath) . '/srdb.cli.php';

        $class = file_get_contents($classPath);
        $cli = file_get_contents($cliPath);

        // drop require in cli
        $regex = '/^require_once.*'. str_replace('.', '\.', 'srdb.class.php') .'.*$/m';
        $cli = preg_replace($regex, '// -- inserted at the end of this file --', $cli);

        // drop php open tag
        $class = preg_replace('/^\<\?php.*$/m', '', $class);
        $out = $cli .PHP_EOL. $class;

        return chunk_split(base64_encode($out));
    }
}
