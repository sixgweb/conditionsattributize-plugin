<?php

namespace Sixgweb\Attributize\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class AddConditionsToFieldValuesTable extends Migration
{
    public function up()
    {
        Schema::table('sixgweb_attributize_field_values', function (
            Blueprint $table
        ) {
            $table->json('conditions')
                ->nullable()
                ->after('value');
        });
    }

    public function down()
    {
        Schema::table('sixgweb_attributize_field_values', function (
            Blueprint $table
        ) {
            if (Schema::hasColumn($table->getTable(), 'conditions')) {
                $table->dropColumn('conditions');
            }
        });
    }
}
