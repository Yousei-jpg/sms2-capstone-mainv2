<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/config.php';
require_once ROOT_PATH.'/config/database.php';
require_once ROOT_PATH.'/includes/authentication.php';
require_once ROOT_PATH.'/includes/audit.php';
require_once ROOT_PATH.'/modules/scheduling/teacher-mapping-service.php';
header('Content-Type: application/json; charset=utf-8');
if(!isAuthenticated()){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Authentication required.']);exit;}
if(!userCanAccessModule('scheduling')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Scheduling access required.']);exit;}
try{
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'error'=>'POST required.']);exit;}
    $p=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($p)||!in_array($p['action']??'',['validate','save'],true))throw new InvalidArgumentException('Invalid mapping request.');
    echo json_encode(tmProcess(getDatabaseConnection(),$p,$p['action']==='save'),JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $safe=$e instanceof InvalidArgumentException||$e instanceof DomainException;
    http_response_code($e instanceof DomainException?409:($safe?422:500));
    if(!$safe)error_log('Teacher mapping: '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>$safe?$e->getMessage():'Unable to save mapping. Try again.']);
}
