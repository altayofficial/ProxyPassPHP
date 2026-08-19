<?php

declare(strict_types=1);

namespace altay\proxypass\logging;

use altay\proxypass\Configuration;
use altay\proxypass\utils\PacketPrinter;
use pocketmine\network\mcpe\protocol\Packet;
use function count;
use function date;
use function file_put_contents;
use function fmod;
use function implode;
use function is_dir;
use function json_encode;
use function microtime;
use function mkdir;
use function sprintf;
use const FILE_APPEND;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

final class SessionLogger{
	private const FLUSH_INTERVAL = 5.0;

	private string $dataPath;
	private string $logPath;

	/** @var string[] */
	private array $logBuffer = [];
	private float $lastFlush;

	public function __construct(
		private Configuration $configuration,
		string $sessionsDir,
		string $displayName,
		int $timestamp
	){
		$this->dataPath = $sessionsDir . "/" . $displayName . "-" . $timestamp;
		$this->logPath = $this->dataPath . "/packets.log";
		$this->lastFlush = microtime(true);
	}

	public function start() : void{
		if($this->configuration->isLoggingPackets() && $this->configuration->getLogTo()->logsToFile()){
			$this->createDataDirectory();
		}
	}

	public function getDataPath() : string{
		return $this->dataPath;
	}

	public function logPacket(?Packet $packet, bool $serverBound) : void{
		if(!$this->configuration->isLoggingPackets() || $packet === null){
			return;
		}
		if($this->configuration->isIgnoredPacket($packet->getName())){
			return;
		}

		$message = sprintf("[%s] [%s] - %s", self::formatTime(), $serverBound ? "SERVER BOUND" : "CLIENT BOUND", PacketPrinter::printPacket($packet));

		if($this->configuration->getLogTo()->logsToFile()){
			$this->logBuffer[] = $message;
		}
		if($this->configuration->getLogTo()->logsToConsole()){
			echo $message . PHP_EOL;
		}
	}

	/**
	 * @param mixed $data
	 */
	public function saveJson(string $name, $data) : void{
		$this->createDataDirectory();
		file_put_contents($this->dataPath . "/" . $name . ".json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	public function tick() : void{
		if(microtime(true) - $this->lastFlush >= self::FLUSH_INTERVAL){
			$this->flush();
		}
	}

	public function flush() : void{
		$this->lastFlush = microtime(true);
		if(count($this->logBuffer) === 0){
			return;
		}

		$this->createDataDirectory();
		file_put_contents($this->logPath, implode(PHP_EOL, $this->logBuffer) . PHP_EOL, FILE_APPEND);
		$this->logBuffer = [];
	}

	private function createDataDirectory() : void{
		if(!is_dir($this->dataPath) && !mkdir($this->dataPath, 0777, true) && !is_dir($this->dataPath)){
			throw new \RuntimeException("Failed to create session directory " . $this->dataPath);
		}
	}

	private static function formatTime() : string{
		$time = microtime(true);

		return date("H:i:s", (int) $time) . sprintf(":%03d", (int) (fmod($time, 1) * 1000));
	}
}
