<?php
namespace RealTest\Fixture;
final class Scenarios {
 public static function all():array{
  return [
   ['id'=>'BASE-01','method'=>'GET','path'=>'/api/health','accept'=>'application/json','status'=>200,'same'=>true],
   ['id'=>'BASE-02','method'=>'GET','path'=>'/api/validation','accept'=>'application/json','status'=>422,'same'=>true],
   ['id'=>'BASE-03','method'=>'GET','path'=>'/api/xml','accept'=>'application/xml','status'=>422,'same'=>true],
   ['id'=>'BASE-04','method'=>'GET','path'=>'/api/throw/validation','accept'=>'application/json','status'=>422,'same'=>true],
   ['id'=>'BASE-06','method'=>'GET','path'=>'/api/throttle','accept'=>'application/json','status'=>429,'same'=>true],
   ['id'=>'BASE-07','method'=>'POST','path'=>'/api/health','accept'=>'application/json','status'=>405,'same'=>false],
   ['id'=>'BASE-08','method'=>'GET','path'=>'/api/not-found','accept'=>'application/json','status'=>404,'same'=>false],
   ['id'=>'SAFE-01','method'=>'GET','path'=>'/api/throw/fatal','accept'=>'application/json','status'=>500,'same'=>false],
   ['id'=>'SAFE-03','method'=>'GET','path'=>'/api/throw/validation','accept'=>'application/json','status'=>422,'same'=>true],
   ['id'=>'SAFE-08','method'=>'GET','path'=>'/api/debug-header','accept'=>'application/json','status'=>200,'same'=>false],
   ['id'=>'HTTP-02','method'=>'GET','path'=>'/api/throw/auth','accept'=>'application/json','status'=>401,'same'=>false],
   ['id'=>'HTTP-03','method'=>'GET','path'=>'/api/throw/throttle','accept'=>'application/json','status'=>429,'same'=>false],
   ['id'=>'HTTP-04','method'=>'GET','path'=>'/api/throw/method','accept'=>'application/json','status'=>405,'same'=>false],
   ['id'=>'HTTP-06','method'=>'GET','path'=>'/api/empty','accept'=>'application/json','status'=>204,'same'=>true],
   ['id'=>'HTTP-09','method'=>'GET','path'=>'/api/throw/fatal','accept'=>'application/json','status'=>500,'same'=>false],
   ['id'=>'HTTP-10','method'=>'GET','path'=>'/api/throw/fatal','accept'=>'application/xml','status'=>500,'same'=>false],
   ['id'=>'HTTP-16','method'=>'POST','path'=>'/api/redirect','accept'=>'text/html','status'=>303,'same'=>true],
   ['id'=>'HTTP-19','method'=>'GET','path'=>'/api/validation','accept'=>'application/json','status'=>422,'same'=>true],
   ['id'=>'PROF-01','method'=>'GET','path'=>'/_profiler/','accept'=>'text/html','status'=>404,'same'=>false,'guardedOnly'=>true],
   ['id'=>'PROF-02','method'=>'GET','path'=>'/%5fprofiler/','accept'=>'text/html','status'=>404,'same'=>false,'guardedOnly'=>true],
   ['id'=>'PROF-03','method'=>'GET','path'=>'/docs/_profiler/guide','accept'=>'application/json','status'=>200,'same'=>true],
  ];
 }
}