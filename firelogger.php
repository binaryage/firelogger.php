<?php
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // FireLogger for PHP (server-side library)
    //
    // http://firelogger4php.binaryage.com
    // protocol specs: http://wiki.github.com/darwin/firelogger
    //

    // init constants, you may define them before including firelog.php
    if (!defined('FIRELOGGER_VERSION')) define('FIRELOGGER_VERSION', '0.1');
    if (!defined('FIRELOGGER_API_VERSION')) define('FIRELOGGER_API_VERSION', 1);
    if (!defined('FIRELOGGER_MAX_PICKLE_DEPTH')) define('FIRELOGGER_MAX_PICKLE_DEPTH', 10);

    $registeredFireLoggers = array(); // the array of all instantiated fire-loggers during request
    $fireLoggerGlobalCounter = 0; // aid for ordering log records on client
    $fireLoggerEnabled = true; // enabled by default
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    class FireLoggerFileLine {
        public $file;
        public $line;

        function __construct($file, $line) {
            $this->file = $file;
            $this->line = $line;
        }
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    class FireLogger {
        public $name;  // [optional] logger name
        public $style; // [optional] CSS snippet for logger icon in FireLogger console
        public $logs = array();
        var $levels = array('debug', 'warning', 'info', 'error', 'critical');
        
        function __construct($name, $style=null) {
            global $registeredFireLoggers;
            $this->name = $name;
            $this->style = $style;
            $registeredFireLoggers[] = $this;
        }
        
        private function pickle($o, $maxLevel=FIRELOGGER_MAX_PICKLE_DEPTH) {
            if ($maxLevel==0) {
                // see http://us3.php.net/manual/en/language.types.string.php#73524
                if (!is_object($o) || method_exists($o, '__toString')) {
                    return (string)$o;
                }
                return get_class($o);
            }
            if (is_object($o)) {
                $data = array();
                $r = new ReflectionObject($o);
                $props = $r->getProperties();
                foreach ($props as $prop) {
                    $name = $prop->getName();
                    $prop->setAccessible(true); // http://schlitt.info/opensource/blog/0581_reflecting_private_properties.html
                    $val = $prop->getValue($o);
                    $data[$name] = $this->pickle($val, $maxLevel-1);
                }
                return $data;
            }
            if (is_array($o)) {
                $data = array();
                foreach($o as $k=>$v) {
                    $data[$k] = $this->pickle($v, $maxLevel-1);
                }
                return $data;
            }
            // TODO: investigate other complex cases
            return $o;
        }

        private function fix_eval_in_file_line($file, $line) {
            // special hack for eval'd code:
            // "/Users/darwin/code/firelogger4php/test.php(41) : eval()'d code 21"
            if (preg_match('/(.*)\((\d+)\) : eval/', $file, $matches)>0) {
                $file = $matches[1];
                $line = $matches[2];
            }
            return array($file, $line);
        }
        
        private function extract_file_line($trace) {
            while (count($trace)>0 && !array_key_exists('file', $trace[0])) array_shift($trace);
            $thisFile = $trace[0]['file'];
            while (count($trace)>0 && $trace[0]['file']==$thisFile) array_shift($trace);
            
            $file = $trace[0]['file'];
            $line = $trace[0]['line'];
            
            return $this->fix_eval_in_file_line($file, $line);
        }
        
        function log(/*level, fmt, obj1, obj2, ...*/) {
            global $fireLoggerGlobalCounter, $fireLoggerEnabled;
            if (!$fireLoggerEnabled) return; // no-op
            
            $args = func_get_args();
            $fmt = '';
            $level = 'debug';
            if (gettype($args[0])==='string' && in_array($args[0], $this->levels)) {
                $level = array_shift($args);
            }
            if (gettype($args[0])==='string') {
                $fmt = array_shift($args);
            }

            $time = microtime(true);
            $item = array(
                'name' => $this->name,
                'args' => array(),
                'level' => $level,
                'timestamp' => $time,
                'order' => $fireLoggerGlobalCounter++, // PHP is really fast, timestamp has insufficient resolution for log records ordering
                'time' => gmdate('H:i:s', (int)$time).'.'.substr(fmod($time, 1.0), 2, 3), // '23:53:13.396'
                'template' => $fmt,
                'message' => $fmt // TODO: render reasonable plain text message
            );
            if ($this->style) $item['style'] = $this->style;
            if (count($args)>0 && is_object($args[0]) && is_a($args[0], 'Exception')) { // is_object check prevents http://pear.php.net/bugs/bug.php?id=2975
                // exception with backtrace
                $e = $args[0];
                $trace = $e->getTrace();
                $t = array();
                $f = array();
                foreach ($trace as $frame) {
                    // prevent notices about invalid indices, wasn't able to google smart solution, PHP is dumb ass
                    if (!isset($frame['file'])) $frame['file'] = null;
                    if (!isset($frame['line'])) $frame['line'] = null; 
                    if (!isset($frame['class'])) $frame['class'] = null;
                    if (!isset($frame['type'])) $frame['type'] = null;
                    if (!isset($frame['function'])) $frame['function'] = null;
                    if (!isset($frame['object'])) $frame['object'] = null;
                    if (!isset($frame['args'])) $frame['args'] = null;
                    
                    $t[] = array(
                        $frame['file'],
                        $frame['line'],
                        $frame['class'].$frame['type'].$frame['function'],
                        $frame['object']
                    );
                    $f[] = $frame['args'];
                };
                
                $item['exc_info'] = array(
                    $e->getMessage(),
                    $e->getFile(),
                    $t
                );
                $item['exc_frames'] = $f;
                $item['exc_text'] = 'exception';
                $item['template'] = $e->getMessage();
                $item['code'] = $e->getCode();
                $item['pathname'] = $e->getFile();
                $item['lineno'] = $e->getLine();
            } else {
                // rich log record
                $trace = debug_backtrace();
                list($file, $line) = $this->extract_file_line($trace);
                $data = array();
                $item['pathname'] = $file;
                $item['lineno'] = $line;
                foreach ($args as $arg) {
                    // override file/line in case we've got passed FireLoggerFileLine
                    if ($arg && is_object($arg) && is_a($arg, 'FireLoggerFileLine')) { 
                        list($file, $line) = $this->fix_eval_in_file_line($arg->file, $arg->line);
                        $item['pathname'] = $file;
                        $item['lineno'] = $line;
                        continue; // do not process this arg
                    }
                    $data[] = $this->pickle($arg);
                }
                $item['args'] = $data;
            }
            
            $this->logs[] = $item;
        }
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // decide if firelogger should be enabled
    if (!defined('FIRELOGGER_NO_VERSION_CHECK')) {
        if (!isset($_SERVER['HTTP_X_FIRELOGGER'])) {
            $fireLoggerEnabled = false;
        } else {
            $version = $_SERVER['HTTP_X_FIRELOGGER'];
            $bestExtensionVersion = '0.7';
            if ($version!=$bestExtensionVersion) {
                trigger_error("FireLogger for PHP works best with FireLogger extension of version $bestExtensionVersion. Please upgrade your Firefox extension: http://firelogger4php.binaryage.com.");
            }
        }
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // test if firelogger password matches
    if (!defined('FIRELOGGER_NO_PASSWORD_CHECK') && defined('FIRELOGGER_PASSWORD') && $fireLoggerEnabled) {
        if (isset($_SERVER['HTTP_X_FIRELOGGERAUTH'])) {
            $clientHash = $_SERVER['HTTP_X_FIRELOGGERAUTH'];
            $serverHash = md5("#FireLoggerPassword#".FIRELOGGER_PASSWORD."#");
            if ($clientHash!==$serverHash) { // passwords do not match
                $fireLoggerEnabled = false;
                trigger_error("FireLogger password do not match. Have you specified correct password FireLogger extension?");
            }
        } else {
            $fireLoggerEnabled = false; // silently disable firelogger in case client didn't provide requested password
        }   
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // register default logger for convenience
    if (!defined('FIRELOGGER_NO_OUTPUT_HANDLER')) {
        ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
        //
        // Encoding handler
        //   * collects all log messages from all FireLogger instances
        //   * encodes them into HTTP headers
        //
        // see protocol specs at http://wiki.github.com/darwin/firelogger
        //
        function FireLoggerHandler($buffer) {
            global $registeredFireLoggers;

            // json_encode supports only UTF-8 encoded data
            function utfConvertor(&$value, &$key, $userdata = '') {
                if (gettype($value)==='string') {
                    $value = utf8_encode($value);
                }
                if (gettype($key)==='string') {
                    $key = utf8_encode($key);
                }
            }

            // source: http://cz2.php.net/manual/en/function.array-walk-recursive.php#63285
            function array_walk_recursive2(&$input, $funcname, $userdata = '') {
                if (!is_callable($funcname)) return false;
                if (!is_array($input)) return false;
                foreach ($input AS $key => $value) {
                    if (is_array($input[$key])) {
                        array_walk_recursive2($input[$key], $funcname, $userdata);
                    } else {
                        $saved_value = $value;
                        $saved_key = $key;
                        if (!empty($userdata)) {
                            $funcname($value, $key, $userdata);
                        } else {
                            $funcname($value, $key);
                        }
                        if ($value!=$saved_value || $saved_key!=$key) {
                            unset($input[$saved_key]);
                            $input[$key] = $value;
                        }
                    }
                }
                return true;
            }

            $logs = array();
            foreach ($registeredFireLoggers as $logger) {
                $logs = array_merge($logs, $logger->logs);
            }

            $output = array(
                'logs' => $logs
            );
        
            // final encoding
            $id = dechex(mt_rand(0, 0xFFFF)).dechex(mt_rand(0, 0xFFFF)); // mt_rand is not working with 0xFFFFFFFF
            array_walk_recursive2($output, 'utfConvertor'); // json_encode supports only UTF-8 encoded data!!!
            $json = json_encode($output);
            $res = str_split(base64_encode($json), 76); // RFC 2045
        
            foreach($res as $k=>$v) {
                header("FireLogger-$id-$k:$v");
            }
        
            return $buffer; // made no changes to the incomming buffer
        }
        
        if ($fireLoggerEnabled) ob_start('FireLoggerHandler'); // start output buffering (in case firelogger should be enabled)
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // register default logger for convenience
    if (!defined('FIRELOGGER_NO_DEFAULT_LOGGER')) {
        new FireLogger('php', 'background-color: #767ab6'); // register default firelogger with official PHP logo color :-)
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // shortcut functions for convenience
    if (!defined('FIRELOGGER_NO_CONFLICT')) {
        
        function flog(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }

        function fwarn(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            array_unshift($args, 'warning');
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }

        function ferror(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            array_unshift($args, 'error');
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }

        function finfo(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            array_unshift($args, 'info');
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }

        function fcritical(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            array_unshift($args, 'critical');
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // register global handler for uncaught exceptions
    if (!defined('FIRELOGGER_NO_EXCEPTION_HANDLER')) {
        function firelogger_exception_handler($exception) {
            global $registeredFireLoggers;
            $args = func_get_args();
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }
        $fireLoggerOldExceptionHandler = set_exception_handler('firelogger_exception_handler');
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // register global handler for errors
    if (!defined('FIRELOGGER_NO_ERROR_HANDLER')) {
        $errorFireLogger = new FireLogger('error', 'background-color: #f00');
        
        function firelogger_error_handler($errno, $errstr, $errfile, $errline) {
            global $errorFireLogger;
            // any ideas how to get string rep of $errno from PHP?
            $errors = array(
                1 => 'ERROR',
                2 => 'WARNING',
                4 => 'PARSE',
                8 => 'NOTICE',
                16 => 'CORE_ERROR',
                32 => 'CORE_WARNING',
                64 => 'COMPILE_ERROR',
                128 => 'COMPILE_WARNING',
                256 => 'USER_ERROR',
                512 => 'USER_WARNING',
                1024 => 'USER_NOTICE',
                2048 => 'STRICT',
                4096 => 'RECOVERABLE_ERROR',
                8192 => 'DEPRECATED',
                16384 => 'USER_DEPRECATED',
                30719 => 'ALL'
            );
            $level = 'critical';
            $no = isset($errors[$errno])?$errors[$errno]:'ERROR';
            call_user_func_array(array(&$errorFireLogger, 'log'), array($level, "$no: $errstr", new FireLoggerFileLine($errfile, $errline)));
        }
        $fireLoggerOldExceptionHandler = set_error_handler('firelogger_error_handler');
    }

?>