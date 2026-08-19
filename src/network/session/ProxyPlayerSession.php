<?php

declare(strict_types=1);

namespace altay\proxypass\network\session;

use altay\proxypass\crypto\JwtUtils;
use altay\proxypass\logging\SessionLogger;
use altay\proxypass\ProxyPass;
use function time;

final class ProxyPlayerSession{

	private \OpenSSLAsymmetricKey $keyPair;
	private SessionLogger $logger;
	private int $timestamp;

	public function __construct(
		private ProxyServerSession $upstream,
		private ProxyClientSession $downstream,
		ProxyPass $proxy,
		private IdentityData $identityData
	){
		$this->keyPair = JwtUtils::generateKeyPair();
		$this->timestamp = time();
		$this->logger = new SessionLogger($proxy->getConfiguration(), $proxy->getSessionsDir(), $identityData->getDisplayName(), $this->timestamp);
		$this->logger->start();
	}

	public function getUpstream() : ProxyServerSession{
		return $this->upstream;
	}

	public function getDownstream() : ProxyClientSession{
		return $this->downstream;
	}

	public function getIdentityData() : IdentityData{
		return $this->identityData;
	}

	public function getKeyPair() : \OpenSSLAsymmetricKey{
		return $this->keyPair;
	}

	public function getTimestamp() : int{
		return $this->timestamp;
	}

	public function getLogger() : SessionLogger{
		return $this->logger;
	}
}
