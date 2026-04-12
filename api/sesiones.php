<?php
$allowed_origins = [
    'https://bmaria23ea-ai.github.io',    // GitHub 
    'https://romanalyzer.onrender.com',   // Render
    'http://localhost',                   // ocal
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: https://bmaria23ea-ai.github.io');
}
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Access-Control-Allow-Credentials: false');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

//contraseña
$api_key = getenv('ROM_API_KEY');
$req_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($api_key && $req_key !== $api_key) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

//credenciales de TURSO
define('TURSO_URL',   getenv('TURSO_URL')   ?: '');
define('TURSO_TOKEN', getenv('TURSO_TOKEN') ?: '');

function turso($stmts) {
    $reqs = array_map(fn($s) => ['type'=>'execute','stmt'=>isset($s['args'])
        ?['sql'=>$s['sql'],'named_args'=>array_map(fn($k,$v)=>['name'=>$k,'value'=>['type'=>'text','value'=>(string)$v]],array_keys($s['args']),$s['args'])]
        :['sql'=>$s['sql']]],$stmts);
    $reqs[]=['type'=>'close'];
    $ch=curl_init(TURSO_URL.'/v2/pipeline');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.TURSO_TOKEN,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['requests'=>$reqs])]);
    $res=curl_exec($ch);$err=curl_error($ch);curl_close($ch);
    if($err) throw new Exception($err);
    $d=json_decode($res,true);
    if(!$d) throw new Exception('Turso error');
    return $d['results']??[];
}

turso([['sql'=>"CREATE TABLE IF NOT EXISTS sesiones(id TEXT PRIMARY KEY,data TEXT NOT NULL,created_at TEXT DEFAULT(strftime('%Y-%m-%dT%H:%M:%fZ','now')))"]]);

$method=$_SERVER['REQUEST_METHOD'];
$id=$_GET['id']??null;

try {
    if($method==='GET'&&!$id&&!isset($_GET['maxid'])){
        $res=turso([['sql'=>"SELECT data FROM sesiones ORDER BY created_at DESC"]]);
        $rows=$res[0]['response']['result']['rows']??[];
        echo '['.implode(',',array_map(fn($r)=>$r[0]['value'],$rows)).']';

    }elseif($method==='GET'&&isset($_GET['maxid'])){
        $res=turso([['sql'=>"SELECT id FROM sesiones"]]);
        $rows=$res[0]['response']['result']['rows']??[];
        $ids=array_map(fn($r)=>intval($r[0]['value']),$rows);
        echo json_encode(['maxId'=>empty($ids)?0:max($ids)]);

    }elseif($method==='POST'){
        $body=json_decode(file_get_contents('php://input'),true);
        if(!$body||!isset($body['id'])){http_response_code(400);echo json_encode(['error'=>'invalid']);exit;}
        turso([['sql'=>"INSERT INTO sesiones(id,data)VALUES(:id,:data)",'args'=>[':id'=>$body['id'],':data'=>json_encode($body)]]]);
        http_response_code(201);echo json_encode(['ok'=>true,'id'=>$body['id']]);

    }elseif($method==='PATCH'&&$id){
        $body=json_decode(file_get_contents('php://input'),true);
        $res=turso([['sql'=>"SELECT data FROM sesiones WHERE id=:id",'args'=>[':id'=>$id]]]);
        $rows=$res[0]['response']['result']['rows']??[];
        if(empty($rows)){http_response_code(404);echo json_encode(['error'=>'not found']);exit;}
        $ses=json_decode($rows[0][0]['value'],true);
        if(array_key_exists('paciente',$body))$ses['paciente']=$body['paciente'];
        if(array_key_exists('notas',$body))$ses['notas']=$body['notas'];
        turso([['sql'=>"UPDATE sesiones SET data=:data WHERE id=:id",'args'=>[':data'=>json_encode($ses),':id'=>$id]]]);
        echo json_encode(['ok'=>true]);

    }elseif($method==='DELETE'&&$id){
        turso([['sql'=>"DELETE FROM sesiones WHERE id=:id",'args'=>[':id'=>$id]]]);
        echo json_encode(['ok'=>true]);

    }else{http_response_code(405);echo json_encode(['error'=>'not allowed']);}
} catch(Exception $e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
