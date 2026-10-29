<?php
namespace RealTest\Fixture;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
final class ProductionKernel extends Kernel {
 public function __construct(private string $root){parent::__construct('prod',false);}
 public function getProjectDir():string{return $this->root;}
 public function getCacheDir():string{return $this->root.'/cache-prod-no-profiler';}
 public function getLogDir():string{return $this->root.'/logs';}
 public function registerBundles():iterable{return [
  new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
  new \OmegaGuardian\ProfilerShield\OmegaGuardianProfilerShieldBundle()
 ];}
 public function registerContainerConfiguration(LoaderInterface $loader):void{
  $loader->load(function(ContainerBuilder $c){
   $c->loadFromExtension('framework',[
    'secret'=>'only-a-disposable-test-secret',
    'router'=>['resource'=>__DIR__.'/routes.php','type'=>'php'],
    'profiler'=>['collect'=>false],
    'session'=>['storage_factory_id'=>'session.storage.factory.mock_file']
   ]);
   $c->register('real_test.api_exception',ApiExceptionSubscriber::class)->addTag('kernel.event_subscriber');
  });
 }
}
