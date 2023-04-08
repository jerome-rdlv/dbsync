<?php

namespace Rdlv\DbSync;

use PHPSQLParser\PHPSQLParser;
use Symfony\Component\Yaml\Yaml;

/**
 * todo Replace dbreplace.php with a new search-replacement feature on SQL stream.
 *  Use https://github.com/phpmyadmin/sql-parser (but don’t know if it can work on a stream
 *  nor with high volume dumps > 100MB)
 */
class App
{
    const OPT_CONF = 'c';
    const OPT_SOURCE = 's';
    const OPT_TARGET = 't';
    const OPT_REPLACEMENTS_ONLY = 'r';
    const OPT_NO_REPLACEMENT = 'n';
    const OPT_FIX = 'm';
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

    public static $confDefaultFile = 'dbsync';

    public static function run()
    {
        return new self();
    }

    private $config = null;

    private $dbr = 'dbr_%s.php';

    private $defaultPhp = 'php';
    private $defaultMysql = 'mysql';
    private $defaultMysqldump = 'mysqldump';

    // used to mark source env in dump file
    private $marker = '-- sync-db env: ';

    private $opts;

    private $dbrOpts = [
        't:' => 'tables:',
        'i:' => 'include-cols:',
        'x:' => 'exclude-cols:',
        'g' => 'regex',
    ];

    private $options = null;

    private $colors = [
        'no' => '00',
        'grey' => '37',
        'yellow' => '33',
        'red' => '31',
        'green' => '32',
        'blue' => '34',
        'purple' => '35',
        'ocean' => '36',
        'dark' => '30',
    ];

    private $cmds = [
        'test' => 'printf "use {:base};" | {:mysql} -u {:user} -p{:pass} -h {:host} -P {:port} --protocol=TCP {:base}',
        'gzip_test' => 'gzip -V',
        'tables' => '{:mysql} --skip-column-names -u {:user} -p{:pass} -h {:host} -P {:port} --protocol=TCP {:base} -e "show tables;"',
        'source' => '{:mysqldump} {options} -u {:user} -p{:pass} --add-drop-table --no-create-db --no-tablespaces -h {:host} -P {:port} --protocol=TCP {:base} {:tables} {gzip}',
        'target' => '{gzip} {:mysql} {options} -u {:user} -p{:pass} -h {:host} -P {:port} --protocol=TCP {:base}',
        'markfile' => '{ echo {:mark}; cat; } {gzip} > {:file}',
        'tofile' => ' cat {gzip} > {:file}',
        'fromfile' => 'cat {:file} {gzip}',
        'cp' => 'printf {:data} | {:php} -r "echo base64_decode(stream_get_contents(STDIN));" > {:dbr}',
        'ssh_cp' => 'printf {:data} | {:php} -r "echo base64_decode(stream_get_contents(STDIN));" | ssh {ssh} \'cat > {:dbr}\'',
        'chmod' => 'chmod +x {:dbr}',
        'rm' => 'rm {:dbr}',
        'dbr_test' => '{:php} -f {:dbr} -- -v -n {:base} -u {:user} -p{:pass} -h {:host} --port {:port}',
        'replace' => '{:php} -f {:dbr} -- -n {:base} -u {:user} -p{:pass} -h {:host} --port {:port} -s{:search} -r{:replace} {options}',
    ];

    private $envs = null;

    private $path = null;

    private $gzip = false;

