<?php

/**
 * Expected result is for all four responses to be echoed
 * to console (two out and two nested by callbacks).
 */

set_include_path(get_include_path() . PATH_SEPARATOR . realpath('../src'));
require_once 'Hasty/Loader.php';
$loader = new \Hasty\Loader;
$loader->register();

$pool = new \Hasty\Pool;
$r1 = new \Hasty\Request('http://pear.phpunit.de');
$r2 = new \Hasty\Request('http://pear.php.net');

// The complete event can also be referenced as \Hasty\Request::EVENT_COMPLETE

$r1->on('complete', function($response, $pool) {
    echo $response->getContent(), "\n\n\n====================\n\n\n";
    $r3 = new \Hasty\Request('http://pear.survivethedeepend.com');
    $r3->on('complete', function($response, $pool) {
        echo $response->getContent(), "\n\n\n====================\n\n\n";
    });
    $pool->attach($r3);
});

$r2->on('complete', function($response, $pool) {
    echo $response->getContent(), "\n\n\n====================\n\n\n";
    $r4 = new \Hasty\Request('http://saucelabs.github.com/pear/');
    $r4->on('complete', function($response, $pool) {
        echo $response->getContent(), "\n\n\n====================\n\n\n";
    });
    $pool->attach($r4);
});

$responses = $pool->attach($r1)->attach($r2)->run();