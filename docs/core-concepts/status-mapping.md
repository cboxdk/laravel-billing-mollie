---
title: Status mapping & the SDK seam
weight: 1
description: How the Mollie SDK is isolated and its statuses mapped to a PaymentResult.
---

# Status mapping & the SDK seam

## The seam

`MolliePaymentGateway` depends on a small `MollieIntentCreator` interface, not the
Mollie SDK directly:

- `MollieApiIntentCreator` is the real implementation — it wraps the Mollie SDK,
  formats the amount as a decimal string, includes the redirect URL, makes the API
  call, and normalises the result to `{id, status}`. It is deliberately thin.
- `FakeMollieIntentCreator` drives the tests.

This keeps the gateway's decision logic fully unit-tested without the network, and
confines the "verify against the live API" surface to the thin wrapper.

## Mapping

| Mollie status | `PaymentResult` |
| --- | --- |
| `paid` | settled |
| `open` · `pending` | pending |
| `authorized` | requires action |
| `canceled` · `expired` · `failed` · anything else | failed |

Because Mollie is redirect-based, a freshly created payment is normally `open`
(pending) — settlement is confirmed later (webhook) when the status becomes `paid`.
A Mollie API failure is caught and returned as a **failed** result — the gateway
never throws. The Mollie payment id is carried through as the gateway reference.
