<?php
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
return static function(RoutingConfigurator $routes):void {
 \RealTest\Fixture\Routes::load($routes);
};
