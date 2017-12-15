<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require 'vendor/autoload.php';
require '../include.me.php';

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

class ServiceController extends SFCDB
{

	use WithField;

	public function hello(Request $request, Response $response){
		
		$name = $request->getAttribute('name');
    	$response->getBody()->write("Hello, $name");

    	return $response;	
	}

	public function getWeightHdd(Request $request, Response $response){
		
		$palletId = $request->getAttribute('id');
		
		$sql_text = "
		    SELECT
		      SFCMCUPC.SFC80_EDASN
		    FROM SFCMCPAL
		    LEFT JOIN SFCMCUPC ON
		      SFCMCPAL.SFC90_PACKAGEID = SFCMCUPC.SFC80_PACKAGEID
		    LEFT JOIN SFCWGHDD ON
		      SFCMCUPC.SFC80_EDASN = SFCWGHDD.SFC20_HDDSN
		    WHERE SFCMCPAL.SFC90_PALLETID = '{$palletId}' AND SFCWGHDD.SFC20_WGHDD IS NULL ";
		
		$data = parent::getArrays( $sql_text );
		if( sizeof( $data ) > 0 ){
			$EDASN = "";
	  		foreach ($data as $key => $value) {
	  		  # code...
	  		  if( $key > 3 ){
	  		    $EDASN .= ", ...";
	  		    break;
	  		  }else{
	  		    
	  		    if( $key > 0 ){
	  		      $EDASN .= ", " . $value['SFC80_EDASN'];  
	  		    }else{
	  		      $EDASN .= $value['SFC80_EDASN'];
	  		    };  
	  		  }
	  		}

		  	$this->message = "HDD S/N: {$EDASN} Weight missing !";
		  	$this->error = true;
		  	$this->sql_text = $sql_text;
		  	$this->data = $data;

		  	return $response->withJSON($this->__toObject(), 200, JSON_UNESCAPED_UNICODE);
		}
		
		$sql_text = "
		    SELECT 
		      NVL(PALLET.WGPAL, 0) WGPAL,
		      NVL(PALLET.WGANGLE, 0) WGANGLE,
		      NVL(MCBOX.SCCA_WGMC, 0) WGMC,
		      NVL(HDD.SFC20_WGHDD, 0) WGHDD,
		      MCBOX.QST21_BUYMDNM,
		      HDD.SFC90_ORDNO,
		      MCBOX.QST21_MCQTY,
		      HDD.WGAVG
		    FROM (
		      SELECT SUM(SFCWGHDD.SFC20_WGHDD) SFC20_WGHDD, SFCMCPAL.SFC90_ORDNO, AVG(SFC20_WGHDD) WGAVG FROM SFCMCPAL
		      LEFT JOIN SFCMCUPC ON
		        SFCMCPAL.SFC90_PACKAGEID = SFCMCUPC.SFC80_PACKAGEID
		      LEFT JOIN SFCWGHDD ON
		        SFCMCUPC.SFC80_EDASN = SFCWGHDD.SFC20_HDDSN
		      WHERE SFCMCPAL.SFC90_PALLETID = '{$palletId}'
		      GROUP BY SFCMCPAL.SFC90_ORDNO
		    ) HDD
		    LEFT JOIN (
		      SELECT * FROM (
		        SELECT SCC_VALUE WGPAL FROM SFCCONFIG WHERE SCC_KEY = 'WG' AND SCC_PARAM = 'PALLET'
		      )t1
		      LEFT JOIN (
		        SELECT SCC_VALUE WGANGLE FROM SFCCONFIG WHERE SCC_KEY = 'WG' AND SCC_PARAM = 'ANGLE'
		      )t2 ON 1 = 1
		    )PALLET ON 1 = 1
		    LEFT JOIN (
		      SELECT QST21_BUYMDNM, QST10_ORDNO, SCCA_WGMC, QST21_MCQTY
		      FROM (
		        SELECT QST21_BUYMDNM, QST10_ORDNO, QST21_MCQTY
		        FROM QSTORD ORD
		        LEFT JOIN QSTMODEL MODEL ON
		          MODEL.QST21_MODEL = ORD.QST10_MODEL
		        GROUP BY QST21_BUYMDNM, QST10_ORDNO, QST21_MCQTY
		      ) QSTMODEL
		      LEFT JOIN (
		        SELECT SCCA_WGMC, SCCA_BUYMDNM FROM SFCC_MODELCTL
		      ) SFCC_MODELCTL ON 
		        QSTMODEL.QST21_BUYMDNM = SFCC_MODELCTL.SCCA_BUYMDNM 
		    )MCBOX ON
		      HDD.SFC90_ORDNO = MCBOX.QST10_ORDNO ";

		$conn = parent::getConnection();
		$stid = oci_parse($conn, $sql_text);
		$r = oci_execute($stid);
		$obj = oci_fetch_object($stid);
		oci_free_statement($stid);
		oci_close($conn);

		if(!$obj){
			$this->message = "{$PALLETID} not foud weight data !";
			$this->error = true;
		}

		if(!$obj->WGPAL){
			$this->message = "Pallet can not config weight !";
			$this->error = true;
		}

		if(!$obj->WGANGLE){
		  	$this->message = "Pallet can not config angle weight !";
			$this->error = true;
		}

		if(!$obj->WGMC){
		  	$this->message = "Model `{$obj->QST21_BUYMDNM}` can not config master carton weight !";
			$this->error = true;
		}

	  	$this->sql_text = $sql_text;
	  	$this->data = $obj;
		return $response->withJSON($this->__toObject(), 200, JSON_UNESCAPED_UNICODE);
	}

