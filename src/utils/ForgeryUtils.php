<?php

declare(strict_types=1);

namespace altay\proxypass\utils;

use altay\proxypass\crypto\JwtUtils;
use altay\proxypass\network\session\IdentityData;
use function base64_encode;
use function time;

final class ForgeryUtils{
	public const MOJANG_AUDIENCE = "api://auth-minecraft-services/multiplayer";

	private const TOKEN_LIFETIME = 86400;

	private function __construct(){
		//NOOP
	}

	public static function forgeAuthToken(\OpenSSLAsymmetricKey $keyPair, IdentityData $identity) : string{
		$publicKey = base64_encode(JwtUtils::emitDerPublicKey($keyPair));
		$timestamp = time();

		return JwtUtils::create(
			[
				"alg" => "ES384",
				"x5u" => $publicKey
			],
			[
				"aud" => self::MOJANG_AUDIENCE,
				"iss" => "self",
				"nbf" => $timestamp - 1,
				"exp" => $timestamp + self::TOKEN_LIFETIME,
				"iat" => $timestamp,
				"cpk" => $publicKey,
				"leguuid" => $identity->getUuid(),
				"xname" => $identity->getDisplayName(),
				"mid" => $identity->getMinecraftId()
			],
			$keyPair
		);
	}

	/**
	 * @param mixed[] $clientData
	 */
	public static function forgeClientData(\OpenSSLAsymmetricKey $keyPair, array $clientData) : string{
		return JwtUtils::create(
			[
				"alg" => "ES384",
				"x5u" => base64_encode(JwtUtils::emitDerPublicKey($keyPair))
			],
			$clientData,
			$keyPair
		);
	}
}
