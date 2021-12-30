<?php
/**
 * Zasadniczy moduł API Allegro
 * @author Piotr Maślanka <piotr.maslanka@henrietta.com.pl>
 * @copyright Copyright (c) by Piotr Maślanka 2013
 *            All rights reserved
 *            
 * When shit hits the fan, check lines 24-34
 */
class AllegroAPI extends SoapClient {
	const COUNTRY_PL = 1;
	const QUERY_ALLEGROWEBAPI = 1;
	const ALLEGRO_APIKEY = 'f2b5d3dd';
	
	/**
	 * Konwertuj $x do postaci long wymaganej przez WSDL
	 * @param $x zmienna do konwersji
	 * @param SOAP-owy "long" nadający się do konsumpcji przez AllegroAPI
	 */
	private function tl($x) {
		return new SoapVar($x, XSD_STRING, "string", "http://www.w3.org/2001/XMLSchema");
	}
	
	/**
	 * Konwertuj odebrany int do stringa
	 * UWAGA: to się bardzo chrzani jeśli ID allegro przekroczy 4 miliardy !!!!!!!!!!!!!!
	 * Ratuj się wtedy sam, bo PHP się podda, a z nim większość obecnych implementacji AllegroAPI w PHP.
	 * Głupi język.
	 */
	private function lt($x) {
		$x = (float)$x;
		if ($x < 0) $x = 2147483647 - $x;	// U2 do unsigneda
		return sprintf("%.0f", $x);		
	}
	
	/**
	 * Absolutny SOAP-owy klasyk. Kto potrzebuje obiektów, kiedy masz tablice haszujące?
	 * @param $object Obiekt
	 * @return najpewniej tablica haszująca z reprezentacją obiektu
	 */
	public function objectToArray($object) {
		if(!is_object( $object ) && !is_array( $object )) return $object;
		if(is_object($object)) $object = get_object_vars( $object );
		return array_map(array('AllegroAPI','objectToArray'), $object );
	}	
	
	public function __construct() {
		parent::__construct('http://webapi.allegro.pl/uploader.php?wsdl');
	}
	
	/**
	 * Zaloguj użytkownika
	 * @param $user użytkownik
	 * @param $pass hasło
	 * @throws SoapFault
	 */
	public function login($user, $pass) {
		$country = AllegroAPI::COUNTRY_PL;
		$version = $this->doQuerySysStatus(AllegroAPI::QUERY_ALLEGROWEBAPI, $country, AllegroAPI::ALLEGRO_APIKEY);
		$session = $this->session = $this->__call(
			'doLogin',
			array(
				'user-login' => $user,
				'user-password' => $pass,
				'country-code' => $country,
				'webapi-key' => AllegroAPI::ALLEGRO_APIKEY,
				'local-version' => $version['ver-key']
			)
		);
		$this->sesskey = $this->session['session-handle-part'];
	}
	
	
	private function _getTransactionsInfoHelper($transaction_ids) {
		if (count($transaction_ids) > 25) die('Max 25');
		$ret = $this->__call('doGetPostBuyFormsDataForSellers', array(
			'session-id' => $this->sesskey,
			'transactions-ids-array' => $transaction_ids
		));
		return $this->objectToArray($ret);
	} 
	
	/**
	 * Pobierz informację o transakcjach z danej oferty
	 * @return array of PostBuyFormDataStruct[], as per http://allegro.pl/webapi/documentation.php/show/id,703#method-output
	 */
	public function getTransactionsInfo($offer_id) {
		$ret = $this->__call('doGetTransactionsIDs',
			array(
				'session-handle' => $this->sesskey,
				'items-id-array' => array($this->tl($offer_id)),
				'user-role' => 'seller'
		));
		
		$transaction_ids = array();
		foreach ($ret as $e) $transaction_ids[] = $this->lt($e);
		
		// this needs to be split into rounds of 25, because that's max from Allegro API
		$collector = array(); 	// this will be cumulated response from doGetPostBuy...
		while (count($transaction_ids) > 0) {
			$sub = array_slice($transaction_ids, 0, 25);
			$transaction_ids = array_slice($transaction_ids, 25);
			$collector = array_merge($collector, $this->_getTransactionsInfoHelper($sub));			
		}
				
		return $collector;
	}
	
	/**
	 * Pobiera dane kontaktowe kontrahentów odnośnie danej oferty
	 */
	public function getContactData($offer_id) {
		$ret = $this->__call('doGetPostBuyData', array(
			'session-handle' => $this->sesskey,
			'items-array' => array($this->tl($offer_id))
		));
        
		$ret = $this->objectToArray($ret);
		
		// Extract customer data from this
		$cust_data = array();
		foreach ($ret as $e) {
			foreach ($e['users-post-buy-data'] as $r) {
				$r['user-data']['user-id'] = $this->lt($r['user-data']['user-id']); 
				$cust_data[] = $r;
			}			
		}
		
		return $cust_data;
	}
	
	
	/**
	 * Pobierz informację o metodach dostarczeń
	 * @return array(id_metody => array(opis, czy_jest_darmowa, czy_przy_odbiorze))
	 */
	public function getShipmentMethods() {
		$ret = $this->__call('doGetShipmentData', array(
			'country-id' => AllegroAPI::COUNTRY_PL,
			'webapi-key' => AllegroAPI::ALLEGRO_APIKEY
		));
		$ret = $this->objectToArray($ret);

		$shipdata = array();
		foreach ($ret['shipment-data-list'] as $she) {
			$is_free = false;
			if (strpos($she['shipment-name'], 'osobisty')) $is_free = true;			 
			$czy_przy_odbiorze = ($she['shipment-type'] == 2);
			$shipdata[$she['shipment-id']] = array($she['shipment-name'], $is_free, $czy_przy_odbiorze);
		}
		
		return $shipdata;		
	}
	
	/**
	 * Pobierz informację o sprzedanych nrach ofert
	 * @return array z array(nr oferty => array(czy aukcja zamknięta, timestamp wystawienia))
	 */
	public function getSold() {
		$ret = $this->__call('doGetMySoldItems', array('session-id' => $this->sesskey));
		$ret = $ret['sold-items-list'];
		$items = array();
		foreach ($ret as $item) {
			// Verify whether the auction was closed?
			$was_closed = false;
			$was_closed = $was_closed || ($item->{'item-start-quantity'} == $item->{'item-sold-quantity'});
			$was_closed = $was_closed || (time() > $item->{'item-end-time'});
			$items[$this->lt($item->{'item-id'})] = array($was_closed, $item->{'item-start-time'});
		}
		return $items;
	}
	
	/**
	 * Zweryfikuj że aukcja o podanym ID istnieje
	 * @return bool Czy istnieje
	 */
	public function verifyAuction($auction_id) {
		$ret = $this->__call('doGetItemsInfo',
			array(
				'session-handle' => $this->sesskey,
				'items-id-array' => array($this->tl($auction_id)),					
			)
		);
		return !empty($ret['array-item-list-info']);
	}
}
?>