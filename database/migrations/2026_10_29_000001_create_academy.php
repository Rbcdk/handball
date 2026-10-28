<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->unsignedTinyInteger('academy_level')->default(1)->after('arena_build_left');
            $table->unsignedInteger('academy_intake_season')->nullable()->after('academy_level');
        });
        Schema::table('players', function (Blueprint $table) {
            // Seed for the drawn portrait, so a prospect keeps his face when he joins the first team.
            $table->unsignedInteger('face')->nullable();
        });
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('position', 2);
            $table->unsignedTinyInteger('age');
            foreach (['shooting', 'passing', 'technique', 'speed', 'stamina', 'jumping', 'defense', 'composure', 'reflexes', 'positioning', 'seven_meter'] as $a) {
                $table->unsignedTinyInteger($a);
            }
            $table->unsignedTinyInteger('potential');
            $table->unsignedSmallInteger('training_xp')->default(0);
            $table->unsignedInteger('face');
            $table->unsignedInteger('joined_season');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
        Schema::table('players', fn (Blueprint $t) => $t->dropColumn('face'));
        Schema::table('clubs', fn (Blueprint $t) => $t->dropColumn(['academy_level', 'academy_intake_season']));
    }
};
