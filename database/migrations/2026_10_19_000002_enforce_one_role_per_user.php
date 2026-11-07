<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE model_has_roles IN ACCESS EXCLUSIVE MODE');
        $types = array_unique([User::class, (new User)->getMorphClass(), ...array_keys(array_filter(Relation::morphMap(), fn ($class) => $class === User::class))]);
        $conflicts = DB::table('model_has_roles')->whereIn('model_type', $types)
            ->select('model_id')->groupBy('model_id')->havingRaw('count(*) > 1')->pluck('model_id');
        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException('Users with multiple role assignments: '.$conflicts->implode(', ').'. Resolve explicitly before migrating; assignments preserved.');
        }
        $predicate = implode(', ', array_map(fn ($type) => DB::getPdo()->quote($type), $types));
        DB::statement("CREATE UNIQUE INDEX model_has_roles_one_pazsalvo_user ON model_has_roles (model_id) WHERE model_type IN ({$predicate})");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS model_has_roles_one_pazsalvo_user');
    }
};
