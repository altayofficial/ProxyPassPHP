# Security Policy

## Supported versions

Only the latest commit on `main` is supported. Fixes are not backported.

## Reporting a vulnerability

Report vulnerabilities privately through
[GitHub Security Advisories](https://github.com/altayofficial/ProxyPass/security/advisories/new). Do not open a
public issue for a vulnerability.

Please include a description of the issue, the steps needed to reproduce it and the impact you expect. You will get
an initial response within 7 days.

## Scope

ProxyPass is a debugging tool. It is a man-in-the-middle by design and it deliberately does the following:

- it re-signs the login with a key pair it generates itself, so the destination server sees the proxy, not the client
- it decrypts and logs the entire packet flow of the session

These are not vulnerabilities. Issues worth reporting are, for example, remote code execution while decoding a
packet, crashes triggered by a malicious peer, or the proxy leaking data outside of its own working directory.

## Handling captured data

`sessions/` contains decrypted traffic, and `clientData.json` contains device identifiers, the client's skin and the
address it connected to. Treat that directory as sensitive and do not publish it as is.
