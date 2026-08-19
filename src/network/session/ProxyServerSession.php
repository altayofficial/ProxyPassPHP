<?php

declare(strict_types=1);

namespace altay\proxypass\network\session;

final class ProxyServerSession extends ProxySession{

	protected function isServerBound() : bool{
		return true;
	}
}
