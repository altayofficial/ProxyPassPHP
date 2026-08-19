<?php

declare(strict_types=1);

namespace altay\proxypass\crypto;

use Crypto\Cipher;
use pmmp\encoding\LE;
use function bin2hex;
use function openssl_digest;
use function openssl_error_string;
use function strlen;
use function substr;

final class EncryptionContext{
	private const CHECKSUM_ALGO = "sha256";

	private Cipher $decryptCipher;
	private int $decryptCounter = 0;

	private Cipher $encryptCipher;
	private int $encryptCounter = 0;

	public function __construct(
		private string $key,
		string $algorithm,
		string $iv
	){
		$this->decryptCipher = new Cipher($algorithm);
		$this->decryptCipher->decryptInit($this->key, $iv);

		$this->encryptCipher = new Cipher($algorithm);
		$this->encryptCipher->encryptInit($this->key, $iv);
	}

	public static function fakeGCM(string $encryptionKey) : self{
		return new self($encryptionKey, "AES-256-CTR", substr($encryptionKey, 0, 12) . "\x00\x00\x00\x02");
	}

	public static function cfb8(string $encryptionKey) : self{
		return new self($encryptionKey, "AES-256-CFB8", substr($encryptionKey, 0, 16));
	}

	/**
	 * @throws DecryptionException
	 */
	public function decrypt(string $encrypted) : string{
		if(strlen($encrypted) < 9){
			throw new DecryptionException("Payload is too short");
		}
		$decrypted = $this->decryptCipher->decryptUpdate($encrypted);
		$payload = substr($decrypted, 0, -8);

		$packetCounter = $this->decryptCounter++;
		if(($expected = $this->calculateChecksum($packetCounter, $payload)) !== ($actual = substr($decrypted, -8))){
			throw new DecryptionException("Encrypted packet $packetCounter has invalid checksum (expected " . bin2hex($expected) . ", got " . bin2hex($actual) . ")");
		}

		return $payload;
	}

	public function encrypt(string $payload) : string{
		return $this->encryptCipher->encryptUpdate($payload . $this->calculateChecksum($this->encryptCounter++, $payload));
	}

	private function calculateChecksum(int $counter, string $payload) : string{
		$hash = openssl_digest(LE::packUnsignedLong($counter) . $payload . $this->key, self::CHECKSUM_ALGO, true);
		if($hash === false){
			throw new \RuntimeException("openssl_digest() error: " . openssl_error_string());
		}
		return substr($hash, 0, 8);
	}
}
