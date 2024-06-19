<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatePromoCodeServices extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('promo', function (Blueprint $table) {

            $table->string('promo_code_users_available')->nullable()->after('to');
            $table->text('user_ids')->after('total_uses')->nullable();
            $table->text('service_ids')->after('total_uses')->nullable();
            $table->unsignedInteger('user_id')->after('promo_code_users_available')->nullable();
                $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

    }
}
