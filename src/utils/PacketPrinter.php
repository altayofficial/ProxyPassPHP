<?php

declare(strict_types=1);

namespace altay\proxypass\utils;

use pocketmine\network\mcpe\protocol\Packet;
use function bin2hex;
use function count;
use function get_class;
use function get_debug_type;
use function implode;
use function is_float;
use function is_int;
use function is_iterable;
use function is_object;
use function is_string;
use function mb_check_encoding;
use function strlen;
use function strrpos;
use function substr;

final class PacketPrinter{
	private const MAX_DEPTH = 6;
	private const MAX_ELEMENTS = 32;
	private const MAX_STRING_LENGTH = 256;

	private function __construct(){
		//NOOP
	}

	public static function printPacket(Packet $packet) : string{
		return self::shortName(get_class($packet)) . self::printObjectBody($packet, 0);
	}

	private static function printValue(mixed $value, int $depth) : string{
		if($value === null){
			return "null";
		}
		if($value === true){
			return "true";
		}
		if($value === false){
			return "false";
		}
		if(is_string($value)){
			return self::printString($value);
		}
		if($value instanceof \UnitEnum){
			return self::shortName(get_class($value)) . "::" . $value->name;
		}
		if($value instanceof \Traversable){
			$value = [...$value];
		}
		if(is_iterable($value)){
			return self::printArray($value, $depth);
		}
		if($value instanceof \Stringable){
			return (string) $value;
		}
		if($value instanceof \GMP){
			return $value->__toString();
		}
		if($value instanceof \OpenSSLAsymmetricKey){
			return "OpenSSLAsymmetricKey";
		}
		if(is_object($value)){
			if($depth >= self::MAX_DEPTH){
				return self::shortName(get_class($value)) . "{...}";
			}
			return self::shortName(get_class($value)) . self::printObjectBody($value, $depth + 1);
		}
		if(is_float($value) || is_int($value)){
			return (string) $value;
		}

		return get_debug_type($value);
	}

	/**
	 * @param mixed[] $values
	 */
	private static function printArray(array $values, int $depth) : string{
		$parts = [];
		$listed = 0;
		foreach($values as $key => $value){
			if(++$listed > self::MAX_ELEMENTS){
				$parts[] = "... " . (count($values) - self::MAX_ELEMENTS) . " more";
				break;
			}
			$parts[] = (is_int($key) ? "" : $key . ": ") . self::printValue($value, $depth + 1);
		}

		return "[" . implode(", ", $parts) . "]";
	}

	private static function printObjectBody(object $object, int $depth) : string{
		$parts = [];
		foreach((new \ReflectionObject($object))->getProperties() as $property){
			if($property->isStatic()){
				continue;
			}
			$property->setAccessible(true);
			if(!$property->isInitialized($object)){
				continue;
			}
			$parts[] = $property->getName() . "=" . self::printValue($property->getValue($object), $depth);
		}

		return "(" . implode(", ", $parts) . ")";
	}

	private static function printString(string $value) : string{
		if(!mb_check_encoding($value, "UTF-8")){
			return strlen($value) > self::MAX_STRING_LENGTH ?
				"0x" . bin2hex(substr($value, 0, self::MAX_STRING_LENGTH)) . "... (" . strlen($value) . " bytes)" :
				"0x" . bin2hex($value);
		}
		if(strlen($value) > self::MAX_STRING_LENGTH){
			return "\"" . substr($value, 0, self::MAX_STRING_LENGTH) . "\"... (" . strlen($value) . " bytes)";
		}

		return "\"" . $value . "\"";
	}

	private static function shortName(string $className) : string{
		$position = strrpos($className, "\\");

		return $position === false ? $className : substr($className, $position + 1);
	}
}
