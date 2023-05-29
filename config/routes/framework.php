<?php


use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator) {

    if ($routingConfigurator->env() == 'dev') {
        $routingConfigurator->import('@FrameworkBundle/Resources/config/routing/errors.xml')
            ->prefix("/_error");
    }
};
