<?php

declare(strict_types=1);

namespace altay\proxypass\utils;

use function date;
use function fwrite;
use function strtoupper;
use const PHP_EOL;
use const STDOUT;

final class ConsoleLogger extends \SimpleLogger{

	public function __construct(
		private bool $debug = false
	){}

	public function log($level, $message){
		if($level === \LogLevel::DEBUG && !$this->debug){
			return;
		}
		fwrite(STDOUT, "[" . date("H:i:s") . "] [" . strtoupper((string) $level) . "] " . $message . PHP_EOL);
	}

	public function logException(\Throwable $e, $trace = null){
		$this->critical($e->getMessage());
		fwrite(STDOUT, $e->getTraceAsString() . PHP_EOL);
	}
}
