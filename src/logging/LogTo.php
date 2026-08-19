<?php

declare(strict_types=1);

namespace altay\proxypass\logging;

enum LogTo : string{
	case CONSOLE = "console";
	case FILE = "file";
	case BOTH = "both";

	public function logsToConsole() : bool{
		return $this === self::CONSOLE || $this === self::BOTH;
	}

	public function logsToFile() : bool{
		return $this === self::FILE || $this === self::BOTH;
	}
}
