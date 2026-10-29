<?php
namespace RealTest\Fixture;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
final class TestKernel extends Kernel {
 public function __construct(private string $root,private bool $guarded,bool $debug,private bool $prototype=false){parent::__construct('dev',$debug);}
 public function getProjectDir():string{return $this->root;}
 public function getCacheDir():string{return $this->root.'/cache-'.($this->guarded?'guarded':'clean').($this->prototype?'-safe-native':'').'-'.($this->isDebug()?'debug':'release');}
 public function getLogDir():string{return $this->root.'/logs';}
 public function registerBundles():iterable{
  $bundles=[new \Symfony\Bundle\FrameworkBundle\FrameworkBundle()];
  if($this->guarded){$bundles[]=new \Symfony\Bundle\TwigBundle\TwigBundle();$bundles[]=new \Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();$bundles[]=new \OmegaGuardian\ProfilerShield\OmegaGuardianProfilerShieldBundle();}
  return $bundles;
 }
 public function registerContainerConfiguration(LoaderInterface $loader):void{
  $loader->load(function(ContainerBuilder $c){
   $c->loadFromExtension('framework',['secret'=>'test-only-non-production-secret','router'=>['resource'=>__DIR__.'/routes.php','type'=>'php'],'profiler'=>['collect'=>false],'session'=>['storage_factory_id'=>'session.storage.factory.mock_file']]);
   if($this->guarded){$c->loadFromExtension('twig',['default_path'=>__DIR__]);$c->loadFromExtension('web_profiler',['toolbar'=>true]);}
   $c->register('real_test.api_exception',ApiExceptionSubscriber::class)->addTag('kernel.event_subscriber');
   if($this->guarded && $this->prototype){
     $c->register('real_test.safe_native_subscriber',\RealTest\Prototype\SafeNativeSubscriber::class)->addTag('kernel.event_subscriber');
   }
  });
 }
}