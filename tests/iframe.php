<?php
require "../firelogger.php";

///////////////////////////////////////////////////////////////////////////
// IFRAME content
if ($_GET["frame"]) {
?><!DOCTYPE html>
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
<?php
    exit;
}

///////////////////////////////////////////////////////////////////////////
// main content
?><!DOCTYPE html>
<html>
<head>
    <title>FireLogger IFRAME test</title>
</head>
<body>
    <h1>FireLogger IFRAME test</h1>
  
<?php
    $code = <<<'EOD'
flog("Hello from main HTML!");
EOD;

  echo "<pre>$code</pre>";
  eval($code);
?>  

    <iframe src="iframe.php?frame=1" width="500" height="200"></iframe>
</body>
</html>