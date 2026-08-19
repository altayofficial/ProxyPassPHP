<?php

declare(strict_types=1);

namespace altay\proxypass;

use altay\network\Network;
use altay\network\raknet\RakNetTransport;
use altay\network\raknet\utils\InternetAddress;
use altay\proxypass\network\ProxyNetworkListener;
use altay\proxypass\network\raknet\RakNetClientTransport;
use altay\proxypass\network\session\ProxyClientSession;
use altay\proxypass\utils\ConsoleLogger;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use function copy;
use function count;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function spl_object_id;
use function sprintf;

final class ProxyPass{
	public const NAME = "ProxyPass";
	public const PROTOCOL_VERSION = ProtocolInfo::CURRENT_PROTOCOL;
	public const MINECRAFT_VERSION = ProtocolInfo::MINECRAFT_VERSION_NETWORK;

	private Configuration $configuration;
	private Network $network;
	private RakNetTransport $serverTransport;
	private ProxyNetworkListener $listener;

	/** @var RakNetClientTransport[] */
	private array $clients = [];

	/** @var \Closure[] */
	private array $pendingClients = [];

	private string $sessionsDir;
	private string $dataDir;
	private int $nextClientId = 0;
	private bool $running = true;

	public function __construct(
		private string $baseDir,
		private \Logger $logger = new ConsoleLogger()
	){
		$this->sessionsDir = $this->baseDir . "/sessions";
		$this->dataDir = $this->baseDir . "/data";
	}

	public function boot() : void{
		$this->logger->info("Loading configuration...");
		$configPath = $this->baseDir . "/config.yml";
		if(!is_file($configPath)){
			copy(__DIR__ . "/../resources/config.yml", $configPath);
		}
		$this->configuration = Configuration::load($configPath);

		self::createDirectory($this->sessionsDir);
		self::createDirectory($this->dataDir);

		$this->listener = new ProxyNetworkListener($this, PacketPool::getInstance());
		$this->network = new Network($this->listener);

		$proxyAddress = $this->configuration->getProxy();
		$this->serverTransport = new RakNetTransport($this->logger, $proxyAddress->getHost(), $proxyAddress->getPort());
		$this->network->registerTransport($this->serverTransport);
		$this->network->start();
		$this->serverTransport->setName($this->createServerName());

		$this->logger->info(sprintf(
			"Bedrock server %s (%d) started on %s, forwarding to %s",
			self::MINECRAFT_VERSION,
			self::PROTOCOL_VERSION,
			(string) $proxyAddress,
			(string) $this->configuration->getDestination()
		));

		$this->loop();
	}

	private function loop() : void{
		while($this->running){
			$this->network->tick();

			foreach($this->clients as $id => $client){
				if(!$client->isRunning()){
					unset($this->clients[$id], $this->pendingClients[$id]);
					continue;
				}
				$client->tick();
			}

			foreach($this->listener->getSessions() as $session){
				$session->flush();
				$session->getPlayer()?->getLogger()->tick();
			}
		}

		foreach($this->listener->getSessions() as $session){
			$session->getPlayer()?->getLogger()->flush();
		}
		foreach($this->clients as $client){
			$client->shutdown();
		}
		$this->network->shutdown();
	}

	/**
	 * @phpstan-param \Closure(ProxyClientSession) : void $sessionConsumer
	 */
	public function newClient(\Closure $sessionConsumer) : void{
		$destination = $this->configuration->getDestination();
		$transport = new RakNetClientTransport(
			$this->logger,
			"raknet-client-" . $this->nextClientId,
			new InternetAddress($destination->getHost(), $destination->getPort(), 4),
			$this->nextClientId++
		);

		$id = spl_object_id($transport);
		$this->clients[$id] = $transport;
		$this->pendingClients[$id] = $sessionConsumer;

		$transport->start($this->listener);
	}

	public function onClientSessionOpen(RakNetClientTransport $transport, ProxyClientSession $session) : void{
		$id = spl_object_id($transport);
		$consumer = $this->pendingClients[$id] ?? null;
		if($consumer !== null){
			unset($this->pendingClients[$id]);
			$consumer($session);
		}
	}

	public function isFull() : bool{
		return $this->configuration->getMaxClients() > 0 && count($this->clients) >= $this->configuration->getMaxClients();
	}

	public function shutdown() : void{
		$this->running = false;
	}

	public function getConfiguration() : Configuration{
		return $this->configuration;
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	public function getBaseDir() : string{
		return $this->baseDir;
	}

	public function getSessionsDir() : string{
		return $this->sessionsDir;
	}

	public function getDataDir() : string{
		return $this->dataDir;
	}

	private function createServerName() : string{
		return implode(";", [
			"MCPE",
			self::NAME,
			self::PROTOCOL_VERSION,
			self::MINECRAFT_VERSION,
			0,
			$this->configuration->getMaxClients() > 0 ? $this->configuration->getMaxClients() : 20,
			$this->serverTransport->getServerId() ?? 0,
			self::NAME,
			"Survival",
			1,
			$this->configuration->getProxy()->getPort(),
			$this->configuration->getProxy()->getPort(),
			0
		]);
	}

	private static function createDirectory(string $path) : void{
		if(!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)){
			throw new \RuntimeException("Failed to create directory $path");
		}
	}
}
