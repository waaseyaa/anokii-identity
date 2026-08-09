<?php

declare(strict_types=1);

namespace Anokii\Identity;

use Anokii\Identity\Controller\HostIdentityController;
use Anokii\Identity\Entity\Pillar;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/** Registers the canonical Identity domain and its opt-in host surface. */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(
            Pillar::class,
            revisionable: true,
            revisionDefault: true,
            translatable: true,
            tenancy: ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY],
        ));
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        if (!$this->readOnlyHostSurfaceEnabled()) {
            return;
        }

        foreach ([
            'anokii.identity.host.index' => '/admin/anokii',
            'anokii.identity.host.identity' => '/admin/anokii/identity',
        ] as $name => $path) {
            $router->addRoute(
                $name,
                RouteBuilder::create($path)
                    ->controller(HostIdentityController::class . '::index')
                    ->requireAuthentication()
                    ->methods('GET')
                    ->priority(100)
                    ->build(),
            );
        }
    }

    private function readOnlyHostSurfaceEnabled(): bool
    {
        $anokii = $this->config['anokii'] ?? null;
        $identity = is_array($anokii) ? ($anokii['identity'] ?? null) : null;

        return is_array($identity) && ($identity['host_surface'] ?? null) === 'read_only';
    }
}
