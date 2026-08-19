<?php

declare(strict_types=1);

namespace altay\proxypass\network\raknet;

use altay\network\raknet\client\ClientSocket;
use altay\network\raknet\generic\DisconnectReason;
use altay\network\raknet\generic\PacketHandlingException;
use altay\network\raknet\generic\SocketException;
use altay\network\raknet\protocol\ACK;
use altay\network\raknet\protocol\Datagram;
use altay\network\raknet\protocol\IncompatibleProtocolVersion;
use altay\network\raknet\protocol\MessageIdentifiers;
use altay\network\raknet\protocol\NACK;
use altay\network\raknet\protocol\OpenConnectionReply1;
use altay\network\raknet\protocol\OpenConnectionReply2;
use altay\network\raknet\protocol\OpenConnectionRequest1;
use altay\network\raknet\protocol\OpenConnectionRequest2;
use altay\network\raknet\protocol\Packet;
use altay\network\raknet\protocol\PacketSerializer;
use altay\network\raknet\RakNetTransport;
use altay\network\raknet\utils\InternetAddress;
use altay\network\transport\Transport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;
use altay\network\transport\TransportSession;
use function count;
use function microtime;
use function min;
use function mt_rand;
use function ord;
use function strlen;
use const PHP_INT_MAX;
use const SOCKET_ECONNRESET;

final class RakNetClientTransport implements Transport{
	private const STATE_HANDSHAKE_1 = 0;
	private const STATE_HANDSHAKE_2 = 1;
	private const STATE_SESSION = 2;

	/** IP header size (20 bytes) + UDP header size (8 bytes) */
	private const UDP_HEADER_SIZE = 28;
	private const MTU_SIZES = [1492, 1200, 576];
	private const HANDSHAKE_RETRY_INTERVAL = 0.5;
	private const HANDSHAKE_TIMEOUT = 10.0;

	private ?ClientSocket $socket = null;
	private ?TransportListener $listener = null;
	private ?ClientSession $session = null;
	private ?ClientTransportSession $transportSession = null;

	private int $state = self::STATE_HANDSHAKE_1;
	private int $mtuIndex = 0;
	private int $negotiatedMtu = 0;
	private float $lastHandshakeAttempt = 0.0;
	private float $handshakeStart = 0.0;
	private int $clientId;

	public function __construct(
		private \Logger $logger,
		private string $name,
		private InternetAddress $address,
		private int $sessionId,
		private int $protocolVersion = RakNetTransport::BEDROCK_RAKNET_PROTOCOL_VERSION
	){
		$this->clientId = mt_rand(0, PHP_INT_MAX);
	}

	public function getName() : string{
		return $this->name;
	}

	public function start(TransportListener $listener) : void{
		if($this->socket !== null){
			throw new TransportException("RakNet client transport is already running");
		}
		try{
			$socket = new ClientSocket($this->address);
		}catch(SocketException $e){
			throw new TransportException("Failed to connect to " . $this->address . ": " . $e->getMessage(), 0, $e);
		}
		$socket->setBlocking(false);

		$this->socket = $socket;
		$this->listener = $listener;
		$this->state = self::STATE_HANDSHAKE_1;
		$this->handshakeStart = microtime(true);
		$this->sendHandshakePacket();
	}

	public function tick() : void{
		if($this->socket === null){
			return;
		}

		for($i = 0; $i < 100 && $this->receivePacket(); ++$i){
			//NOOP
		}

		$time = microtime(true);
		if($this->session !== null){
			$this->session->update($time);
			if($this->session->isFullyDisconnected()){
				$this->shutdown();
			}
			return;
		}

		if($time - $this->handshakeStart > self::HANDSHAKE_TIMEOUT){
			$this->logger->error("Failed to connect to " . $this->address . ": handshake timed out");
			$this->closeWithReason(DisconnectReason::PEER_TIMEOUT);
			return;
		}
		if($time - $this->lastHandshakeAttempt > self::HANDSHAKE_RETRY_INTERVAL){
			$this->sendHandshakePacket();
		}
	}

	public function isSelfPacing() : bool{
		return false;
	}

	public function getSession(int $sessionId) : ?TransportSession{
		return $sessionId === $this->sessionId ? $this->transportSession : null;
	}

	public function isRunning() : bool{
		return $this->socket !== null;
	}

	public function shutdown() : void{
		if($this->socket === null){
			return;
		}
		if($this->session !== null && $this->session->isConnected()){
			$this->session->initiateDisconnect(DisconnectReason::CLIENT_DISCONNECT);
			$this->session->update(microtime(true));
		}
		$this->closeWithReason(DisconnectReason::CLIENT_DISCONNECT);
	}

	public function sendPacket(Packet $packet) : void{
		if($this->socket === null){
			return;
		}
		$out = new PacketSerializer();
		$packet->encode($out);
		try{
			$this->socket->writePacket($out->getBuffer());
		}catch(SocketException $e){
			$this->logger->debug($e->getMessage());
		}
	}

