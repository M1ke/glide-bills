<?php

class Glide {
	protected $url='https://glide.co.uk/api/4.0/';
	protected $methods=array('frontEnd'=>array(
	),'portal'=>array(
	),'signUp'=>array(
		'payment'=>array(
			'registerCard'=>false,
			'getClientAddresses'=>false,
		),
		'quote'=>array(
			'allServices'=>true,
			'servicePrice'=>true,
			'telephoneConnectionCharge'=>true,
			'broadbandActivationCharge'=>true,
			'broadbandAvailability'=>true,
			'telephoneConnectionType'=>true,
			'telephoneAddress'=>true,
			'email'=>false,
		),
	),);
	protected $service_names=array('gas'=>'Gas','electricity'=>'Electricity','water'=>'Water','telephone'=>'Phone','broadband'=>'Internet','tv'=>'TV license');

	protected $api_key;
	protected $services;
	protected $last_sent;

	public $log_dir='logs';
	public $broadbandTypes=array('adsls');
	public $telOrderTypes=array('restart', 'takeover', 'transfer', 'convert','new');

	function __construct($api_key){
		if (empty($api_key)){
			throw new GlideException('You must set a valid Glide API key.');
		}
		$this->api_key=$api_key;
		$this->services=array_keys($this->service_names);
		return $this;
	}

	function get_last_sent(){
		return $this->last_sent;
	}

	function get_services(){
		return $this->service_names;
	}

	function get_methods(){
		foreach ($this->methods as $master => $items){
			foreach ($items as $item => $functions){
				foreach ($functions as $function => $exists){
					if ($exists){
						$return[$master.'_'.$item.'_'.$function]=$master.'/'.$item.'/'.$function;
					}
				}
			}
		}
		return $return;
	}

	function __call($name,$args){
		$route=str_replace('_','/',$name);
		$data=$args[0];
		if (isset($args[1])){
			$extra=$args[1];
		}
		$valid_method='valid_'.$name;
		if (method_exists($this,$valid_method)){
			$data=$this->$valid_method($data,$extra);
		}
		elseif (empty($data) or !is_array($data)){
			throw new GlideException('No data was submitted for the method '.$name.'.');
		}
		$res=$this->send_request($data,$route);
		if ($res['error']==1){
			$error_method='error_'.$name;
			if (method_exists($this,$error_method)){
				return $this->$error_method($res,$data);
			}
			$this->exception_message($res,$data);
		}
		return $this->return_data($res,$name);
	}

	private function error_signUp_quote_allServices($res,Array $data){
		if (isset($data['water']) and strpos($res['message'],'We are unable to supply water')!==false){
			unset($data['water']);
			return $res=$this->signUp_quote_allServices($data);
		}
		$this->exception_message($res,$data);
	}

	private function valid_signUp_quote(Array $data,&$errors=array()){
		if (!Glide::_make_postcode($data['postcode'])){
			$errors['postcode']='The postcode entered was not valid.';
		}
		if (!isset($data['tenants']) or !is_numeric($data['tenants'])){
			$errors['tenants']='You must enter a valid number of tenants.';
		}
		else {
			$data['tenants'] = (int) $data['tenants'];
		}
		if (!isset($data['term']) or !is_numeric($data['term'])){
			$errors['term']='You must enter a valid contract term.';
		}
		else {
			$data['term'] = (int) $data['term'];
		}
		return $data;
	}

	private function valid_signUp_quote_allServices(Array $data,Array $extra=null){
		$data=$this->valid_signUp_quote($data,$errors);
		if (isset($extra['all'])){
			$services=array_keys($this->get_services());
			foreach ($services as &$service){
				$data[$service]=true;
			}
		}
		$data['capacity']=$data['tenants'];
		$data['minTerm']=$data['term'];
		if (empty($data['broadbandType']) or !is_string($data['broadbandType'])){
			unset($data['broadband']);
		}
		$this->valid_check_errors($errors,$data);
		return $data;
	}

	private function valid_signUp_quote_servicePrice(Array $data){
		$data=$this->valid_signUp_quote($data,$errors);
		if (!isset($this->service_names[$data['service']])){
			$errors['service']='The selected service ('.$data['service'].') is not valid.';
		}
		$data['period']=$data['term'];
		if ($data['service']=='broadband'){
			if (empty($data['broadbandType'])){
				$errors['broadbandType']='You must enter a broadband type to check broadband prices.';
			}
		}
		$this->valid_check_errors($errors,$data);
		return $data;
	}

	private function valid_signUp_quote_broadbandActivationCharge(Array $data){
		$data['minTerm']=$data['term'];
		$data['type']='activation';
		$this->valid_check_errors($errors,$data);
		return $data;
	}

	protected function valid_check_errors($errors=null,$data=null){
		if (!empty($errors)){
			throw new GlideException('There was a problem with the information submitted.',array(
				'errors'=>$errors,
				'data'=>$data,
			));
		}
	}

