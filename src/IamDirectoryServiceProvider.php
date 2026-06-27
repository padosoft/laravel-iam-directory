<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory;

use Illuminate\Contracts\Foundation\Application;
use Padosoft\Iam\Directory\Contracts\DirectoryConnector;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Service provider del modulo Directory (doc 10 §6). Registra il group mapping, il provisioner JIT e
 * l'autenticatore. Il `DirectoryConnector` reale (LDAP/AD via LdapRecord) NON è cablato di default:
 * è una dipendenza opzionale (`suggest`) e va bindato dall'app o da un sub-package quando installato.
 */
final class IamDirectoryServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-iam-directory')->hasConfigFile('iam-directory');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GroupMapper::class, fn (Application $app): GroupMapper => new GroupMapper(
            $this->groupMap($app),
        ));

        $this->app->singleton(DirectoryProvisioner::class);

        $this->app->singleton(DirectoryAuthenticator::class, fn (Application $app): DirectoryAuthenticator => new DirectoryAuthenticator(
            $app->make(DirectoryConnector::class),
            $app->make(GroupMapper::class),
            $app->make(DirectoryProvisioner::class),
            $this->config($app),
        ));
    }

    /** @return array<array-key, mixed> */
    private function groupMap(Application $app): array
    {
        $map = $app->make('config')->get('iam-directory.group_map');

        return is_array($map) ? $map : [];
    }

    /** @return array<string, mixed> */
    private function config(Application $app): array
    {
        $config = $app->make('config')->get('iam-directory');
        if (!is_array($config)) {
            return [];
        }

        $out = [];
        foreach ($config as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
