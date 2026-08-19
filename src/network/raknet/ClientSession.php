<?php

declare(strict_types=1);

namespace altay\proxypass\network\raknet;

use altay\network\raknet\generic\Session;
use altay\network\raknet\protocol\ConnectionRequest;
use altay\network\raknet\protocol\ConnectionRequestAccepted;
use altay\network\raknet\protocol\MessageIdentifiers;
use altay\network\raknet\protocol\NewIncomingConnection;
use altay\network\raknet\protocol\Packet;
use altay\network\raknet\protocol\PacketReliability;
use altay\network\raknet\protocol\PacketSerializer;
use altay\network\raknet\utils\InternetAddress;
use function ord;

final class ClientSession extends Session{
	public const DEFAULT_MAX_SPLIT_PART_COUNT = 512;
	public const DEFAULT_MAX_CONCURRENT_SPLIT_COUNT = 16;

	public function __construct(
		private RakNetClientTransport $transport,
		\Logger $logger,
		InternetAddress $address,
		int $clientId,
		int $mtuSize
	){
		parent::__construct($logger, $address, $clientId, $mtuSize, self::DEFAULT_MAX_SPLIT_PART_COUNT, self::DEFAULT_MAX_CONCURRENT_SPLIT_COUNT);
	}

	public function sendConnectionRequest() : void{
		$packet = new ConnectionRequest();
		$packet->clientID = $this->getID();
		$packet->sendPingTime = $this->getRakNetTimeMS();
		$this->queueConnectedPacket($packet, PacketReliability::UNRELIABLE, 0, true);
	}

	protected function sendPacket(Packet $packet) : void{
		$this->transport->sendPacket($packet);
	}

	protected function onPacketAck(int $identifierACK) : void{
		$this->transport->onSessionPacketAck($identifierACK);
	}

	protected function onDisconnect(int $reason) : void{
		$this->transport->onSessionDisconnect($reason);
	}

	protected function handleRakNetConnectionPacket(string $packet) : void{
		if(ord($packet[0]) !== MessageIdentifiers::ID_CONNECTION_REQUEST_ACCEPTED){
			return;
		}

		$dataPacket = new ConnectionRequestAccepted();
		$dataPacket->decode(new PacketSerializer($packet));

		$response = new NewIncomingConnection();
		$response->address = $this->address;
		$response->systemAddresses = $dataPacket->systemAddresses;
		$response->sendPingTime = $dataPacket->sendPongTime;
		$response->sendPongTime = $this->getRakNetTimeMS();
		$this->queueConnectedPacket($response, PacketReliability::RELIABLE_ORDERED, 0, true);

		$this->state = self::STATE_CONNECTED;
		$this->transport->onSessionConnect();
	}

	protected function onPacketReceive(string $packet) : void{
		$this->transport->onSessionPacketReceive($packet);
	}

	protected function onPingMeasure(int $pingMS) : void{
		$this->transport->onSessionPingMeasure($pingMS);
	}
}
