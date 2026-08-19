<?php

declare(strict_types=1);

namespace altay\proxypass;

final class Address{

	public function __construct(
		private string $host,
		private int $port
	){}

	public function getHost() : string{
		return $this->host;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function __toString() : string{
		return $this->host . ":" . $this->port;
	}
}
