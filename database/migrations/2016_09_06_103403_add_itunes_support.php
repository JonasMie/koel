<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddItunesSupport extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //
        Schema::table('songs', function (Blueprint $table) {
            $table->integer('itunes_id')->after('id')->nullable();
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->integer('itunes_id')->after('id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('itunes_id');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('itunes_id');
        });
    }
}