	public function getPalletInfo(Request $request, Response $response){
		$RESP;
		$MY = 0;
		$CH = 0;
		$TH = 0;
		$QTYSUM = 0;
		$palletId = $request->getAttribute('id');

		$sql_text = "SELECT DISTINCT PAL.SFC90_PALLETID, PAL.SFC90_LINECD, PAL.SFC90_ORDNO, MD.QST21_WDMODEL, PQTY.SFC90_QTY,  HDDCO.SFC80_HDDCOO, HDDCO.SFC80_QTY
				FROM 	SFCMCPAL PAL
				LEFT JOIN ( SELECT OD.QST10_ORDNO,OD.QST10_MODEL,MD.QST21_WDMODEL FROM QSTORD OD LEFT JOIN QSTMODEL MD ON OD.QST10_MODEL = MD.QST21_MODEL ) MD ON MD.QST10_ORDNO = PAL.SFC90_ORDNO
				LEFT JOIN ( SELECT PP.SFC90_PALLETID, COUNT (*) SFC90_QTY FROM SFCMCPAL PP GROUP BY PP.SFC90_PALLETID) PQTY ON PQTY.SFC90_PALLETID = PAL.SFC90_PALLETID
				LEFT JOIN ( SELECT PAL.SFC90_PALLETID,
					CASE
						WHEN UPC.SFC80_HDDCOO = 'Thailand' Then 'TH'
						WHEN UPC.SFC80_HDDCOO = 'Malaysia' Then 'MY'
						WHEN UPC.SFC80_HDDCOO = 'China' Then 'CH'
					END as SFC80_HDDCOO, COUNT(*) AS SFC80_QTY
					FROM SFCMCUPC UPC LEFT JOIN SFCMCPAL PAL ON UPC.SFC80_PACKAGEID = PAL.SFC90_PACKAGEID
					GROUP BY PAL.SFC90_PALLETID, UPC.SFC80_HDDCOO
				)HDDCO ON HDDCO.SFC90_PALLETID = PAL.SFC90_PALLETID
				WHERE PAL.SFC90_PALLETID = '$palletId' ";

		$conn = parent::getConnection();
		$stid = oci_parse($conn, $sql_text);
		$REST = oci_execute($stid);
		$ERR = oci_error($stid);
		$nrows = oci_fetch_all($stid, $res, null, null, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
		oci_free_statement($stid);
		oci_close($conn);

		if ($REST && $nrows > 0){
			while (list($key, $ROW) = each($res)) {
				
				$RESP['SFC90_PALLETID'] = $ROW['SFC90_PALLETID'];
				$RESP['SFC90_WDMODEL'] = $ROW['QST21_WDMODEL'];
				$RESP['SFC90_LINECD'] = $ROW['SFC90_LINECD'];
				$RESP['SFC90_ORDNO'] = $ROW['SFC90_ORDNO'];
				$RESP['SFC90_QTY'] = $ROW['SFC90_QTY'];
				
				if ($ROW['SFC80_HDDCOO'] == 'TH'){ $TH = intval($ROW['SFC80_QTY']);}
				if ($ROW['SFC80_HDDCOO'] == 'MY'){ $MY = intval($ROW['SFC80_QTY']);}
				if ($ROW['SFC80_HDDCOO'] == 'CH'){ $CH = intval($ROW['SFC80_QTY']);}

				$QTYSUM += intval($ROW['SFC80_QTY']);
			}

			while (strlen($MY)<4){ $MY = '0'.$MY; }
			while (strlen($TH)<4){ $TH = '0'.$TH; }
			while (strlen($CH)<4){ $CH = '0'.$CH; }

			$RESP['SFC90_ORIGIN'] = "$MY/MY-$TH/TH-$CH/CH";

			if ($QTYSUM > 0)
				$RESP['SFC90_SUMQTY'] = $QTYSUM ;
		}

		if($ERR){
			$this->message = $ERR['message'];
	  		$this->error = true;	
		}
	  	
	  	$this->sql_text = $sql_text;
	  	$this->data = $RESP;

	  	return $response->withJSON($this->__toObject(), 200, JSON_UNESCAPED_UNICODE);
	}

	public function userInfo(Request $request, Response $response){
		$user = $request->getAttribute('user');
		$sql_text = " SELECT 
						SCC12_EMPNO,
						SCC12_DEPTNM,
						SCC12_LINENM,
						SCC12_EMPNM,
						SCC12_UPDUSRCD,
						SCC12_REGYMD,
						SCC12_CHGYMD,
						SCC12_SPCFLG
					FROM SFCCTLEMP 
					WHERE SCC12_EMPNO LIKE '%{$user}%' 
					ORDER BY SCC12_EMPNO, SCC12_EMPNM ";

		$data = parent::getArrays($sql_text);
		if(sizeof($data) <= 0){
			$this->error = true;
			$this->message = " Employee `{$user}` not found data information !";
		}

		$this->sql_text = $sql_text;
	  	$this->data = $data;

	  	return $response->withJSON($this->__toObject(), 200, JSON_UNESCAPED_UNICODE);
	}

	public function authorization(Request $request, Response $response){
		$params = $request->getParams();
		$username = $params['username'];
		$password = $params['password'];

		$sql_text = " SELECT * FROM SFCCTLEMP WHERE SCC12_EMPNO = '{$username}' AND SCC12_PASSWD = '{$password}' ";
		$data = parent::getArrays($sql_text);
		$this->sql_text = $sql_text;

		if(sizeof($data) <= 0){

			$this->error = ture;
			$this->message = "Username or Password Wrong !";
		}

	  	$this->data = $data;
	  	return $response->withJSON($this->__toObject(), 200, JSON_UNESCAPED_UNICODE);		
	}

	public function __construct(){
		parent::__construct();
	}
}

$app = new \Slim\App;
$app->get('/hello/{name}', 'ServiceController::hello');
$app->get('/getWeightHdd/{id}', \ServiceController::class . ':getWeightHdd');
$app->get('/getPalletInfo/{id}', \ServiceController::class . ':getPalletInfo');
$app->get('/userInfo/{user}', \ServiceController::class . ':userInfo');
$app->post('/author', \ServiceController::class . ':authorization');
$app->run();

?>