<?php

declare(strict_types=1);

namespace altay\proxypass\compression;

interface Compressor{

	public function getNetworkId() : int;

	/**
	 * Returns the minimum payload size which is worth compressing, or null if nothing should be compressed.
	 */
	public function getCompressionThreshold() : ?int;

	/**
	 * @throws DecompressionException
	 */
	public function decompress(string $payload) : string;

	public function compress(string $payload) : string;
}