	protected function return_data($res){
		return $res;
	}

	protected function exception_message($res,Array $data=array()){
		throw new GlideException('The Glide server reported an error: '.$res['message'],array(
			'data'=>$data,
		));
	}

	protected function send_request(Array $data,$route){
		if ($route[0]=='/'){
			$route=substr($route,1);
		}
		$check_route=explode("/",$route);
		$methods=$this->methods;
		foreach ($check_route as $step){
			if (!isset($methods[$step])){
				throw new GlideException('The route '.$route.' could not be found. It failed looking for "'.$step.'"');
			}
			$methods=$methods[$step];
		}

		$data['key']=$this->api_key;
		$url=$this->url.$route.'.json';

		$this->last_sent=$data;
		$res=$this->_json_post($url,$data);

		$res_arr=json_decode($res,true);
		if ($res_arr===false or $res_arr===null){
			if (empty($res)){
				$err_msg='No data was returned from '.$url.'.';
			}
			else {
				$this->_file_save($this->log_dir.'/glide_error.html',$res,true);
				$err_msg='The JSON data could not be parsed from '.$url.'.';
			}
			throw new GlideException($err_msg,array(
				'data'=>$data,
			));
		}
		return $res_arr;
	}

	private function service_name($service){
		return $this->service_names[$service] || ucfirst($service);
	}

	/* Utility functions */

	protected function _json_post($url,$data){
		$curl_json=new Curl\Json;
		$result=$curl_json->post($url,$data)->response;
		return $result;
	}

	/* These functions would ideally be provided by third party dependencies pulled down by
		Composer. At present it's difficult to work out which libraries should be used and 
		want to avoid overkill.
		
		For any developers working on forking this project replacing these with external
		dependencies would be a good task to work on - will require a small amount of changes
		to the main library code to check method names and parameters
	*/

	protected function _file_save($file,$string,$overwrite=false){
		$fh=@fopen($file,$overwrite ? 'w' : 'a');
		if (!empty($fh)){
			fwrite($fh,$string);
			fclose($fh);
			return true;
		}
		else {
			return false;
		}
	}

	protected function _make_postcode(&$string,$blank=null){
		$string=trim($string);
		if (!empty($string)){
			// Permitted letters depend upon their position in the postcode.
			$alpha1="[abcdefghijklmnoprstuwyz]"; // Character 1
			$alpha2="[abcdefghklmnopqrstuvwxy]"; // Character 2
			$alpha3="[abcdefghjkpmnrstuvwxy]"; // Character 3
			$alpha4="[abehmnprvwxy]"; // Character 4
			$alpha5="[abdefghjlnpqrstuwxyz]"; // Character 5
			// Expression for postcodes: AN NAA, ANN NAA, AAN NAA, and AANN NAA with a space
			$pcexp[0]='/^('.$alpha1.'{1}'.$alpha2.'{0,1}[0-9]{1,2})([\s]{0,})([0-9]{1}'.$alpha5.'{2})$/';
			// Expression for postcodes: ANA NAA
			$pcexp[1]='/^('.$alpha1.'{1}[0-9]{1}'.$alpha3.'{1})([\s]{0,})([0-9]{1}'.$alpha5.'{2})$/';
			// Expression for postcodes: AANA NAA
			$pcexp[2]='/^('.$alpha1.'{1}'.$alpha2.'{1}[0-9]{1}'.$alpha4.')([\s]{0,})([0-9]{1}'.$alpha5.'{2})$/';
			// Exception for the special postcode GIR 0AA
			$pcexp[3]='/^(gir)(0aa)$/';
			// Standard BFPO numbers
			$pcexp[4]='/^(bfpo)([0-9]{1,4})$/';
			// c/o BFPO numbers
			$pcexp[5]='/^(bfpo)(c\/o[0-9]{1,3})$/';
			// Overseas Territories
			$pcexp[6]='/^([a-z]{4})(1zz)$/i';
			// Load up the string to check, converting into lowercase
			$string=strtolower($string);
			// Assume we are not going to find a valid postcode
			$valid=false;
			// Check the string against the six types of postcodes
			foreach ($pcexp as $regexp){
				if (preg_match($regexp,$string,$matches)){
					// Load new postcode back into the form element
					$string=strtoupper($matches[1].' '.$matches[3]);
					// Take account of the special BFPO c/o format
					$string=preg_replace ('/C\/O/','c/o ',$string);
					// Remember that we have found that the code is valid and break from loop
					$valid=true;
					break;
				}
			}
			return $valid;
		}
		elseif ($blank){
			$string=null;
			return true;
		}
		return false;
	}
}

class GlideException extends Exception {
	private $errors=array();
	private $data=array();

	function __construct($message,Array $debug=null){
		$this->errors=$debug['errors'];
		$this->data=$debug['data'];
		parent::__construct($message);
	}

	function get_errors(){
		return $this->errors;
	}

	function get_data(){
		return $this->data;
	}
}
