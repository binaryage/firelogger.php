<?php
require "../firelogger.php";
?>
<!DOCTYPE html>
<html>
<head>
    <title>FireLogger IFRAME test (frame)</title>
</head>
<body>
    <h1>FireLogger inner FRAME</h1>
<?php
      $code = <<<'EOD'

$frame = new FireLogger("iframe");
$frame->log("Hello from IFRAME!");

EOD;
    echo "<pre>$code</pre>";
    eval($code);
?>  

</body>
</html>