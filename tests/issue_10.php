<?php
require "../firelogger.php";
?><!DOCTYPE html>
<html lang="en">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <title>FireLogger</title>
    </head>
    <body>
        <h1>FireLogger for PHP - <a href="http://github.com/darwin/firelogger.php/issues#issue/10">Issue #10</a> test</h1>
<?php
    $code = <<<'EOD'
flog("Text %text %text %text");
EOD;

    echo "<pre>$code</pre>";
    eval($code);
?>
    </body>
</html>