	public function onSessionConnect() : void{
		if($this->session === null || $this->listener === null){
			return;
		}
		$this->transportSession = new ClientTransportSession($this->session, $this->sessionId, $this->address->getIp(), $this->address->getPort());
		$this->listener->onSessionOpen($this, $this->transportSession);
	}

	public function onSessionPacketReceive(string $payload) : void{
		if($this->transportSession !== null && $this->listener !== null){
			$this->listener->onPacketReceive($this, $this->transportSession, $payload);
		}
	}

	public function onSessionPacketAck(int $receiptId) : void{
		if($this->transportSession !== null && $this->listener !== null){
			$this->listener->onPacketAck($this, $this->transportSession, $receiptId);
		}
	}

	public function onSessionPingMeasure(int $pingMS) : void{
		if($this->transportSession !== null && $this->listener !== null){
			$this->transportSession->updatePing($pingMS);
			$this->listener->onPingUpdate($this, $this->transportSession, $pingMS);
		}
	}

	public function onSessionDisconnect(int $reason) : void{
		$session = $this->transportSession;
		if($session === null || $this->listener === null){
			return;
		}
		$this->transportSession = null;
		$session->markDisconnected();
		$this->listener->onSessionClose($this, $session, DisconnectReason::toString($reason));
	}

	private function closeWithReason(int $reason) : void{
		$this->onSessionDisconnect($reason);
		$this->socket?->close();
		$this->socket = null;
		$this->session = null;
	}

	private function sendHandshakePacket() : void{
		$this->lastHandshakeAttempt = microtime(true);

		if($this->state === self::STATE_HANDSHAKE_1){
			$packet = new OpenConnectionRequest1();
			$packet->protocol = $this->protocolVersion;
			$packet->mtuSize = self::MTU_SIZES[$this->mtuIndex] - self::UDP_HEADER_SIZE;
			$this->mtuIndex = min($this->mtuIndex + 1, count(self::MTU_SIZES) - 1);
			$this->sendPacket($packet);
		}elseif($this->state === self::STATE_HANDSHAKE_2){
			$packet = new OpenConnectionRequest2();
			$packet->clientID = $this->clientId;
			$packet->serverAddress = $this->address;
			$packet->mtuSize = $this->negotiatedMtu;
			$this->sendPacket($packet);
		}
	}

	private function receivePacket() : bool{
		if($this->socket === null){
			return false;
		}
		try{
			$buffer = $this->socket->readPacket();
		}catch(SocketException $e){
			if($e->getCode() === SOCKET_ECONNRESET){
				return true;
			}
			$this->logger->debug($e->getMessage());
			return false;
		}
		if($buffer === null || strlen($buffer) < 1){
			return false;
		}

		$header = ord($buffer[0]);
		if($this->session !== null && ($header & Datagram::BITFLAG_VALID) !== 0){
			if(($header & Datagram::BITFLAG_ACK) !== 0){
				$packet = new ACK();
			}elseif(($header & Datagram::BITFLAG_NAK) !== 0){
				$packet = new NACK();
			}else{
				$packet = new Datagram();
			}

			try{
				$packet->decode(new PacketSerializer($buffer));
				$this->session->handlePacket($packet);
			}catch(PacketHandlingException $e){
				$this->logger->error("Error receiving packet: " . $e->getMessage());
				$this->session->forciblyDisconnect($e->getDisconnectReason());
			}catch(\Throwable $e){
				$this->logger->debug("Error decoding packet from " . $this->address . ": " . $e->getMessage());
			}
			return true;
		}

		$this->handleOfflinePacket($header, $buffer);
		return true;
	}

	private function handleOfflinePacket(int $header, string $buffer) : void{
		if($header === MessageIdentifiers::ID_OPEN_CONNECTION_REPLY_1 && $this->state === self::STATE_HANDSHAKE_1){
			$reply = new OpenConnectionReply1();
			$reply->decode(new PacketSerializer($buffer));
			if(!$reply->isValid()){
				return;
			}
			$this->negotiatedMtu = min($reply->mtuSize, self::MTU_SIZES[0]);
			$this->state = self::STATE_HANDSHAKE_2;
			$this->sendHandshakePacket();
		}elseif($header === MessageIdentifiers::ID_OPEN_CONNECTION_REPLY_2 && $this->state === self::STATE_HANDSHAKE_2){
			$reply = new OpenConnectionReply2();
			$reply->decode(new PacketSerializer($buffer));
			if(!$reply->isValid()){
				return;
			}
			$this->negotiatedMtu = min($reply->mtuSize, $this->negotiatedMtu);
			$this->state = self::STATE_SESSION;
			$this->session = new ClientSession($this, $this->logger, $this->address, $this->clientId, $this->negotiatedMtu);
			$this->session->sendConnectionRequest();
		}elseif($header === MessageIdentifiers::ID_INCOMPATIBLE_PROTOCOL_VERSION){
			$packet = new IncompatibleProtocolVersion();
			$packet->decode(new PacketSerializer($buffer));
			$this->logger->error("Failed to connect to " . $this->address . ": incompatible RakNet protocol version (server: $packet->protocolVersion, client: $this->protocolVersion)");
			$this->closeWithReason(DisconnectReason::SERVER_DISCONNECT);
		}
	}
}
