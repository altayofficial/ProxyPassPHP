# Contributing

Thanks for taking the time to improve ProxyPass. This document describes what is expected from a contribution.

## Reporting bugs

Open an issue and include:

- the ProxyPass commit you are running
- the protocol version of the client and of the destination server
- the relevant part of `sessions/<player>-<timestamp>/packets.log`
- the full stack trace, if the proxy crashed

Do not paste `clientData.json` verbatim - it contains device identifiers. Strip anything you are not comfortable
sharing publicly.

## Development setup

```
composer install
php bin/proxypass.php
```

`ext-crypto` and `ext-encoding` are not shipped with most PHP distributions. The easiest way to get a working
interpreter is to use the binaries built by [PHP-Binaries](https://github.com/altayofficial/PHP-Binaries).

## Pull requests

- Target the `main` branch and keep one pull request focused on one change.
- Match the surrounding code: tabs for indentation, `declare(strict_types=1);` in every file, imports sorted, no
  unused imports.
- Follow the existing architecture. Packet handling belongs in `src/network/handler`, session plumbing in
  `src/network/session`, transport level code in `src/network/raknet`.
- Do not commit `config.yml`, `sessions/`, `data/` or `vendor/`.
- Run `php -l` over the files you touched, and make sure a client can still connect end to end before opening the
  pull request.
- Write commit titles in the conventional commit style, for example `fix: forward unknown packets untouched`.

## Adding data dumps

`DownstreamPacketHandler` is the intended place to hook in dumping of server data (item registries, recipes, block
palettes). Return `false` from a handler method so the packet is still forwarded to the client - returning `true`
swallows the packet and will break the session.
