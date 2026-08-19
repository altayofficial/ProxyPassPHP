<h1 align="center">ProxyPass</h1>

<p align="center">
	<b>Man-in-the-middle proxy for Minecraft: Bedrock Edition, written in PHP.</b><br>
	Sits between a client and a server, decodes every packet in both directions and writes the flow to disk.
</p>

<p align="center">
	<a href="https://github.com/altayofficial/ProxyPass/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/altayofficial/ProxyPass/ci.yml?branch=main&style=for-the-badge&logo=github&label=CI"></a>
	<a href="https://www.php.net/"><img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white"></a>
	<a href="https://minecraft.net/"><img alt="Minecraft" src="https://img.shields.io/badge/Minecraft-1.26.50%20(2192)-62B47A?style=for-the-badge&logo=minecraft&logoColor=white"></a>
	<a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-GPL--3.0-blue?style=for-the-badge"></a>
</p>

<p align="center">
	<a href="#features">Features</a> •
	<a href="#requirements">Requirements</a> •
	<a href="#getting-started">Getting started</a> •
	<a href="#output">Output</a> •
	<a href="#configuration">Configuration</a> •
	<a href="#how-it-works">How it works</a>
</p>

---

## Features

-  **Full packet capture** - both directions, decrypted and decoded, written to a per-session log file
-  **Transparent forwarding** - packets are forwarded as the exact bytes they arrived as, never re-encoded
-  **Handles the handshake** - network settings, compression and the encryption handshake are done for you
-  **Client data dumps** - the skin and device information sent by the client is saved as JSON
-  **Noise filtering** - spammy packets can be dropped from the log through the config
-  **Ready to extend** - `DownstreamPacketHandler` is the hook for dumping registries, recipes or palettes

Built on top of [altayofficial/Network](https://github.com/altayofficial/Network) and
[altayofficial/BedrockProtocol](https://github.com/altayofficial/BedrockProtocol).

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `crypto`, `encoding`, `gmp`, `json`, `openssl`, `sockets`, `yaml`, `zlib` |
| Protocol | 2192 (Minecraft: Bedrock Edition 1.26.50) |

`ext-crypto` and `ext-encoding` are not part of a stock PHP install. Prebuilt binaries containing both are available
from [PHP-Binaries](https://github.com/altayofficial/PHP-Binaries).

## Getting started

```bash
git clone https://github.com/altayofficial/ProxyPass
cd ProxyPass
composer install
php bin/proxypass.php
```

A `config.yml` is copied into the working directory on the first start. Point `destination` at the server you want to
inspect, then connect your client to the `proxy` address:

```yaml
proxy:
  host: 0.0.0.0
  port: 19122
destination:
  host: 127.0.0.1
  port: 19132
```

Pass `--debug` to get verbose transport logging on the console.

> [!NOTE]
> Only offline mode servers can be joined. The proxy re-signs the login with its own key pair, so the destination
> server sees the proxy rather than an authenticated Xbox Live account.

## Output

Every session gets its own directory:

```
sessions/
└── Steve-1787162646/
    ├── clientData.json
    └── packets.log
```

`packets.log` uses one line per packet:

```
[18:04:06:439] [CLIENT BOUND] - NetworkSettingsPacket(compressionThreshold=1, compressionAlgorithm=0, ...)
[18:04:06:498] [CLIENT BOUND] - PlayStatusPacket(status=0, senderSubId=0, recipientSubId=0)
[18:04:06:714] [SERVER BOUND] - ClientCacheStatusPacket(enabled=true, senderSubId=0, recipientSubId=0)
```

`SERVER BOUND` is a packet travelling from the client to the destination server, `CLIENT BOUND` is the other way
around.

> [!WARNING]
> `sessions/` holds decrypted traffic and device identifiers. Do not publish it as is.

## Configuration

| Option | Description |
|---|---|
| `proxy` | Address the proxy binds to |
| `destination` | Address of the server clients are forwarded to |
| `max-clients` | Maximum amount of connected clients, `0` disables the limit |
| `log-packets` | Whether the packet flow is logged at all |
| `log-to` | `console`, `file` or `both` |
| `ignored-packets` | Packet class names which are left out of the log |

## How it works

```
   Client  ──RakNet──▶  ProxyServerSession  ──▶  ProxyClientSession  ──RakNet──▶  Server
                              │                        │
                              └────── SessionLogger ───┘
                                    sessions/<player>-<timestamp>/packets.log
```

1. The client performs the network settings handshake with the proxy, which answers as a server would.
2. On login, the proxy reads the identity out of the client's token, opens a RakNet connection to the destination and
   forges a new self-signed login with a key pair generated for that session.
3. The encryption handshake with the destination is completed by the proxy, so it holds both halves of the session in
   plain text.
4. From then on, each packet is decoded for logging and the original bytes are handed to the other side untouched.

| Package | Responsibility |
|---|---|
| `network` | Bedrock session layer: batching, compression, encryption |
| `network/raknet` | RakNet client used for the connection to the destination |
| `network/session` | Upstream and downstream proxy sessions |
| `network/handler` | Login, handshake and pass-through packet handlers |
| `logging` | Session log files |
| `crypto` | JWT handling, ECDH key exchange, packet cipher |

## Contributing

Bug reports and pull requests are welcome - see [CONTRIBUTING.md](CONTRIBUTING.md). By taking part you agree to the
[Code of Conduct](CODE_OF_CONDUCT.md). Vulnerabilities should be reported privately, see [SECURITY.md](SECURITY.md).

## Credits

Inspired by [CloudburstMC/ProxyPass](https://github.com/CloudburstMC/ProxyPass).
