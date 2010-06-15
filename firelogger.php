<?php
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // FireLogger for PHP (server-side library)
    // http://firelogger.binaryage.com/php
    //
    // see test.php for sample usage
    //
    // protocol specs: http://wiki.github.com/darwin/firelogger
    //

    // some directives, you may define them before including firelogger.php
    if (!defined('FIRELOGGER_VERSION')) define('FIRELOGGER_VERSION', '0.2');
    if (!defined('FIRELOGGER_API_VERSION')) define('FIRELOGGER_API_VERSION', 1);
    if (!defined('FIRELOGGER_MAX_PICKLE_DEPTH')) define('FIRELOGGER_MAX_PICKLE_DEPTH', 10);
    // ... there is more scattered throught this source, hint: search for constants beginning with "FIRELOGGER_"
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // this class represents logger object
    // logger has name and you can ask him to perform logging for you like this:
    //
    //   $ajax = new FireLogger("ajax");
    //   $ajax->log("info", "hello from ajax logger");
    //   $ajax->log("have", "fun!");
    //
    //  you may also use shortcut helper functions to log into default logger
    //
    //    flog("Hello from PHP!");
    //    fwarn("Warning, %s alert!", "gertruda");
    //    ...
    //
    class FireLogger {
        // global state kept under FireLogger "namespace"
        public static $enabled = true; // enabled by default, but see the code executed after class
        public static $counter = 0; // an aid for ordering log records on client
        public static $loggers = array(); // the array of all instantiated fire-loggers during request
        public static $default; // points to default logger
        public static $error; // points to error logger (for errors trigerred by PHP)
        public static $oldErrorHandler;
        public static $oldExceptionHandler;
        public static $clientVersion = '?';
        public static $recommendedClientVersion = '0.8';
        
        // logger instance data
        public $name;  // [optional] logger name
        public $style; // [optional] CSS snippet for logger icon in FireLogger console
        public $logs = array(); // array of captured log records, this will be encoded into headers during 
        public $levels = array('debug', 'warning', 'info', 'error', 'critical'); // well-known log levels (originated in Python)

        //------------------------------------------------------------------------------------------------------
        function __construct($name='logger', $style=null) {
            $this->name = $name;
            $this->style = $style;
            FireLogger::$loggers[] = $this;
        }
        //------------------------------------------------------------------------------------------------------
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
        //------------------------------------------------------------------------------------------------------
        private function fix_eval_in_file_line($file, $line) {
            // special hack for eval'd code:
            // "/Users/darwin/code/firelogger.php/test.php(41) : eval()'d code 21"
            if (preg_match('/(.*)\((\d+)\) : eval/', $file, $matches)>0) {
                $file = $matches[1];
                $line = $matches[2];
            }
            return array($file, $line);
        }
        //------------------------------------------------------------------------------------------------------
        private function extract_file_line($trace) {
            while (count($trace)>0 && !array_key_exists('file', $trace[0])) array_shift($trace);
            $thisFile = $trace[0]['file'];
            while (count($trace)>0 && (array_key_exists('file', $trace[0]) && $trace[0]['file']==$thisFile)) array_shift($trace);
            while (count($trace)>0 && !array_key_exists('file', $trace[0])) array_shift($trace);

            if (count($trace)==0) return array("?", "0");
            $file = $trace[0]['file'];
            $line = $trace[0]['line'];
            return $this->fix_eval_in_file_line($file, $line);
        }
        //------------------------------------------------------------------------------------------------------
        private function extract_trace($trace) {
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
            return array($t, $f);
        }
        //------------------------------------------------------------------------------------------------------
        function log(/*level, fmt, obj1, obj2, ...*/) {
            if (!FireLogger::$enabled) return; // no-op
            
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
                'order' => FireLogger::$counter++, // PHP is really fast, timestamp has insufficient resolution for log records ordering
                'time' => gmdate('H:i:s', (int)$time).'.'.substr(fmod($time, 1.0), 2, 3), // '23:53:13.396'
                'template' => $fmt,
                'message' => $fmt // TODO: render reasonable plain text message
            );
            if ($this->style) $item['style'] = $this->style;
            if (count($args)>0 && is_object($args[0]) && is_a($args[0], 'Exception')) { // is_object check prevents http://pear.php.net/bugs/bug.php?id=2975
                // exception with backtrace
                $e = $args[0];
                $trace = $e->getTrace();
                $ti = $this->extract_trace($trace);
                $item['exc_info'] = array(
                    $e->getMessage(),
                    $e->getFile(),
                    $ti[0]
                );
                $item['exc_frames'] = $ti[1];
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
                    // override backtrace in case we've got passed FireLoggerBacktrace
                    if ($arg && is_object($arg) && is_a($arg, 'FireLoggerBacktrace')) { 
                        $ti = $this->extract_trace($arg->trace);
                        $item['exc_info'] = array(
                            '',
                            '',
                            $ti[0]
                        );
                        $item['exc_frames'] = $ti[1];
                        continue; // do not process this arg
                    }
                    $data[] = $this->pickle($arg);
                }
                $item['args'] = $data;
            }
            
            $this->logs[] = $item;
        }
        //------------------------------------------------------------------------------------------------------
        static function firelogger_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
            if (!defined('FIRELOGGER_NO_ERROR_FILTERING')) {
                // It is important to remember that the standard PHP error handler is completely bypassed. 
                // error_reporting() settings will have no effect and your error handler will be called regardless - 
                // however you are still able to read the current value of error_reporting and act appropriately. 
                // Of particular note is that this value will be 0 if the statement that caused the error was 
                // prepended by the @ error-control operator.
                $currentLevel = ini_get('error_reporting');
                if (!($errno&$currentLevel)) return;
            }
            
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
            call_user_func_array(array(&FireLogger::$error, 'log'), array($level, "$no: $errstr", new FireLoggerFileLine($errfile, $errline), new FireLoggerBacktrace(debug_backtrace())));
        }
        //------------------------------------------------------------------------------------------------------
        static function firelogger_exception_handler($exception) {
            $args = func_get_args();
            call_user_func_array(array(&FireLogger::$default, 'log'), $args);
        }
        //------------------------------------------------------------------------------------------------------
        //
        // Encoding handler
        //   * collects all log messages from all FireLogger instances
        //   * encodes them into HTTP headers
        //
        // see protocol specs at http://wiki.github.com/darwin/firelogger
        //
        static function handler($buffer) {
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
            foreach (FireLogger::$loggers as $logger) {
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
        
            return $buffer; // made no changes to the incoming buffer
        }
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // helper class for passing file/line override into log methods
    class FireLoggerFileLine {
        public $file;
        public $line;
        function __construct($file, $line) {
            $this->file = $file;
            $this->line = $line;
        }
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // helper class for passing backtrace override into log methods
    class FireLoggerBacktrace {
        public $trace;
        function __construct($trace) {
            $this->trace = $trace;
        }
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // decide if firelogger should be enabled
    if (!defined('FIRELOGGER_NO_VERSION_CHECK')) {
        if (!isset($_SERVER['HTTP_X_FIRELOGGER'])) {
            FireLogger::$enabled = false;
        } else {
            FireLogger::$clientVersion = $_SERVER['HTTP_X_FIRELOGGER'];
            if (FireLogger::$clientVersion!=FireLogger::$recommendedClientVersion) {
                error_log("FireLogger for PHP (v".FIRELOGGER_VERSION.") works best with FireLogger extension of version ".FireLogger::$recommendedClientVersion.". You are currently using extension v".FireLogger::$clientVersion.". Please upgrade your Firefox extension: http://firelogger.binaryage.com/php");
            }
        }
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // test if firelogger password matches
    if (!defined('FIRELOGGER_NO_PASSWORD_CHECK') && defined('FIRELOGGER_PASSWORD') && FireLogger::$enabled) {
        if (isset($_SERVER['HTTP_X_FIRELOGGERAUTH'])) {
            $clientHash = $_SERVER['HTTP_X_FIRELOGGERAUTH'];
            $serverHash = md5("#FireLoggerPassword#".FIRELOGGER_PASSWORD."#");
            if ($clientHash!==$serverHash) { // passwords do not match
                FireLogger::$enabled = false;
                trigger_error("FireLogger password do not match. Have you specified correct password FireLogger extension?");
            }
        } else {
            FireLogger::$enabled = false; // silently disable firelogger in case client didn't provide requested password
        }   
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // register default logger for convenience
    if (!defined('FIRELOGGER_NO_OUTPUT_HANDLER')) {
        if (FireLogger::$enabled) ob_start('FireLogger::handler'); // start output buffering (in case firelogger should be enabled)
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // register default logger for convenience
    if (!defined('FIRELOGGER_NO_DEFAULT_LOGGER')) {
        FireLogger::$default = new FireLogger('php', 'background-color: #767ab6'); // register default firelogger with official PHP logo color :-)
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // shortcut functions for convenience
    if (!defined('FIRELOGGER_NO_CONFLICT')) {
        function flog(/*fmt, obj1, obj2, ...*/) {
            $args = func_get_args();
            call_user_func_array(array(&FireLogger::$default, 'log'), $args);
        }
        function fwarn(/*fmt, obj1, obj2, ...*/) {
            $args = func_get_args();
            array_unshift($args, 'warning');
            call_user_func_array(array(&FireLogger::$default, 'log'), $args);
        }
        function ferror(/*fmt, obj1, obj2, ...*/) {
            $args = func_get_args();
            array_unshift($args, 'error');
            call_user_func_array(array(&FireLogger::$default, 'log'), $args);
        }
        function finfo(/*fmt, obj1, obj2, ...*/) {
            $args = func_get_args();
            array_unshift($args, 'info');
            call_user_func_array(array(&FireLogger::$default, 'log'), $args);
        }
        function fcritical(/*fmt, obj1, obj2, ...*/) {
            $args = func_get_args();
            array_unshift($args, 'critical');
            call_user_func_array(array(&FireLogger::$default, 'log'), $args);
        }
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // register global handler for uncaught exceptions
    if (!defined('FIRELOGGER_NO_EXCEPTION_HANDLER')) {
        FireLogger::$oldExceptionHandler = set_exception_handler('FireLogger::firelogger_exception_handler');
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // register global handler for errors
    if (!defined('FIRELOGGER_NO_ERROR_HANDLER')) {
        FireLogger::$error = new FireLogger('error', 'background-color: #f00');
        FireLogger::$oldErrorHandler = set_error_handler('FireLogger::firelogger_error_handler');
    }

?>