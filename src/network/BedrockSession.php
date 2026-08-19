<?php

declare(strict_types=1);

namespace altay\proxypass\network;

use altay\network\transport\TransportSession;
use altay\proxypass\compression\Compressor;
use altay\proxypass\compression\DecompressionException;
use altay\proxypass\crypto\DecryptionException;
use altay\proxypass\crypto\EncryptionContext;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketHandlerInterface;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use function base64_encode;
use function chr;
use function count;
use function ord;
use function strlen;
use function substr;

abstract class BedrockSession{
	private const MCPE_PACKET_ID = "\xfe";

	private ?PacketHandlerInterface $handler = null;
	private ?EncryptionContext $cipher = null;
	private ?Compressor $compressor = null;
	private bool $compressionEnabled = false;

	/** @var string[] */
	private array $sendBuffer = [];

	public function __construct(
		private TransportSession $transport,
		private PacketPool $packetPool,
		protected \Logger $logger
	){}

	public function getTransport() : TransportSession{
		return $this->transport;
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	public function getPacketHandler() : ?PacketHandlerInterface{
		return $this->handler;
	}

	public function setPacketHandler(?PacketHandlerInterface $handler) : void{
		$this->handler = $handler;
	}

	public function setCompressor(Compressor $compressor) : void{
		$this->compressor = $compressor;
	}

	public function enableCompression() : void{
		if($this->compressor === null){
			throw new \LogicException("Cannot enable compression without a compressor");
		}
		$this->compressionEnabled = true;
	}

	public function enableEncryption(string $encryptionKey) : void{
		$this->cipher = EncryptionContext::fakeGCM($encryptionKey);
	}

	public function isConnected() : bool{
		return $this->transport->isConnected();
	}

	public function disconnect() : void{
		$this->flush();
		$this->transport->disconnect();
	}

	public function handleIncoming(string $payload) : void{
		if($payload === "" || $payload[0] !== self::MCPE_PACKET_ID){
			$this->logger->debug("Non-FE packet received: " . base64_encode($payload));
			return;
		}
		$payload = substr($payload, 1);

		if($this->cipher !== null){
			try{
				$payload = $this->cipher->decrypt($payload);
			}catch(DecryptionException $e){
				$this->logger->error("Packet decryption error: " . $e->getMessage());
				return;
			}
		}

		if(strlen($payload) < 1){
			$this->logger->debug("No bytes in payload");
			return;
		}

		if($this->compressionEnabled){
			$compressionType = ord($payload[0]);
			$compressed = substr($payload, 1);
			if($compressionType === CompressionAlgorithm::NONE){
				$decompressed = $compressed;
			}elseif($this->compressor !== null && $compressionType === $this->compressor->getNetworkId()){
				try{
					$decompressed = $this->compressor->decompress($compressed);
				}catch(DecompressionException $e){
					$this->logger->error("Failed to decompress packet: " . $e->getMessage());
					return;
				}
			}else{
				$this->logger->error("Packet compressed with unexpected compression type $compressionType");
				return;
			}
		}else{
			$decompressed = $payload;
		}

		try{
			foreach(PacketBatch::decodeRaw(new ByteBufferReader($decompressed)) as $buffer){
				$this->handleIncomingBuffer($buffer);
			}
		}catch(\Throwable $e){
			$this->logger->error("Error decoding packet batch: " . $e->getMessage());
		}
	}

	private function handleIncomingBuffer(string $buffer) : void{
		$packet = null;
		try{
			$packet = $this->packetPool->getPacket($buffer);
			$packet?->decode(new ByteBufferReader($buffer));
		}catch(\Throwable $e){
			$this->logger->debug("Error decoding packet: " . $e->getMessage() . " (" . base64_encode($buffer) . ")");
			$packet = null;
		}

		$this->onPacket($packet, $buffer);
	}

	/**
	 * Called for every packet received from the peer. The packet may be null if it could not be decoded, in which case
	 * only the raw buffer is available.
	 */
	abstract protected function onPacket(?Packet $packet, string $buffer) : void;

	public function sendPacket(Packet $packet, bool $immediate = false) : void{
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$this->sendRawPacket($writer->getData(), $immediate);
	}

	public function sendRawPacket(string $buffer, bool $immediate = false) : void{
		$this->sendBuffer[] = $buffer;
		if($immediate){
			$this->flush(true);
		}
	}

	public function flush(bool $immediate = false) : void{
		if(count($this->sendBuffer) === 0 || !$this->transport->isConnected()){
			return;
		}

		$writer = new ByteBufferWriter();
		PacketBatch::encodeRaw($writer, $this->sendBuffer);
		$this->sendBuffer = [];

		$payload = $writer->getData();
		if($this->compressionEnabled && $this->compressor !== null){
			$threshold = $this->compressor->getCompressionThreshold();
			if($threshold === null || strlen($payload) < $threshold){
				$payload = chr(CompressionAlgorithm::NONE) . $payload;
			}else{
				$payload = chr($this->compressor->getNetworkId()) . $this->compressor->compress($payload);
			}
		}

		if($this->cipher !== null){
			$payload = $this->cipher->encrypt($payload);
		}

		$this->transport->sendPacket(self::MCPE_PACKET_ID . $payload, $immediate);
	}
}
