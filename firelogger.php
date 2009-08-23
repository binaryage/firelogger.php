<?php
    // init constants, you may define them before including firelog.php
    if (!defined('FIRELOGGER_VERSION')) define('FIRELOGGER_VERSION', '0.1');
    if (!defined('FIRELOGGER_API_VERSION')) define('FIRELOGGER_API_VERSION', 1);
    if (!defined('FIRELOGGER_MAX_PICKLE_DEPTH')) define('FIRELOGGER_MAX_PICKLE_DEPTH', 10);
    if (!defined('FIRELOGGER_PASSWORD')) define('FIRELOGGER_PASSWORD', '');

    $registeredFireLoggers = array(); // the array of all instantiated fire-loggers during request
    
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
    ob_start('FireLoggerHandler'); // start output buffering
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    class FireLogger {
        public $name = 'root';
        public $logs = array();
        var $levels = array('debug', 'warning', 'info', 'error', 'critical');
        
        function __construct(/*$name*/) {
            global $registeredFireLoggers;
            $numArgs = func_num_args();
            if ($numArgs>=1) {
                $name = func_get_arg(0);
                if ($name) $this->name = $name;
            }
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
        
        private function extract_file_line($trace) {
            while (count($trace)>0 && !array_key_exists('file', $trace[0])) array_shift($trace);
            $thisFile = $trace[0]['file'];
            while (count($trace)>0 && $trace[0]['file']==$thisFile) array_shift($trace);
            
            $file = $trace[0]['file'];
            $line = $trace[0]['line'];
            
            // special hack for eval'd code:
            // "/Users/darwin/code/firexxx/test.php(41) : eval()'d code"
            if (preg_match('/(.*)\((\d+)\) : eval/', $file, $matches)>0) {
                $file = $matches[1];
                $line = $matches[2];
            }
            return array($file, $line);
        }
        
        function log(/*level, fmt, obj1, obj2, ...*/) {
            $args = func_get_args();
            $fmt = '';
            $level = 'debug';
            if (gettype($args[0])==='string' && in_array($args[0], $this->levels)) {
                $level = array_shift($args);
            }
            if (gettype($args[0])==='string') {
                $fmt = array_shift($args);
            }

            list($usec, $sec) = explode(' ', microtime());
            $item = array(
                'name' => $this->name,
                'args' => array(),
                'level' => $level,
                'timestamp' => $sec,
                'time' => gmdate('H:i:s', $sec).'.'.substr($usec, 2, 3), // '23:53:13.396'
                'template' => $fmt,
                'message' => $fmt
            );
            if (count($args)>0 && is_object($args[0]) && is_a($args[0], 'Exception')) { // is_object check prevents http://pear.php.net/bugs/bug.php?id=2975
                // exception with backtrace
                $e = $args[0];
                $trace = $e->getTrace();
                $t = array();
                $f = array();
                foreach ($trace as $frame) {
                    $t[] = array(
                        @$frame['file'],
                        @$frame['line'],
                        @$frame['class'].@$frame['type'].@$frame['function'],
                        @$frame['object']
                    );
                    $f[] = @$frame['args'];
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
                foreach ($args as $arg) {
                    $data[] = $this->pickle($arg);
                }
                $item['args'] = $data;
                $item['pathname'] = $file;
                $item['lineno'] = $line;
            }
            
            $this->logs[] = $item;
        }
    }
    
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // shortcut functions for convenience
    if (!defined('FIRELOGGER_NO_CONFLICT')) {
        new FireLogger(); // register default fire logger
        
        function flog(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }

        function fwarn(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            array_unshift($args, "warning");
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }

        function ferror(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            array_unshift($args, "error");
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }

        function finfo(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            array_unshift($args, "info");
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }

        function fcritical(/*fmt, obj1, obj2, ...*/) {
            global $registeredFireLoggers;
            $args = func_get_args();
            array_unshift($args, "critical");
            call_user_func_array(array(&$registeredFireLoggers[0], 'log'), $args);
        }
    }
?>