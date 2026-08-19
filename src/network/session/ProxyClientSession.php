<?php

declare(strict_types=1);

namespace altay\proxypass\network\session;

final class ProxyClientSession extends ProxySession{

	protected function isServerBound() : bool{
		return false;
	}
}
