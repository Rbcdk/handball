<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->bigInteger('balance')->default(0)->after('tactics');
            $table->unsignedInteger('arena_capacity')->default(1300)->after('balance');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->unsignedInteger('wage')->default(0)->after('potential'); // per match day
            $table->unsignedInteger('contract_until')->default(1)->after('wage'); // last season of the contract
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('last_ip', 45)->nullable()->after('guide_progress');
        });

        // Every change to a club's balance, with the reason.
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained();
            $table->bigInteger('amount'); // positive = income, negative = cost
            $table->string('type', 24); // wages, tickets, sponsor, prize, transfer_in, transfer_out, start
            $table->string('description');
            $table->bigInteger('balance_after');
            $table->timestamps();
            $table->index(['club_id', 'created_at']);
        });

        Schema::create('transfer_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_club_id')->constrained('clubs');
            $table->string('kind', 8); // auction | fixed
            $table->unsignedBigInteger('price'); // starting price (auction) or asking price (fixed)
            $table->timestamp('ends_at')->nullable(); // auctions only
            $table->string('status', 12)->default('open')->index(); // open | sold | expired | cancelled
            $table->foreignId('buyer_club_id')->nullable()->constrained('clubs');
            $table->unsignedBigInteger('final_price')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('transfer_listings')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->index(['listing_id', 'amount']);
        });

        // History of completed moves, including free-agent signings.
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained();
            $table->foreignId('from_club_id')->nullable()->constrained('clubs');
            $table->foreignId('to_club_id')->nullable()->constrained('clubs');
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('kind', 12); // auction | fixed | free_agent | released | contract_end
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('transfer_bids');
        Schema::dropIfExists('transfer_listings');
        Schema::dropIfExists('transactions');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('last_ip'));
        Schema::table('players', fn (Blueprint $t) => $t->dropColumn(['wage', 'contract_until']));
        Schema::table('clubs', fn (Blueprint $t) => $t->dropColumn(['balance', 'arena_capacity']));
    }
};
