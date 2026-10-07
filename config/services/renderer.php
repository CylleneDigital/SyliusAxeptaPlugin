<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CylleneDigital\SyliusAxeptaPlugin\Renderer\AutoSubmitFormRenderer;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('cyllene_digital_sylius_axepta.renderer.auto_submit_form', AutoSubmitFormRenderer::class)
        ->args([service('twig')]);
};
