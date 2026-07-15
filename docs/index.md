---
title: Overview
weight: 0
description: A Mollie payment-gateway adapter for cboxdk/laravel-billing, with the SDK isolated behind a testable seam.
---

# Cbox Billing — Mollie

`cboxdk/laravel-billing-mollie` implements billing's `PaymentGateway` contract
backed by Mollie. Install it and set a key, and billing's payments route through
Mollie.

## Mental model

- Billing is gateway-agnostic: it charges a `PaymentIntent` and reads a
  `PaymentResult`. This package provides the Mollie implementation.
- The Mollie SDK is isolated behind a small `MollieIntentCreator` seam, so the
  gateway's status-mapping and error handling are unit-tested without the network.
- Mollie is **redirect-based**: a created payment starts `open` (pending) until the
  customer completes it and Mollie confirms `paid`.
- The gateway **never throws**: a Mollie API failure becomes a failed result.

## Sections

- [Getting started](getting-started/_index.md) — install, configure, test.
- [Core concepts](core-concepts/_index.md) — the seam and status mapping.
