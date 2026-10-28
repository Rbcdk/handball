<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedTinyInteger('fitness')->default(100)->after('contract_until'); // 0-100 at next kick-off
            $table->unsignedTinyInteger('injured_days')->default(0)->after('fitness'); // match days still out
            $table->string('injury', 40)->nullable()->after('injured_days');
            $table->string('training_focus', 16)->default('balanced')->after('injury');
            $table->unsignedSmallInteger('training_xp')->default(0)->after('training_focus'); // 100 = one skill point
        });

        Schema::table('clubs', function (Blueprint $table) {
            $table->string('training_intensity', 8)->default('normal')->after('arena_capacity');
            $table->json('lineup')->nullable()->after('training_intensity'); // chosen starters and 7 m taker
        });

        // Short notes for the manager: injuries, recoveries, training progress, transfers.
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // injury | recovered | training | transfer | info
            $table->string('message');
            $table->timestamps();
            $table->index(['club_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
        Schema::table('clubs', fn (Blueprint $t) => $t->dropColumn(['training_intensity', 'lineup']));
        Schema::table('players', fn (Blueprint $t) => $t->dropColumn(['fitness', 'injured_days', 'injury', 'training_focus', 'training_xp']));
    }
};
