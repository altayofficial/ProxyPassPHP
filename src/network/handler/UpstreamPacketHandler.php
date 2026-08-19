<?php

declare(strict_types=1);

namespace altay\proxypass\network\handler;

use altay\proxypass\compression\ZlibCompressor;
use altay\proxypass\crypto\JwtUtils;
use altay\proxypass\network\session\IdentityData;
use altay\proxypass\network\session\ProxyClientSession;
use altay\proxypass\network\session\ProxyPlayerSession;
use altay\proxypass\network\session\ProxyServerSession;
use altay\proxypass\ProxyPass;
use altay\proxypass\utils\ForgeryUtils;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\NetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\PacketHandlerDefaultImplTrait;
use pocketmine\network\mcpe\protocol\PacketHandlerInterface;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\network\mcpe\protocol\types\login\AuthenticationType;
use Ramsey\Uuid\Uuid;
use function chr;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function md5;
use function ord;
use const JSON_THROW_ON_ERROR;

final class UpstreamPacketHandler implements PacketHandlerInterface{
	use PacketHandlerDefaultImplTrait;

	private ?IdentityData $identityData = null;

	/** @var mixed[]|null */
	private ?array $clientData = null;

	public function __construct(
		private ProxyServerSession $session,
		private ProxyPass $proxy
	){}

	public function handleRequestNetworkSettings(RequestNetworkSettingsPacket $packet) : bool{
		$protocolVersion = $packet->getProtocolVersion();
		if($protocolVersion !== ProxyPass::PROTOCOL_VERSION){
			$this->session->sendPacket(PlayStatusPacket::create(
				$protocolVersion > ProxyPass::PROTOCOL_VERSION ? PlayStatusPacket::LOGIN_FAILED_SERVER : PlayStatusPacket::LOGIN_FAILED_CLIENT
			), true);
			return true;
		}

		$this->session->setCompressor(new ZlibCompressor(NetworkSettingsPacket::COMPRESS_EVERYTHING));
		$this->session->sendPacket(NetworkSettingsPacket::create(
			NetworkSettingsPacket::COMPRESS_EVERYTHING,
			CompressionAlgorithm::ZLIB,
			false,
			0,
			0
		), true);
		$this->session->enableCompression();

		return true;
	}

	public function handleLogin(LoginPacket $packet) : bool{
		try{
			$this->identityData = self::parseIdentityData($packet->authInfoJson);
			[, $clientData, ] = JwtUtils::parse($packet->clientDataJwt);
			$this->clientData = $clientData;
		}catch(\Throwable $e){
			$this->proxy->getLogger()->error("Unable to complete login: " . $e->getMessage());
			$this->session->disconnect();
			return true;
		}

		$this->initializeProxySession();

		return true;
	}

	private function initializeProxySession() : void{
		$identityData = $this->identityData;
		$clientData = $this->clientData;
		if($identityData === null || $clientData === null){
			return;
		}

		$this->proxy->getLogger()->debug("Initializing proxy session for " . $identityData->getDisplayName());
		$this->proxy->newClient(function(ProxyClientSession $downstream) use ($identityData, $clientData) : void{
			$downstream->setSendSession($this->session);
			$this->session->setSendSession($downstream);

			$player = new ProxyPlayerSession($this->session, $downstream, $this->proxy, $identityData);
			$downstream->setPlayer($player);
			$this->session->setPlayer($player);

			$player->getLogger()->saveJson("clientData", $clientData);

			$login = LoginPacket::create(
				ProxyPass::PROTOCOL_VERSION,
				json_encode([
					"AuthenticationType" => AuthenticationType::SELF_SIGNED->value,
					"Token" => ForgeryUtils::forgeAuthToken($player->getKeyPair(), $identityData)
				], JSON_THROW_ON_ERROR),
				ForgeryUtils::forgeClientData($player->getKeyPair(), $clientData)
			);

			$downstream->setPacketHandler(new DownstreamInitialPacketHandler($downstream, $player, $this->proxy, $login));
			$downstream->sendPacket(RequestNetworkSettingsPacket::create(ProxyPass::PROTOCOL_VERSION), true);
		});
	}

	private static function parseIdentityData(string $authInfoJson) : IdentityData{
		$authInfo = json_decode($authInfoJson, true, flags: JSON_THROW_ON_ERROR);
		if(!is_array($authInfo) || !isset($authInfo["Token"]) || !is_string($authInfo["Token"])){
			throw new \InvalidArgumentException("Malformed authentication info");
		}

		[, $claims, ] = JwtUtils::parse($authInfo["Token"]);

		$displayName = $claims["xname"] ?? null;
		if(!is_string($displayName)){
			throw new \InvalidArgumentException("Authentication token does not contain a display name");
		}
		$xuid = is_string($claims["xid"] ?? null) ? $claims["xid"] : "";
		$uuid = is_string($claims["leguuid"] ?? null) ? $claims["leguuid"] : self::uuidFromXuid($xuid);
		$minecraftId = is_string($claims["mid"] ?? null) ? $claims["mid"] : "";

		return new IdentityData($displayName, $uuid, $xuid, $minecraftId);
	}

	private static function uuidFromXuid(string $xuid) : string{
		return Uuid::fromBytes(self::applyUuidVersion(md5("pocket-auth-1-xuid:" . $xuid, true)))->toString();
	}

	private static function applyUuidVersion(string $hash) : string{
		$hash[6] = chr((ord($hash[6]) & 0x0f) | 0x30);
		$hash[8] = chr((ord($hash[8]) & 0x3f) | 0x80);

		return $hash;
	}
}
