<?php
require "../firelogger.php";

if ($_SERVER['REQUEST_METHOD']=='POST') {
    flog("Got POST request!", $_POST);
?>
    <b>Hello World! #<?php echo $_POST["counter"] ?></b>
<?
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>FireLogger AJAX test</title>
    <script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.3/jquery.min.js"></script>
</head>
<body>
    <h1>FireLogger AJAX test</h1>
  
<?php
    $code = <<<'EOD'
flog("Hello from main HTML!");
EOD;

  echo "<pre>$code</pre>";
  eval($code);
?>  

    <button id="button">Click me to do AJAX!</button>
    <div id="info"></div>
    <div id="result"></div>

    <script type="text/javascript" charset="utf-8">
        $(function() {
            var counter = 0;
            $('#button').bind('click', function() {
                counter++;
                $('#info').html('Sent AJAX request #'+counter);
            
                $.post('ajax.php', { counter: counter }, function(data) {
                    $('#result').html('Server response:<br>'+data);
                });
            });
        });
    </script>

</body>
</html>