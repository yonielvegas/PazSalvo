<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class CreatePazSalvoUser extends Command
{
    protected $signature = 'paz-salvo:create-user
        {--name= : Nombre completo}
        {--email= : Correo electrónico}
        {--agency= : Nombre de agencia}
        {--agency-code= : Código de agencia}
        {--role=admin : Rol}
        {--password= : Contraseña (si se omite se solicitará de forma oculta)}';

    protected $description = 'Crea un usuario institucional con agencia y rol asignados';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nombre completo');
        $email = $this->option('email') ?: $this->ask('Correo electrónico');
        $agencyName = $this->option('agency') ?: $this->ask('Nombre de la agencia');
        $agencyCode = $this->option('agency-code') ?: $this->ask('Código de agencia (opcional)');
        $role = $this->option('role') ?: $this->choice('Rol', ['admin', 'supervisor', 'operador', 'consulta'], 0);
        $password = $this->option('password') ?: $this->secret('Contraseña (mínimo 8 caracteres)');

        $validator = Validator::make(compact('name', 'email', 'agencyName', 'role', 'password'), [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'agencyName' => ['required', 'string', 'max:255'], 'role' => ['required', Rule::in(['admin', 'supervisor', 'operador', 'consulta'])],
            'password' => ['required', 'string', 'min:8'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

return self::FAILURE;
        }
        if (! Role::where('name', $role)->exists()) {
            $this->error('Ejecute primero php artisan db:seed para crear roles y permisos.');

            return self::FAILURE;
        }

        $agency = Agency::firstOrCreate(['name' => $agencyName], ['code' => $agencyCode ?: null, 'is_active' => true]);
        $user = User::create(['agency_id' => $agency->id, 'name' => $name, 'email' => mb_strtolower($email), 'password' => Hash::make($password)]);
        $user->assignRole($role);
        $this->info("Usuario {$user->email} creado con rol {$role} en {$agency->name}.");

        return self::SUCCESS;
    }
}
