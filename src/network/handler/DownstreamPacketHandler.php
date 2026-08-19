<?php

declare(strict_types=1);

namespace altay\proxypass\network\handler;

use altay\proxypass\network\session\ProxyClientSession;
use altay\proxypass\network\session\ProxyPlayerSession;
use altay\proxypass\ProxyPass;
use pocketmine\network\mcpe\protocol\PacketHandlerDefaultImplTrait;
use pocketmine\network\mcpe\protocol\PacketHandlerInterface;

/**
 * Handles packets coming from the destination server once the session is fully established. Every packet is passed
 * through to the client, so this is the place to hook in any additional data dumping.
 */
final class DownstreamPacketHandler implements PacketHandlerInterface{
	use PacketHandlerDefaultImplTrait;

	public function __construct(
		private ProxyClientSession $session,
		private ProxyPlayerSession $player,
		private ProxyPass $proxy
	){}
}
