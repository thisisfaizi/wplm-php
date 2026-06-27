# Security Policy

## Reporting a vulnerability

Please report security issues privately to **security@nowdigiverse.com**. Do not
open a public issue for security reports. We aim to acknowledge within 72 hours.

## What this SDK ships

This SDK ships only the **Ed25519 public key**, the product id, and the server
URL. It never contains a private signing key, the server's vault key, or the
fingerprint HMAC secret. Offline verification is public-key only.
