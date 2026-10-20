<?php

namespace Webkul\Admin\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Admin\Support\DefaultRoles;

class InstallDefaultRoles extends Command
{
    protected $signature = 'roles:install-defaults {--update : Also rewrite the permissions of the profiles that already exist} {--only=* : Install only these profiles (by name)}';

    protected $description = 'Creates the standard profiles (Gestor, Vendedor, SDR, Atendimento, Marketing, Somente leitura)';

    public function handle(): int
    {
        $result = DefaultRoles::install((bool) $this->option('update'), $this->option('only') ?: null);

        foreach (['created' => 'Criados', 'updated' => 'Atualizados', 'skipped' => 'Já existiam (mantidos)'] as $key => $label) {
            if ($result[$key]) {
                $this->info($label.': '.implode(', ', $result[$key]));
            }
        }

        return self::SUCCESS;
    }
}
