<?php
require "../firelogger.php";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
   "http://www.w3.org/TR/html4/loose.dtd">

<html lang="en">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <title>FireLogger</title>
    </head>
    <body>
        <h1>FireLogger for PHP</h1>
<?php
    $code = <<<'EOD'

flog("Hello from PHP!");

flog("Expansion %s", 1);
flog("Expansion %s", "string");
flog("Expansion %s", array(1,2,3,4,5));
flog("Expansion %s", array("a" => 1, "b" => 2, "c" => 3));

flog("Unbound args", 1, 2, 3, 4, 5);
flog(1, "no formating string");

flog("debug", "DEBUG log level");
flog("info", "INFO log level");
flog("error", "ERROR log level");
flog("warning", "LOG level");
flog("critical", "CRITICAL log level");

flog("Complex structure %o", $_SERVER);
flog("Complex object structure %o", (object) array('item1' => new ArrayObject($_SERVER), 'item2' => new SplFileInfo(__FILE__)));
flog("Global scope!", $GLOBALS);

$api = new FireLogger("api");
$api->log("info", "hello from api logger");
$api->log("have", "fun!");

$a = array();
$a["bad_index"]; // should give notice!

try {
    throw new Exception("this is a nasty exception, catch it!");
} catch (Exception $e) {
    flog($e);
}

flog("1.timing test - this must display after nasty exception");
$api->log("2.timing test - this must display after nasty exception");

throw new Exception("this exception is caught automagically because firelogger installs set_exception_handler");

flog("info", "you should not see this!");


EOD;

    echo "<pre>$code</pre>";
    
    eval($code);
?>
    </body>
</html>