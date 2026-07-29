<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger. Append-only, in the same sense and for the same reason as
     * `audit_logs`: its entire value is being trustworthy after the fact.
     *
     * Note there is no `updated_at`. That is not an oversight -- a row that can
     * be touched after it is written is not a ledger entry. Corrections are new
     * reversing rows, so the history of a mistake survives alongside its fix.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();

            // Signed: positive credits the bucket, negative debits it. Callers
            // always pass a positive amount and the type decides the sign, so
            // no caller can accidentally credit where it meant to debit.
            $table->bigInteger('amount_minor');

            // Which of the wallet's two pots this row moved. Maturity writes
            // two rows -- one per bucket -- rather than one ambiguous row.
            $table->string('bucket', 10);

            $table->string('type', 30);

            // The bucket's value immediately after this row. Redundant with a
            // running sum, and worth it: reconciliation and "explain this
            // customer's balance" both become a single indexed read instead of
            // replaying the account's whole history.
            $table->bigInteger('balance_after_minor');

            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();

            // Populated from Phase 2 and Phase 6. Declared now so the ledger
            // never needs an ALTER once it holds real money.
            $table->unsignedBigInteger('topup_id')->nullable();
            $table->unsignedBigInteger('withdrawal_id')->nullable();

            // The other side of a transfer: a customer's payment row points at
            // the Owner's earning row and vice versa, so either half of a
            // movement can be traced to the other without guessing by amount
            // and timestamp.
            $table->foreignId('counterparty_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();

            // Guards against a retried webhook crediting the same money twice.
            $table->string('idempotency_key')->nullable()->unique();

            $table->json('meta')->nullable();

            // created_at only -- see the class note.
            $table->timestamp('created_at')->useCurrent();

            // Wallet statements, newest first.
            $table->index(['wallet_id', 'created_at']);
            // "Show me everything that happened to this booking's money."
            $table->index('reservation_id');
            // Reporting and the reconciliation sweep.
            $table->index(['type', 'created_at']);
            // Recomputing a bucket from the ledger during reconciliation.
            $table->index(['wallet_id', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
