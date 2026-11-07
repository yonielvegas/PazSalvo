<?php

namespace App\Console\Commands;

use App\Integrations\AaudOauth\Exceptions\InvalidRoleCatalogException;
use App\Integrations\AaudOauth\Roles\RoleCatalogBuilder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class PreviewSsoRoleCatalog extends Command
{
    protected $signature = 'sso:roles:preview';

    protected $description = 'Muestra el catálogo web para AAUD OAUTH, sin tokens ni sincronización';

    public function handle(RoleCatalogBuilder $builder): int
    {
        try {
            $catalog = $builder->build();
        } catch (InvalidRoleCatalogException $exception) {
            $this->output->getErrorStyle()->writeln($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException) {
            // Do not expose SQL, connection details or credentials on a failed diagnostic.
            $this->output->getErrorStyle()->writeln('No se pudo leer el catálogo de roles. Revise la conexión y el esquema local.');

            return self::FAILURE;
        }
        $this->line(json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
