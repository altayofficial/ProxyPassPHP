<?php

declare(strict_types=1);

namespace altay\proxypass\network\session;

final class IdentityData{

	public function __construct(
		private string $displayName,
		private string $uuid,
		private string $xuid,
		private string $minecraftId
	){}

	public function getDisplayName() : string{
		return $this->displayName;
	}

	public function getUuid() : string{
		return $this->uuid;
	}

	public function getXuid() : string{
		return $this->xuid;
	}

	public function getMinecraftId() : string{
		return $this->minecraftId;
	}
}
