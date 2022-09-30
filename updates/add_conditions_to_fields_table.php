<?php

namespace Sixgweb\Attributize\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class AddConditionsToFieldsTable extends Migration
{
    public function up()
    {
        Schema::table('sixgweb_attributize_fields', function (
            Blueprint $table
        ) {
            $table->json('conditions')
                ->nullable()
                ->after('config');
        });
    }

    public function down()
    {
        Schema::table('sixgweb_attributize_fields', function (
            Blueprint $table
        ) {
            if (Schema::hasColumn($table->getTable(), 'conditions')) {
                $table->dropColumn('conditions');
            }
        });
    }
}
