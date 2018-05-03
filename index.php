<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require 'vendor/autoload.php';

DB::$user = 'root';
DB::$password = '';
DB::$dbName = 'android_exp';
DB::$host = 'localhost'; //defaults to localhost if omitted
DB::$port = '3306'; // defaults to 3306 if omitted
DB::$encoding = 'utf8'; // defaults to latin1 if omitted

		
trait WithField
{
    public $message;
    public $sql_text;
    public $data;
    public $error = false;
    public function __toObject(){
    	$stdClass = new stdClass();
    	$stdClass->message = $this->message;
    	$stdClass->sql = $this->sql_text;
    	$stdClass->data = $this->data;
    	$stdClass->error = $this->error;

    	return $stdClass;
    }
}

class ServiceController
{

	use WithField;

	public static function hello(Request $request, Response $response){
		
		$name = $request->getAttribute('name');
    	$response->getBody()->write("Hello, $name");

    	return $response;	
	}

	// public function __construct(){
		
	// }

	public static function getInfo(Request $request, Response $response){

		$sql_text  = " select * from registers ";		
		$data = DB::query($sql_text);

		$obj = new stdClass();
		$obj->result = $data; 
		return $response->withJSON($obj, 200, JSON_UNESCAPED_UNICODE);
	}

	public static function insert(Request $request, Response $response){
		
		$param = $request->getParsedBody();
		$name = $param['name'];
		$lastname = $param['lastname'];
		$school = $param['school'];
		$sql_text = " insert into registers(name, lastname, height_school) values ('{$name}','{$lastname}','{$school}') ";
		$affect = DB::query($sql_text);

		$obj = new stdClass();
		$obj->sql = $sql_text;
		$obj->row = $affect;
		$obj->attr = [$name, $lastname, $school];
	

		return $response->withJSON($obj, 200, JSON_UNESCAPED_UNICODE);

	}

}

$app = new \Slim\App;
$app->get('/hello/{name}', 'ServiceController::hello');
$app->get('/info', 'ServiceController::getInfo');
$app->post('/insert', 'ServiceController::insert');
$app->run();

?>