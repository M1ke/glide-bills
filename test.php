<?php
require 'Glide.php';
require 'vendor/autoload.php';

class testGlide extends PHPUnit_Framework_TestCase {
	private $glide;
	private $service_data=array(
		'postcode'=>'m1 1dz',
		'tenants'=>5,
		'term'=>12,
	);

	function __construct(){
		$this->glide=$this->glide_new();
	}

	private function glide_new(){
		$api_key=shell_exec('cat api_key');
		if (empty($api_key)){
			throw new Exception('Create a file called "api_key" which constains your Glide API key.');
		}
		return new Glide($api_key);
	}

	private function setup_services(){
		$services=$this->glide->get_services();
		foreach ($services as $name => $title){
			$arr[$name]=true;
		}
		return $arr;
	}

	private function handle_exception(GlideException $e){
		echo PHP_EOL.'Caught exception: '.$e->getMessage().PHP_EOL.'Data: '.print_r($e->get_data(),true).PHP_EOL.'Errors: '.print_r($e->get_errors()).PHP_EOL;
	}

    function testGlideQuoteServicesReturnsArray(){
    	$res=$this->glide->signUp_quote_allServices($this->service_data + $this->setup_services());
    	$this->assertTrue(is_numeric($res['gas']['monthly_fee']));
    	$this->assertTrue(is_numeric($res['electricity']['monthly_fee']));
    	$this->assertTrue(is_numeric($res['telephone']['monthly_fee']));
    	$this->assertTrue(is_numeric($res['tv']['monthly_fee']));
    }

    function testGlideQuoteServicesAllReturnsArray(){
    	$res=$this->glide->signUp_quote_allServices($this->service_data,array('all'=>true));
    	$this->assertTrue(is_numeric($res['gas']['monthly_fee']));
    	$this->assertTrue(is_numeric($res['electricity']['monthly_fee']));
    	$this->assertTrue(is_numeric($res['telephone']['monthly_fee']));
    	$this->assertTrue(is_numeric($res['tv']['monthly_fee']));
    }

	function testGlideQuoteServiceReturnsArray(){
		$res=$this->glide->signUp_quote_servicePrice($this->service_data + array('service'=>'electricity'));
    	$this->assertTrue(is_numeric($res['monthly_fee']));
    	$this->assertTrue(is_numeric($res['tenant_month']));
    	$this->assertTrue(is_numeric($res['tenant_week']));
	}

	function testGlideExceptionNoPostcode(){
		try {
			$data=$this->service_data;
			unset($data['postcode']);
			$res=$this->glide->signUp_quote_allServices($data);
		}
		catch (GlideException $e){
			$errors=$e->get_errors();
		}
		$this->assertEquals(array_keys($errors),array('postcode'));
	}

	function testGlideExceptionNoTerm(){
		try {
			$data=$this->service_data;
			unset($data['term']);
			$res=$this->glide->signUp_quote_allServices($data);
		}
		catch (GlideException $e){
			$errors=$e->get_errors();
		}
		$this->assertEquals(array_keys($errors),array('term'));
	}

	function testGlideExceptionNoTenants(){
		try {
			$data=$this->service_data;
			unset($data['tenants']);
			$res=$this->glide->signUp_quote_allServices($data);
		}
		catch (GlideException $e){
			$errors=$e->get_errors();
		}
		$this->assertEquals(array_keys($errors),array('tenants'));
	}

	function testGlideExceptionWrongService(){
		try {
			$res=$this->glide->signUp_quote_servicePrice($this->service_data + array('service'=>'not-a-service'));
		}
		catch (GlideException $e){
			$errors=$e->get_errors();
		}
		$this->assertEquals(array_keys($errors),array('service'));
	}

	/*********************

		# fails with any value tested so far:

	function testGlideTelephoneConnectionTypeNumber1(){
		try {
			$res=$this->glide->signUp_quote_telephoneConnectionType(array(
				'cli'=>'+44.01617631111',
				'cli'=>'00441617631111',
				'cli'=>'0161 763 1111',
			));
		}
		catch (GlideException $e){
			echo PHP_EOL.'Caught exception: '.$e->getMessage().PHP_EOL;
		}
		print_r($res);
		$this->assertTrue(is_array($res));
	}
	*********************/

	function testGlideBroadbandActivationCharge(){
		try {
			$res=$this->glide->signUp_quote_broadbandActivationCharge(array(
				'term'=>12,
			));
		}
		catch (GlideException $e){
			$this->handle_exception($e);
		}
		$this->assertTrue(isset($res['price']));
		$this->assertTrue(empty($res['price']) or is_numeric($res['price']));
	}

	function testGlideTelephoneConnectionCharge(){
		try {
			$res=$this->glide->signUp_quote_telephoneConnectionCharge(array(
				'tenants'=>5,
				'period'=>12,
				'orderType'=>'new',
				'broadband'=>true,
			));
		}
		catch (GlideException $e){
			$this->handle_exception($e);
		}
		$this->assertTrue(is_numeric($res['total']));
		$this->assertTrue(is_numeric($res['per_tenant']));
	}

	function testGlideTelephoneConnectionTypeRef(){
		try {
			$res=$this->glide->signUp_quote_telephoneConnectionType(array(
				'addressReference'=>'A14321522680',
			));
		}
		catch (GlideException $e){
			$this->handle_exception($e);
		}
		$this->assertTrue(in_array($res['type'],$this->glide->telOrderTypes));
	}

	function testGlidetelephoneAddress(){
		try {
			$res=$this->glide->signUp_quote_telephoneAddress(array(
				'buildingNumber'=>'26',
				'thoroughFare'=>'Lever Street',
				'town'=>'Manchester',
				'postcode'=>'M1 1DZ',
			));
		}
		catch (GlideException $e){
			$this->handle_exception($e);
		}
		$this->assertTrue(strlen($res['addressReference'])>0);
		$this->assertTrue(is_array($res['listOfTechnologies']));
	}

	function testGlideRouteFailure(){
        $method=new ReflectionMethod('Glide','send_request');
 
        $method->setAccessible(TRUE);
		$this->setExpectedException('GlideException');
 
        $method->invoke($this->glide_new(),array(),'made/up/route');
    }
}
