<?php

declare(strict_types=1);

namespace altay\proxypass\crypto;

use function bin2hex;
use function gmp_init;
use function gmp_strval;
use function hex2bin;
use function openssl_digest;
use function openssl_error_string;
use function openssl_pkey_derive;
use function str_pad;
use const STR_PAD_LEFT;

final class EncryptionUtils{

	private function __construct(){
		//NOOP
	}

	public static function generateSharedSecret(\OpenSSLAsymmetricKey $localPriv, \OpenSSLAsymmetricKey $remotePub) : \GMP{
		$secret = openssl_pkey_derive($remotePub, $localPriv, 48);
		if($secret === false){
			throw new \InvalidArgumentException("Failed to derive shared secret: " . openssl_error_string());
		}
		return gmp_init(bin2hex($secret), 16);
	}

	public static function generateKey(\GMP $secret, string $salt) : string{
		$key = openssl_digest($salt . hex2bin(str_pad(gmp_strval($secret, 16), 96, "0", STR_PAD_LEFT)), 'sha256', true);
		if($key === false){
			throw new \RuntimeException("openssl_digest() error: " . openssl_error_string());
		}
		return $key;
	}
}
