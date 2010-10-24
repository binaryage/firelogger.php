<?php
require "../firelogger.php";
?><!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
   "http://www.w3.org/TR/html4/loose.dtd">

<html lang="en">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <title>FireLogger</title>
    </head>
    <body>
        <h1>FireLogger for PHP - backtrace test</h1>
<?php


function first($arg1, $arg2)
{
	second(TRUE, FALSE);
}



function second($arg1, $arg2)
{
	third(array(1, 2, 3));
}


function third($arg1)
{
	throw new Exception("this is a nasty exception, catch it!");
}


first(10, 'any string');


?>

    </body>
</html>