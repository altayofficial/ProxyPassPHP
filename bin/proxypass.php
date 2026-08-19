<?php

declare(strict_types=1);

use altay\proxypass\ProxyPass;
use altay\proxypass\utils\ConsoleLogger;

require dirname(__DIR__) . "/vendor/autoload.php";

$logger = new ConsoleLogger(in_array("--debug", $argv, true));
$proxy = new ProxyPass(getcwd(), $logger);

if(function_exists("pcntl_async_signals")){
	pcntl_async_signals(true);
	pcntl_signal(SIGINT, static fn() => $proxy->shutdown());
	pcntl_signal(SIGTERM, static fn() => $proxy->shutdown());
}

try{
	$proxy->boot();
}catch(Throwable $e){
	$logger->logException($e);
	exit(1);
}