    private function __construct()
    {
        global $argv;

        $this->opts = [
            self::OPT_CONF . ':' => self::LOPT_CONF . ':',
            self::OPT_SOURCE . ':' => self::LOPT_SOURCE . ':',
            self::OPT_TARGET . ':' => self::LOPT_TARGET . ':',
            self::OPT_REPLACEMENTS_ONLY => self::LOPT_REPLACEMENTS_ONLY,
            self::OPT_NO_REPLACEMENT => self::LOPT_NO_REPLACEMENT,
            self::OPT_FIX => self::LOPT_FIX,
            self::OPT_INCLUDE . ':' => self::LOPT_INCLUDE . ':',
            self::OPT_EXCLUDE . ':' => self::LOPT_EXCLUDE . ':',
            self::OPT_HELP => self::LOPT_HELP,
            self::OPT_DEBUG => self::LOPT_DEBUG,
            self::OPT_VERBOSE => self::LOPT_VERBOSE,
            self::OPT_FORCE => self::LOPT_FORCE,
        ];

        $this->script = basename($argv[0]);
        $this->options = getopt(
            implode('', array_keys($this->opts)),
            array_values($this->opts)
        );

        // dbr 
        $this->dbr = sprintf($this->dbr, uniqid());

        // generate ssh cmds
//        $cmds = [];
//        foreach ($this->cmds as $key => &$cmd) {
//            if (strpos($key, 'ssh_') === false && !array_key_exists('ssh_' . $key, $this->cmds)) {
//                $cmds[$key] = $cmd;
//                #$cmds['ssh_' . $key] = 'ssh {ssh} \'' . str_replace('\'', '\'"\'"\'', $cmd) . '\'';
//                $cmds['ssh_' . $key] = 'ssh {ssh} \'' . escapeshellcmd($cmd) . '\'';
//            } else {
//                $cmds[$key] = $cmd;
//            }
//        }
//
//        $this->cmds = $cmds;

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
                    if (preg_match('/' . $this->marker . '(.+)($|' . PHP_EOL . ')/', $line, $matches)) {
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

        $cData = 'grey';
        $cSymbol = 'yellow';

        $items = ['from' => 'source', 'to' => 'target'];
        $out = [];

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

        // tables
        if ($this->envs['source']['tables']) {
            $tables = implode(
                PHP_EOL . str_pad('', $length - 7),
                $this->envs['source']['tables']
            );
            echo str_pad('tables:', $length - 9, ' ', STR_PAD_LEFT) . "  ";
            echo $this->c($tables, $cData);
            echo PHP_EOL;
            echo PHP_EOL;
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

        if ($this->envs['target']['protected'] === true) {
            $token = strtoupper(substr(sha1(rand()), 0, 4));
            $answer = readline(
                $this->c(PHP_EOL . "Target env " . $this->envs['target']['env'] . " is protected!" . PHP_EOL . "Type ",
                         'red') .
                $this->c($token, 'red', true) .
                $this->c(" to proceed anyway: ", 'red')
            );
            return $answer === $token;
        } else {
            $answer = readline(PHP_EOL . "Do you confirm? (yo/N) ");
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
                $long = str_replace(':', '', $long);
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
                    $result = $this->exec($this->getCmd('test', $config['ssh'], [
                        'base' => $config['base'],
                        'user' => $config['user'],
                        'pass' => $config['pass'],
                        'host' => $config['host'],
                        'port' => $config['port'],
                        'mysql' => $config['mysql'],
                    ]),                   $output, true);
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
        $list = "Available environments: ";
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
        $dir = dirname($file);
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
            // try default paths
            foreach (['yml', 'yaml', 'json'] as $ext) {
                $confFile = self::$confDefaultFile . '.' . $ext;
                if (file_exists($confFile)) {
                    break;
                }
            }

            if (!file_exists($confFile)) {
                $this->error('Can not find config file ' . self::$confDefaultFile . '.yml', true);
            }
        } elseif (!file_exists($confFile)) {
            $this->error('Can not find config file ' . $confFile, true);
        }

        $extension = pathinfo($confFile, PATHINFO_EXTENSION);
        switch ($extension) {
            case 'yml':
            case 'yaml':
                $this->config = Yaml::parse(file_get_contents($confFile));
                break;
            case 'json':
                if (function_exists('json_decode')) {
                    $this->config = json_decode(file_get_contents($confFile), true);
                    $error = json_last_error();
                    if ($error != JSON_ERROR_NONE) {
                        $this->error('Error reading ' . $confFile, json_last_error_msg());
                    }
                } else {
                    $this->error('Please install json-ext to parse json config file');
                }
                break;
            default:
                $this->error('Config file must be in JSON or YAML format');
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

        $this->envs = [];
        foreach (['source', 'target'] as $env) {
            $val = $this->getOpt($env);
            $this->envs[$env] = [
                'env' => false,
                'user' => false,
                'pass' => false,
                'base' => false,
                'host' => 'localhost',
                'port' => self::MYSQL_DEFAULT_PORT,
                'ssh' => false,
                'file' => false,
                'php' => $this->defaultPhp,
                'mysql' => $this->defaultMysql,
                'mysqldump' => $this->defaultMysqldump,
                'protected' => false,
                'gzip' => false,
            ];
            if (!$val) {
                $this->error("Please give the $env option", $envList);
            } elseif (!array_key_exists($val, $this->config['environments'])) {
                // no env, consider it is a file path
                $this->envs[$env]['file'] = $val;

                if (preg_match('/\.gz$/', $val)) {
                    $this->envs[$env]['gzip'] = true;
                }
            } else {
                $this->envs[$env] = array_merge($this->envs[$env], $this->config['environments'][$val]);
                $this->envs[$env]['env'] = $val;
            }
        }

        if (array_key_exists('replacements', $this->config)) {
            foreach ($this->config['replacements'] as $replacement) {
                foreach (['source', 'target'] as $env) {
                    if ($this->envs[$env]['env'] && array_key_exists($this->envs[$env]['env'], $replacement)) {
                        if (empty($replacement[$this->envs[$env]['env']])) {
                            $this->error("Replacement value can not be empty for env " . $this->envs[$env]['env']);
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
        echo "Usage : " . $this->script . " [-c config_file] -s source -t target" . PHP_EOL;
        echo "    -" . self::OPT_CONF . ", --" . self::LOPT_CONF . PHP_EOL;
        echo "      Configuration file to read. Defaults to " . self::$confDefaultFile . "." . PHP_EOL;
        echo "    -" . self::OPT_SOURCE . ", --" . self::LOPT_SOURCE . PHP_EOL;
        echo "      Source environment name or file path" . PHP_EOL;
        echo "    -" . self::OPT_TARGET . ", --" . self::LOPT_TARGET . PHP_EOL;
        echo "      Target environment name or file path." . PHP_EOL;
        echo "    -" . self::OPT_REPLACEMENTS_ONLY . ", --" . self::LOPT_REPLACEMENTS_ONLY . PHP_EOL;
        echo "      No database transfert, replacements on target only." . PHP_EOL;
        echo "    -" . self::OPT_NO_REPLACEMENT . ", --" . self::LOPT_NO_REPLACEMENT . PHP_EOL;
        echo "      Do not execute replacements, database transfert only." . PHP_EOL;
        echo "    -" . self::OPT_FIX . ", --" . self::LOPT_FIX . "" . PHP_EOL;
        echo "      Try to fix database charset problems like double utf8 encoded strings." . PHP_EOL;
        echo "    -" . self::OPT_INCLUDE . ", --" . self::LOPT_INCLUDE . "" . PHP_EOL;
        echo "      Include only given tables in sync. This option may be used multiple times." . PHP_EOL;
        echo "    -" . self::OPT_EXCLUDE . ", --" . self::LOPT_EXCLUDE . PHP_EOL;
        echo "      Exclude tables from sync. This option may be used multiple times." . PHP_EOL;
        echo "    -" . self::OPT_DEBUG . ", --" . self::LOPT_DEBUG . PHP_EOL;
        echo "      Debug mode, print all commands. No shell_exec execution except for" . PHP_EOL;
        echo "      config tests (connection to distant database, and tables listing)." . PHP_EOL;
        echo "    -" . self::OPT_VERBOSE . ", --" . self::LOPT_VERBOSE . PHP_EOL;
        echo "      Verbose mode, print all executed commands." . PHP_EOL;
        echo "    -" . self::OPT_FORCE . ", --" . self::LOPT_FORCE . PHP_EOL;
        echo "      Do not prompt for confirmation." . PHP_EOL;
        echo "    -" . self::OPT_HELP . ", --" . self::LOPT_HELP . PHP_EOL;
        echo "      Display this help." . PHP_EOL;
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
    private function buildCommand($format, $vars = [], $regex = '/{([^{}]*?)}/')
    {
        return preg_replace_callback($regex, function ($matches) use ($vars) {
            $name = preg_replace('/^:/', '', $matches[1]);
            if (array_key_exists($name, $vars)) {
                return substr($matches[1], 0, 1) === ':'
                    ? $this->escape($vars[$name])
                    : $vars[$name];
            } else {
                return $matches[0];
            }
        },                           $format);
    }

    private function escape($value)
    {
        if (is_array($value)) {
            return implode(' ', array_map(function ($item) {
                return $this->escape($item);
            }, $value));
        } else {
            return escapeshellarg((string)$value);
        }
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
        } else {
            $this->exec($this->getCmd('tables', $source['ssh'], [
                'user' => $source['user'],
                'pass' => $source['pass'],
                'base' => $source['base'],
                'host' => $source['host'],
                'port' => $source['port'],
                'mysql' => $source['mysql'],
            ]),         $output, true);
            $tables = explode(PHP_EOL, $output);
        }

        if ($filter || $include || $exclude) {
            // filtering
            if ($filter) {
                $tables = array_filter($tables, function ($table) use ($filter) {
                    return preg_match('/' . addslashes($filter) . '/', $table);
                });
            }

            $available = "Available tables are:" . PHP_EOL . " - " . implode(PHP_EOL . " - ", $tables);

            if ($include) {
                if (!is_array($include)) {
                    $include = [$include];
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
                    $exclude = [$exclude];
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
        $tables = [];
        preg_match_all('/^CREATE TABLE.*?;/ims', $this->getFileContents($file), $matches, PREG_SET_ORDER);

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
            $sourceCmd = $this->buildCommand($this->cmds['fromfile'], [
                'file' => $source['file'],
                'gzip' => $gzip,
            ]);
        } else {
            // mysql source
            $sourceCmd = $this->getCmd('source', $source['ssh'], [
                'user' => $source['user'],
                'pass' => $source['pass'],
                'base' => $source['base'],
                'host' => $source['host'],
                'port' => $source['port'],
                'mysqldump' => $source['mysqldump'],
                'options' => $this->getOpt(self::OPT_FIX) ? '--skip-set-charset --default-character-set=latin1' : '',
                'tables' => $source['tables'],
                'gzip' => $this->gzip ? ' | gzip -9 ' : '',
            ]);
        }

        // target
        if ($target['file']) {
            // file target
            $cmd = $source['env'] ? 'markfile' : 'tofile';
            $targetCmd = $this->buildCommand($this->cmds[$cmd], [
                'mark' => $this->marker . $source['env'],
                'file' => $target['file'],
                'gzip' => $target['gzip'] ? ' | gzip -9 ' : '',
            ]);

            // if source is gzip, gunzip before write
            if ($this->gzip) {
                $targetCmd = ' gzip -d | ' . $targetCmd;
            }
        } else {
            // mysql target
            $targetCmd = $this->getCmd('target', $target['ssh'], [
                'user' => $target['user'],
                'pass' => $target['pass'],
                'base' => $target['base'],
                'host' => $target['host'],
                'port' => $target['port'],
                'mysql' => $target['mysql'],
                'options' => $this->getOpt(self::OPT_FIX) ? '--default-character-set=utf8' : '',
                'gzip' => $this->gzip ? 'gzip -d | ' : '',
            ]);
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

        // copy dbr to tmp
        $this->exec($this->getCmd('cp', $target['ssh'], [
            'data' => $this->getDbrData(),
            'dbr' => $this->dbr,
            'php' => $this->defaultPhp,
        ]));

        // chmod +x
//            $this->exec($this->getCmd('chmod', $target['ssh'], array(
//                'dbr' => $this->dbr,
//            )));

        $replaceCmd = $this->getCmd('replace', $target['ssh'], [
            'dbr' => $this->dbr,
            'base' => $target['base'],
            'user' => $target['user'],
            'pass' => $target['pass'],
            'host' => $target['host'],
            'port' => $target['port'],
            'php' => $target['php'],
        ]);

        // pre-replacement test
        $output = '';
        $result = $this->exec($this->getCmd('dbr_test', $target['ssh'], [
            'dbr' => $this->dbr,
            'base' => $target['base'],
            'user' => $target['user'],
            'pass' => $target['pass'],
            'host' => $target['host'],
            'port' => $target['port'],
            'php' => $target['php'],
        ]),                   $output);

        if ($result !== 0) {
            $this->removeDbr($target);
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
            $options = [];

            // limit replacement on source table names by default
            $options['tables'] = '--tables "' . implode(',', $source['tables']) . '"';

            foreach ($this->dbrOpts as $short => $long) {
                $optName = str_replace(':', '', $long);
                if (array_key_exists($optName, $replacement)) {
                    $options[$optName] = '--' . $optName;

                    // if option require a value
                    if ($optName != $long) {
                        $value = $replacement[$optName];
//                        $options[$optName] .= ' "' . $replacement[$optName] . '"';
                        $options[$optName] .= sprintf(
                            ' "%s"',
                            implode(',', is_array($value) ? $value : [$value])
                        );
                    }
                }
            }

            $output = '';
            $result = $this->exec($this->buildCommand($replaceCmd, [
                'search' => $replacement[$source['env']],
                'replace' => $replacement[$target['env']],
                'options' => implode(' ', $options),
            ]),                   $output);

            if ($result !== 0) {
                $errors .= $output . PHP_EOL;
            } elseif (preg_match('/([0-9]+) *changes were made/', $output, $matches)) {
                $changeCount += (int)$matches[1];
            }

            ++$count;
        }

        $this->removeDbr($target);

        if ($errors) {
            $this->fail('replacements failed, errors follow...');
            echo PHP_EOL . $errors . PHP_EOL;
            exit;
        } else {
            $this->done($changeCount . ' replacements executed        ');
        }
    }

    private function removeDbr($target)
    {
        $this->exec($this->getCmd('rm', $target['ssh'], [
            'dbr' => $this->dbr,
        ]));
    }

    private function getCmd($cmd, $ssh, $args = [])
    {
        $cmd = $this->buildCommand($this->cmds[$cmd], $args);

        return $ssh
            ? $this->buildCommand(
                'ssh {ssh} {:cmd}',
                [
                    'ssh' => $ssh,
                    'cmd' => $cmd,
                ]
            )
            : $cmd;
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
            $output = [];
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
     * @return string dbreplace script as base64 string for transfert
     */
    private function getDbrData()
    {
        return chunk_split(
            base64_encode(
                file_get_contents(__DIR__ . '/../../../dbreplace.php')
            )
        );
    }
}
