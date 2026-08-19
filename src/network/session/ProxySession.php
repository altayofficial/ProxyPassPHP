<?php

declare(strict_types=1);

namespace altay\proxypass\network\session;

use altay\network\transport\TransportSession;
use altay\proxypass\network\BedrockSession;
use altay\proxypass\ProxyPass;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketPool;
use function base64_encode;

abstract class ProxySession extends BedrockSession{

	private ?BedrockSession $sendSession = null;
	private ?ProxyPlayerSession $player = null;

	public function __construct(
		TransportSession $transport,
		PacketPool $packetPool,
		\Logger $logger,
		protected ProxyPass $proxy
	){
		parent::__construct($transport, $packetPool, $logger);
	}

	public function getProxyPass() : ProxyPass{
		return $this->proxy;
	}

	public function getSendSession() : ?BedrockSession{
		return $this->sendSession;
	}

	public function setSendSession(?BedrockSession $sendSession) : void{
		$this->sendSession = $sendSession;
	}

	public function getPlayer() : ?ProxyPlayerSession{
		return $this->player;
	}

	public function setPlayer(?ProxyPlayerSession $player) : void{
		$this->player = $player;
	}

	/**
	 * Returns whether packets received by this session are travelling towards the destination server.
	 */
	abstract protected function isServerBound() : bool;

	protected function onPacket(?Packet $packet, string $buffer) : void{
		$this->player?->getLogger()->logPacket($packet, $this->isServerBound());

		$handler = $this->getPacketHandler();
		if($handler === null){
			$this->logger->debug("Received packet without a packet handler: " . base64_encode($buffer));
			return;
		}

		if($packet !== null && $packet->handle($handler)){
			return;
		}

		$this->sendSession?->sendRawPacket($buffer);
	}
}
