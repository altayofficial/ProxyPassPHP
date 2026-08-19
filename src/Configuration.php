<?php

declare(strict_types=1);

namespace altay\proxypass;

use altay\proxypass\logging\LogTo;
use function array_fill_keys;
use function is_array;
use function is_string;
use function yaml_parse_file;

final class Configuration{

	/**
	 * @param array<string, true> $ignoredPackets
	 * @phpstan-param array<string, true> $ignoredPackets
	 */
	private function __construct(
		private Address $proxy,
		private Address $destination,
		private int $maxClients,
		private bool $loggingPackets,
		private LogTo $logTo,
		private array $ignoredPackets
	){}

	public static function load(string $path) : self{
		$data = yaml_parse_file($path);
		if(!is_array($data)){
			throw new \InvalidArgumentException("Failed to parse configuration file $path");
		}

		$ignored = [];
		foreach($data["ignored-packets"] ?? [] as $name){
			if(is_string($name)){
				$ignored[] = $name;
			}
		}

		return new self(
			self::parseAddress($data, "proxy"),
			self::parseAddress($data, "destination"),
			(int) ($data["max-clients"] ?? 0),
			(bool) ($data["log-packets"] ?? false),
			LogTo::tryFrom((string) ($data["log-to"] ?? "file")) ?? LogTo::FILE,
			array_fill_keys($ignored, true)
		);
	}

	/**
	 * @param mixed[] $data
	 */
	private static function parseAddress(array $data, string $key) : Address{
		$address = $data[$key] ?? null;
		if(!is_array($address) || !isset($address["host"], $address["port"])){
			throw new \InvalidArgumentException("Missing or malformed \"$key\" address in configuration");
		}
		return new Address((string) $address["host"], (int) $address["port"]);
	}

	public function getProxy() : Address{
		return $this->proxy;
	}

	public function getDestination() : Address{
		return $this->destination;
	}

	public function getMaxClients() : int{
		return $this->maxClients;
	}

	public function isLoggingPackets() : bool{
		return $this->loggingPackets;
	}

	public function getLogTo() : LogTo{
		return $this->logTo;
	}

	public function isIgnoredPacket(string $packetName) : bool{
		return isset($this->ignoredPackets[$packetName]);
	}
}
