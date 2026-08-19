<?php

declare(strict_types=1);

namespace altay\proxypass\crypto;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function chr;
use function count;
use function explode;
use function is_array;
use function json_decode;
use function json_encode;
use function json_last_error_msg;
use function ltrim;
use function openssl_error_string;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function openssl_pkey_new;
use function openssl_sign;
use function ord;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_pad;
use function str_repeat;
use function str_replace;
use function strlen;
use function strtr;
use function substr;
use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_KEYTYPE_EC;
use const STR_PAD_LEFT;

final class JwtUtils{
	public const BEDROCK_SIGNING_KEY_CURVE_NAME = "secp384r1";

	private const ASN1_INTEGER_TAG = "\x02";
	private const ASN1_SEQUENCE_TAG = "\x30";

	private const SIGNATURE_PART_LENGTH = 48;
	private const SIGNATURE_ALGORITHM = OPENSSL_ALGO_SHA384;

	private function __construct(){
		//NOOP
	}

	public static function generateKeyPair() : \OpenSSLAsymmetricKey{
		$key = openssl_pkey_new([
			"private_key_type" => OPENSSL_KEYTYPE_EC,
			"curve_name" => self::BEDROCK_SIGNING_KEY_CURVE_NAME
		]);
		if($key === false){
			throw new JwtException("Failed to generate a key pair: " . openssl_error_string());
		}
		return $key;
	}

	/**
	 * @return string[]
	 * @phpstan-return array{string, string, string}
	 */
	public static function split(string $jwt) : array{
		$parts = explode(".", $jwt, limit: 4);
		if(count($parts) !== 3){
			throw new JwtException("Expected exactly 3 JWT parts delimited by a period");
		}
		return [$parts[0], $parts[1], $parts[2]];
	}

	/**
	 * @return mixed[]
	 * @phpstan-return array{array<string, mixed>, array<string, mixed>, string}
	 */
	public static function parse(string $token) : array{
		[$headerPart, $bodyPart, $signaturePart] = self::split($token);

		$header = json_decode(self::b64UrlDecode($headerPart), true);
		if(!is_array($header)){
			throw new JwtException("Failed to decode JWT header JSON: " . json_last_error_msg());
		}
		$body = json_decode(self::b64UrlDecode($bodyPart), true);
		if(!is_array($body)){
			throw new JwtException("Failed to decode JWT payload JSON: " . json_last_error_msg());
		}
		return [$header, $body, self::b64UrlDecode($signaturePart)];
	}

	/**
	 * @param mixed[] $header
	 * @param mixed[] $claims
	 */
	public static function create(array $header, array $claims, \OpenSSLAsymmetricKey $signingKey) : string{
		$body = self::b64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)) . "." . self::b64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

		if(!openssl_sign($body, $derSignature, $signingKey, self::SIGNATURE_ALGORITHM)){
			throw new JwtException("Failed to sign JWT: " . openssl_error_string());
		}

		return $body . "." . self::b64UrlEncode(self::rawSignatureFromDer($derSignature));
	}

	public static function b64UrlEncode(string $str) : string{
		return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
	}

	public static function b64UrlDecode(string $str) : string{
		if(($len = strlen($str) % 4) !== 0){
			$str .= str_repeat('=', 4 - $len);
		}
		$decoded = base64_decode(strtr($str, '-_', '+/'), true);
		if($decoded === false){
			throw new JwtException("Malformed base64url encoded payload could not be decoded");
		}
		return $decoded;
	}

	public static function emitDerPublicKey(\OpenSSLAsymmetricKey $key) : string{
		$details = openssl_pkey_get_details($key);
		if($details === false){
			throw new JwtException("Failed to get details from the given OpenSSL key");
		}
		$pemKey = $details['key'];
		if(preg_match("@^-----BEGIN[A-Z\d ]+PUBLIC KEY-----\n([A-Za-z\d+/\n]+)\n-----END[A-Z\d ]+PUBLIC KEY-----\n$@", $pemKey, $matches) === 1){
			$derKey = base64_decode(str_replace("\n", "", $matches[1]), true);
			if($derKey !== false){
				return $derKey;
			}
		}
		throw new JwtException("OpenSSL key contains an invalid public key");
	}

	public static function parseDerPublicKey(string $derKey) : \OpenSSLAsymmetricKey{
		$key = openssl_pkey_get_public(self::derPublicKeyToPem($derKey));
		if($key === false){
			throw new JwtException("OpenSSL failed to parse key: " . openssl_error_string());
		}
		return $key;
	}

	public static function derPublicKeyToPem(string $derKey) : string{
		return sprintf("-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n", base64_encode($derKey));
	}

	private static function rawSignatureFromDer(string $derSignature) : string{
		if($derSignature[0] !== self::ASN1_SEQUENCE_TAG){
			throw new JwtException("Invalid DER signature, expected an ASN.1 SEQUENCE tag, got " . bin2hex($derSignature[0]));
		}

		$length = ord($derSignature[1]);
		$parts = substr($derSignature, 2, $length);
		if(strlen($parts) !== $length){
			throw new JwtException("Invalid DER signature, expected $length sequence bytes, got " . strlen($parts));
		}

		$offset = 0;
		$r = self::signaturePartFromAsn1($parts, $offset);
		$s = self::signaturePartFromAsn1($parts, $offset);
		if($offset !== strlen($parts)){
			throw new JwtException("Invalid DER signature, unexpected trailing sequence data");
		}

		return $r . $s;
	}

	private static function signaturePartFromAsn1(string $sequence, int &$offset) : string{
		if(($sequence[$offset] ?? "") !== self::ASN1_INTEGER_TAG){
			throw new JwtException("Expected an ASN.1 INTEGER tag at offset $offset");
		}
		$length = ord($sequence[$offset + 1]);
		if($length > self::SIGNATURE_PART_LENGTH + 1){
			throw new JwtException("Expected at most 49 bytes for signature R or S, got $length");
		}
		$part = substr($sequence, $offset + 2, $length);
		if(strlen($part) !== $length){
			throw new JwtException("Truncated ASN.1 INTEGER in DER signature");
		}
		$offset += 2 + $length;

		return str_pad(ltrim($part, "\x00"), self::SIGNATURE_PART_LENGTH, "\x00", STR_PAD_LEFT);
	}
}
