<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable stores behind idempotent Mollie webhook processing:
 *
 *  - `billing_mollie_processed_events` dedups deliveries by their stable identity
 *    (payment id + status, since Mollie's classic webhook has no event id), so a
 *    retried/replayed webhook is a no-op.
 *  - `billing_mollie_settled_payments` records which references have been settled, so
 *    the inline charge path and the webhook path settle each reference exactly once.
 *
 * Both rely on a unique index for their idempotency guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_mollie_processed_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->unsignedBigInteger('processed_at');
        });

        Schema::create('billing_mollie_settled_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('settled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_mollie_settled_payments');
        Schema::dropIfExists('billing_mollie_processed_events');
    }
};
