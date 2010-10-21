<?php
require "../firelogger.php";
?>
<!DOCTYPE html>
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

    <iframe src="iframe-frame.php" width="500" height="200"></iframe>
</body>
</html>