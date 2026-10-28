<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->string('status', 16)->default('active'); // active | finished
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('tier'); // 1 = top division
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name')->unique();
            $table->string('short', 3);
            $table->string('city');
            $table->string('color', 7);
            $table->json('tactics');
            $table->timestamp('taken_over_at')->nullable();
            $table->timestamps();
        });

        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->unsignedTinyInteger('number');
            $table->string('position', 2); // GK LW LB CB RB RW PV
            $table->unsignedTinyInteger('age');
            foreach (['shooting', 'passing', 'technique', 'speed', 'stamina', 'jumping', 'defense', 'composure', 'reflexes', 'positioning', 'seven_meter'] as $attr) {
                $table->unsignedTinyInteger($attr);
            }
            $table->unsignedTinyInteger('potential'); // hidden ceiling for development
            $table->timestamps();
        });

        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained();
            $table->foreignId('league_id')->constrained();
            $table->unsignedSmallInteger('round');
            $table->foreignId('home_club_id')->constrained('clubs');
            $table->foreignId('away_club_id')->constrained('clubs');
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('played_at')->nullable();
            $table->unsignedTinyInteger('home_score')->nullable();
            $table->unsignedTinyInteger('away_score')->nullable();
            $table->unsignedTinyInteger('halftime_home')->nullable();
            $table->unsignedTinyInteger('halftime_away')->nullable();
            $table->unsignedInteger('seed');
            $table->timestamps();
            $table->index(['season_id', 'league_id', 'round']);
        });

        Schema::create('match_logs', function (Blueprint $table) {
            $table->foreignId('fixture_id')->primary()->constrained()->cascadeOnDelete();
            $table->longText('log'); // gzip-compressed, base64-encoded engine output
            $table->timestamps();
        });

        Schema::create('player_season_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained();
            $table->foreignId('club_id')->constrained();
            $table->unsignedSmallInteger('matches')->default(0);
            $table->unsignedSmallInteger('goals')->default(0);
            $table->unsignedSmallInteger('shots')->default(0);
            $table->unsignedSmallInteger('assists')->default(0);
            $table->unsignedSmallInteger('seven_m_goals')->default(0);
            $table->unsignedSmallInteger('saves')->default(0);
            $table->unsignedSmallInteger('shots_faced')->default(0);
            $table->unsignedSmallInteger('suspensions')->default(0);
            $table->timestamps();
            $table->unique(['player_id', 'season_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_season_stats');
        Schema::dropIfExists('match_logs');
        Schema::dropIfExists('fixtures');
        Schema::dropIfExists('players');
        Schema::dropIfExists('clubs');
        Schema::dropIfExists('leagues');
        Schema::dropIfExists('seasons');
    }
};
