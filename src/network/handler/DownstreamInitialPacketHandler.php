<?php

declare(strict_types=1);

namespace altay\proxypass\network\handler;

use altay\proxypass\compression\ZlibCompressor;
use altay\proxypass\crypto\EncryptionUtils;
use altay\proxypass\crypto\JwtUtils;
use altay\proxypass\network\session\ProxyClientSession;
use altay\proxypass\network\session\ProxyPlayerSession;
use altay\proxypass\ProxyPass;
use pocketmine\network\mcpe\protocol\ClientToServerHandshakePacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\NetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\PacketHandlerDefaultImplTrait;
use pocketmine\network\mcpe\protocol\PacketHandlerInterface;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ServerToClientHandshakePacket;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use function base64_decode;
use function is_string;

final class DownstreamInitialPacketHandler implements PacketHandlerInterface{
	use PacketHandlerDefaultImplTrait;

	public function __construct(
		private ProxyClientSession $session,
		private ProxyPlayerSession $player,
		private ProxyPass $proxy,
		private LoginPacket $loginPacket
	){}

	public function handleNetworkSettings(NetworkSettingsPacket $packet) : bool{
		$algorithm = $packet->getCompressionAlgorithm();
		if($algorithm !== CompressionAlgorithm::ZLIB){
			$this->proxy->getLogger()->error("The destination server picked an unsupported compression algorithm ($algorithm)");
			$this->session->disconnect();
			return true;
		}

		$this->session->setCompressor(new ZlibCompressor($packet->getCompressionThreshold()));
		$this->session->enableCompression();
		$this->session->sendPacket($this->loginPacket, true);

		return true;
	}

	public function handlePlayStatus(PlayStatusPacket $packet) : bool{
		if($packet->status === PlayStatusPacket::LOGIN_SUCCESS){
			$this->session->setPacketHandler(new DownstreamPacketHandler($this->session, $this->player, $this->proxy));
			$this->proxy->getLogger()->debug("Downstream connected without encryption");
		}

		return false;
	}

	public function handleServerToClientHandshake(ServerToClientHandshakePacket $packet) : bool{
		try{
			[$header, $claims, ] = JwtUtils::parse($packet->jwt);
			if(!is_string($header["x5u"] ?? null) || !is_string($claims["salt"] ?? null)){
				throw new \InvalidArgumentException("Malformed handshake token");
			}

			$serverKeyDer = base64_decode($header["x5u"], true);
			$salt = base64_decode($claims["salt"], true);
			if($serverKeyDer === false || $salt === false){
				throw new \InvalidArgumentException("Malformed handshake token encoding");
			}

			$secret = EncryptionUtils::generateSharedSecret($this->player->getKeyPair(), JwtUtils::parseDerPublicKey($serverKeyDer));
			$this->session->enableEncryption(EncryptionUtils::generateKey($secret, $salt));
		}catch(\Throwable $e){
			$this->proxy->getLogger()->error("Unable to complete the encryption handshake: " . $e->getMessage());
			$this->session->disconnect();
			return true;
		}

		$this->session->sendPacket(ClientToServerHandshakePacket::create(), true);
		$this->session->setPacketHandler(new DownstreamPacketHandler($this->session, $this->player, $this->proxy));
		$this->proxy->getLogger()->debug("Downstream connected");

		return true;
	}
}
