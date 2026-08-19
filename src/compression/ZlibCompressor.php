<?php

declare(strict_types=1);

namespace altay\proxypass\compression;

use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use function strlen;
use function zlib_decode;
use function zlib_encode;
use const ZLIB_ENCODING_RAW;

final class ZlibCompressor implements Compressor{
	public const DEFAULT_LEVEL = 7;
	public const DEFAULT_MAX_DECOMPRESSION_SIZE = 8 * 1024 * 1024;

	public function __construct(
		private ?int $minCompressionSize,
		private int $level = self::DEFAULT_LEVEL,
		private int $maxDecompressionSize = self::DEFAULT_MAX_DECOMPRESSION_SIZE
	){}

	public function getNetworkId() : int{
		return CompressionAlgorithm::ZLIB;
	}

	public function getCompressionThreshold() : ?int{
		return $this->minCompressionSize;
	}

	public function decompress(string $payload) : string{
		$result = @zlib_decode($payload, $this->maxDecompressionSize);
		if($result === false){
			throw new DecompressionException("Failed to decompress data");
		}
		return $result;
	}

	public function compress(string $payload) : string{
		$compressible = $this->minCompressionSize !== null && strlen($payload) >= $this->minCompressionSize;
		$result = zlib_encode($payload, ZLIB_ENCODING_RAW, $compressible ? $this->level : 0);
		if($result === false){
			throw new \RuntimeException("ZLIB compression failed");
		}
		return $result;
	}
}
