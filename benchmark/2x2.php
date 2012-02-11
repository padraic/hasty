<?php

$iterations = 20; // number of times to run to benchmark
$sleep = 180; // number of seconds to sleep between each tested strategy



$hasty_time_cumulative = 0;
$hasty2_time_cumulative = 0;
$zend_time_cumulative = 0;

set_include_path(realpath('../src') . PATH_SEPARATOR . get_include_path());
require_once 'Hasty/Loader.php';
$loader = new \Hasty\Loader;
$loader->register(true);

$time_start_hasty = microtime(true);
for ($i=0; $i < $iterations; $i++) { 
    $pool = new \Hasty\Pool;
    $r1 = new \Hasty\Request('http://pear.phpunit.de');
    $r2 = new \Hasty\Request('http://pear.php.net');
    $r3 = new \Hasty\Request('http://pear.survivethedeepend.com');
    $r4 = new \Hasty\Request('http://saucelabs.github.com/pear/');

    $r1->on('complete', function($response, $pool) {
        echo '.';
    });
    $r2->on('complete', function($response, $pool) {
        echo '.';
    });
    $r3->on('complete', function($response, $pool) {
        echo '.';
    });
    $r4->on('complete', function($response, $pool) {
        echo '.';
    });

    $responses = $pool->attach($r1)->attach($r2)->attach($r3)->attach($r4)->run();
}

$hasty_time_cumulative = microtime(true) - $time_start_hasty;
echo "\nAverage Hasty (Batched): ", round($hasty_time_cumulative/$iterations, 2), "s\n\n";
sleep($sleep);

$time_start_hasty2 = microtime(true);
for ($j=0; $j < $iterations; $j++) { 
    $pool = new \Hasty\Pool;
    $r1 = new \Hasty\Request('http://pear.phpunit.de');
    $r2 = new \Hasty\Request('http://pear.php.net');

    $r1->on('complete', function($response, $pool) {
        echo '.';
        $r3 = new \Hasty\Request('http://pear.survivethedeepend.com');
        $r3->on('complete', function($response, $pool) {
            echo '.';
        });
        $pool->attach($r3);
    });

    $r2->on('complete', function($response, $pool) {
        echo '.';
        $r4 = new \Hasty\Request('http://saucelabs.github.com/pear/');
        $r4->on('complete', function($response, $pool) {
            echo '.';
        });
        $pool->attach($r4);
    });

    $responses = $pool->attach($r1)->attach($r2)->run();
}

$hasty_time_cumulative2 = microtime(true) - $time_start_hasty2;
echo "\nAverage Hasty (Nested): ", round($hasty_time_cumulative2/$iterations, 2), "s\n\n";
sleep($sleep);

/**
 * Optionally set ZF include path to start of path stack
 */
set_include_path(realpath('../../zf/library') . PATH_SEPARATOR . get_include_path());
function autoload($class) {
    include_once str_replace('_', '/', $class) . '.php';
}
spl_autoload_register('autoload', true, true);

$time_start_zend = microtime(true);

for ($k=0; $k < $iterations; $k++) { 
    $client = new Zend_Http_Client(null, array('httpversion'=>'1.0', 'timeout'=>30));
    $client->resetParameters()->setUri('http://pear.phpunit.de')->request(); echo '.';
    $client->resetParameters()->setUri('http://pear.php.net')->request(); echo '.';
    $client->resetParameters()->setUri('http://pear.survivethedeepend.com')->request(); echo '.';
    $client->resetParameters()->setUri('http://saucelabs.github.com/pear/')->request(); echo '.';
}

$zend_time_cumulative = microtime(true) - $time_start_zend;
echo "\nAverage Zend_Http_Client (1.10): ", round($zend_time_cumulative/$iterations, 2), "s\n\n";

echo 'Hasty (Batched) was ' ,round(($zend_time_cumulative/$hasty_time_cumulative), 2) , ' times faster than ZF 1.10', "\n";
echo 'Hasty (Nested) was ' ,round(($zend_time_cumulative/$hasty_time_cumulative2), 2) , ' times faster than ZF 1.10', "\n";