---
title: Requirements
weight: 2
description: PHP, Laravel and the direct dependencies this adapter enforces.
---

# Requirements

From `composer.json`:

- **PHP** `^8.4`
- **Laravel** `^12 || ^13` (`illuminate/contracts`, `illuminate/support`)
- **`mollie/mollie-api-php`** `^3.13` — the official Mollie SDK.
- **`cboxdk/laravel-billing`** — provides the `PaymentGateway` contract and the
  `PaymentIntent` / `PaymentResult` value objects.
