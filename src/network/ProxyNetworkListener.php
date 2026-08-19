<?php

declare(strict_types=1);

namespace altay\proxypass\network;

use altay\network\transport\Transport;
use altay\network\transport\TransportListener;
use altay\network\transport\TransportSession;
use altay\proxypass\network\handler\UpstreamPacketHandler;
use altay\proxypass\network\raknet\RakNetClientTransport;
use altay\proxypass\network\session\ProxyClientSession;
use altay\proxypass\network\session\ProxyServerSession;
use altay\proxypass\network\session\ProxySession;
use altay\proxypass\ProxyPass;
use pocketmine\network\mcpe\protocol\PacketPool;
use function spl_object_id;

final class ProxyNetworkListener implements TransportListener{

	/** @var ProxySession[] */
	private array $sessions = [];

	public function __construct(
		private ProxyPass $proxy,
		private PacketPool $packetPool
	){}

	/**
	 * @return ProxySession[]
	 */
	public function getSessions() : array{
		return $this->sessions;
	}

	public function onSessionOpen(Transport $transport, TransportSession $session) : void{
		$key = self::sessionKey($transport, $session);
		$logger = new \PrefixedLogger($this->proxy->getLogger(), $session->getAddress() . ":" . $session->getPort());

		if($transport instanceof RakNetClientTransport){
			$client = new ProxyClientSession($session, $this->packetPool, $logger, $this->proxy);
			$this->sessions[$key] = $client;
			$this->proxy->onClientSessionOpen($transport, $client);
			return;
		}

		if($this->proxy->isFull()){
			$this->proxy->getLogger()->notice("Refused connection from " . $session->getAddress() . " because the proxy is full");
			$session->disconnect();
			return;
		}

		$upstream = new ProxyServerSession($session, $this->packetPool, $logger, $this->proxy);
		$upstream->setPacketHandler(new UpstreamPacketHandler($upstream, $this->proxy));
		$this->sessions[$key] = $upstream;
	}

	public function onSessionClose(Transport $transport, TransportSession $session, string $reason) : void{
		$key = self::sessionKey($transport, $session);
		$closed = $this->sessions[$key] ?? null;
		if($closed === null){
			return;
		}
		unset($this->sessions[$key]);

		$closed->getPlayer()?->getLogger()->flush();
		$this->proxy->getLogger()->debug("Session " . $session->getAddress() . ":" . $session->getPort() . " closed: " . $reason);

		$sendSession = $closed->getSendSession();
		$closed->setSendSession(null);
		if($sendSession instanceof ProxySession){
			$sendSession->setSendSession(null);
		}
		$sendSession?->disconnect();
	}

	public function onPacketReceive(Transport $transport, TransportSession $session, string $payload) : void{
		($this->sessions[self::sessionKey($transport, $session)] ?? null)?->handleIncoming($payload);
	}

	public function onPacketAck(Transport $transport, TransportSession $session, int $receiptId) : void{
		//NOOP
	}

	public function onPingUpdate(Transport $transport, TransportSession $session, int $pingMS) : void{
		//NOOP
	}

	public function onRawPacketReceive(Transport $transport, string $address, int $port, string $payload) : void{
		//NOOP
	}

	public function onBandwidthUpdate(Transport $transport, int $bytesSentDiff, int $bytesReceivedDiff) : void{
		//NOOP
	}

	private static function sessionKey(Transport $transport, TransportSession $session) : string{
		return spl_object_id($transport) . ":" . $session->getId();
	}
}
