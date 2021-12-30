<?php 
/**
 * Moduł tworzenia zamówienia w bazie Presty
 * @author Piotr Maślanka <piotr.maslanka@henrietta.com.pl>
 * @copyright Copyright (c) by Piotr Maślanka 2013
 *            All rights reserved
 *
 */

require_once('TableWeed.php');

/**
 * Ogólny modus operandii - za pomocą metod add* dodajesz mu dane
 * @author Henrietta
 *
 */
class AMOrder {
	// domyslne moduly do tworzenia adresu
	const ID_ACOUNTRY = 14;
	const ID_ASTATE = 0;
	
	// domyslne moduly do tworzenia carta
	const ID_CGUEST = 1;
	const ID_CCURRENCY = 4;
	const ID_CLANG = 6;
	const ID_CCARRIER = 0;
	
	// domyslne stale do tworzenia zamowienia
	const ID_OLANG = 6;
	const ID_OCURRENCY = 4;
	const ID_OMODULE_zapobraniem = 'maofree_cashondeliveryfee';	// domyslny moduł obsługi płatności za pobraniem
	const ID_OMODULE_bankwire = 'bankwire';	// domyslny modul obslugi platnosci z przedplata
	
	const ID_OSDEFAULT_CASHONDELIVERY = 3;	// domyslny id_order_state gdy za pobraniem
	const ID_OSDEFAULT_BANKWIRE = 10; // domyslny id_order_state gdy przelewem
	const ID_OSEMPLOYEE = 0; // domyslny pracownik order_state
	
	public $customer_id = null;
	private $shipment_methods = null;
	public $cart_id = null;
	public $carrier_id = null;
	public $order_id = null;
	private $items = array();	// array(ID produktu => ilość)
	
	private $za_pobraniem = null;	// czy dostawa za pobraniem
	
	public function __construct($customer_id, $shipment_methods) {
		$this->customer_id = $customer_id;
		$this->shipment_methods = $shipment_methods;
	}
	
	/**
	 * Tworzy odpowiednie rekordy w cart_product
	 * @param int $cart_id Identyfikator koszyka
	 */
	public function generateCartProducts($items) {
		$text_now = date('Y-m-d H:i:s', time());
		
		foreach ($items as $item => $quantity) {
			$quantity = 1; 		// TODO: debug!! always 1
			$q = 'INSERT INTO '._DB_PREFIX_.'cart_product VALUES ('.$this->cart_id.', '.$item.', 0, '.$quantity.', "'.$text_now.'")';
			if (!Db::getInstance()->Execute($q))
				throw new Exception('SQL failure: inserting new cart product');
			}			
		$this->items = $items;
	}
	
	/**
	 * @param $postage Ile kasy za dostawę, brutto
	 * @param $delivery_addr ID adresu dostawy
	 * @param $invoice_addr ID adresu faktury
	 * @param $tran transaction section for this order
	 */
	public function createOrder($postage, $delivery_addr, $invoice_addr, $tran) {
		
		$securekey = md5(uniqid(rand(), true));
		$text_now = date('Y-m-d H:i:s', time());
		
		if ($this->za_pobraniem) {
			$payment = 'POBRANIE';
			$pmodule = AMOrder::ID_OMODULE_zapobraniem;
		} else {
			$payment = 'Przelew bankowy';
			$pmodule = AMOrder::ID_OMODULE_bankwire;
		}		
		
		// allocate new invoice number
		$q = 'SELECT MAX(invoice_number)+1 FROM '._DB_PREFIX_.'orders';
		$q = Db::getInstance()->ExecuteS($q);
		$invoice_number = $q[0]['MAX(invoice_number)+1'];
		
		// allocate new delivery number
		$q = 'SELECT MAX(delivery_number)+1 FROM '._DB_PREFIX_.'orders';
		$q = Db::getInstance()->ExecuteS($q);
		$delivery_number = $q[0]['MAX(delivery_number)+1'];
		
		// start construction query
		$q = 'INSERT INTO '._DB_PREFIX_.'orders VALUES (NULL, '.$this->carrier_id.', '.AMOrder::ID_OLANG.', '.$this->customer_id.', ';
		$q .= $this->cart_id.', '.AMOrder::ID_CCURRENCY.', '.$delivery_addr.', '.$invoice_addr.', "'.pSQL($securekey).'", "'.pSQL($payment).'", ';
		$q .= '1, "'.pSQL($pmodule).'", 0, 0, "", "", 0, ';
		// start from total_paid		
		
		// Ascertain what tax was paid
		$tw = new TableWeed();		
				
		// Build an associated array: array() of array(auction name, quantity)
		$assoc_array = array();
		
		// Build a hashmap: array(presta_product_id => array(unit_cost, quantity))
		// These are financial calculations. I need those values as strings, because
		// serious calculations need serious maths. Bring out the BCMath.
		$itemshm = array();
		foreach ($tran['post-buy-form-items'] as $elem) {
			// Check if this offer is tracked
			if (!$tw->offer_exists($elem['post-buy-form-it-id']))
				// Check if we are allowed to migrate it nevertheless
				if (!TableWeed::DO_IMPORT_IF_NOT_FOUND_IN_TABLE)
					throw new Exception('Order associated with untracked offer and not allowed to migrate nevertheless');
			
			$itemshm[$tw->product_id_by_offer($elem['post-buy-form-it-id'])] = array(
					(string)$elem['post-buy-form-it-price'], 
					(string)$elem['post-buy-form-it-quantity'],
			);
			$assoc_array[] = array($elem['post-buy-form-it-title'], (int)$elem['post-buy-form-it-quantity']);
		}

		// Calculate tax rate		
		$total_netto_cost = '0';
		$total_brutto_cost = '0';		
		foreach ($itemshm as $product_id => $vector) {
			list($unit_cost_brutto, $quantity) = $vector;
			$taxrate = bcdiv((string)$tw->get_tax_rate_by_product_id($product_id), '100', 5); // as percents please
			
			$unit_cost_netto = bcdiv($unit_cost_brutto, bcadd('1', $taxrate, 5));
			$total_item_cost_netto = bcmul($unit_cost_netto, $quantity, 2);
			$total_item_cost_brutto = bcmul($unit_cost_brutto, $quantity, 2);
			
			// add to totals
			$total_netto_cost = bcadd($total_netto_cost, $total_item_cost_netto, 2);
			$total_brutto_cost = bcadd($total_brutto_cost, $total_item_cost_brutto, 2);			
		}
		
		// starting off from total_paid
		$total_total_cost = bcadd((string)$postage, $total_brutto_cost, 2);	// total brutto + delivery brutto 
		$q .= $total_total_cost.', '.$total_total_cost.', '.$total_netto_cost.', '.$total_brutto_cost.', ';
		//$q .= $postage.', '.Configuration::get('allegrom_carrier_tax_rate').', 0, '.$invoice_number.', '.$delivery_number.', "'.$text_now.'", "0000-00-00 00:00:00", ';
		$q .= $postage.', '.Configuration::get('allegrom_carrier_tax_rate').', 0, 0, 0, "'.$text_now.'", "0000-00-00 00:00:00", ';
		$q .= '0, "'.$text_now.'", "'.$text_now.'", NULL, NULL)';	// two last fields for DHL 
		// start off from invoice number
		
		if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inserting new order');
		$this->order_id = Db::getInstance()->Insert_ID();
		
		
		// OK, that's order_id for us. Now update product_detail, alongside updaing ProductSale entries
		foreach ($itemshm as $product_id => $vector) {
			list($unit_cost_brutto, $quantity) = $vector;
			$product_name = $tw->product_name_by_id($product_id);
			$tax_rate = (int)$tw->get_tax_rate_by_product_id($product_id);
			$q = 'INSERT INTO '._DB_PREFIX_.'order_detail VALUES (NULL, '.$this->order_id.', '.$product_id.', 0, "'.pSQL($product_name).'", ';
			$q .= $quantity.', '.$quantity.', 0, 0, 0, '.$unit_cost_brutto.', 0, 0, 0, 0, NULL, NULL, NULL, NULL, 0, ';
			$q .= '"PTU PL '.$tax_rate.'%", '.$tax_rate.', 0, 0, 0, "", 0, "0000-00-00 00:00:00")';
			
			if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inserting new order detail');
			
			ProductSale::addProductSale((int)$product_id, (int)$quantity);
		}
		
		// set order state
		$q = 'INSERT INTO '._DB_PREFIX_.'order_history VALUES (NULL, '.AMOrder::ID_OSEMPLOYEE.', '.$this->order_id.', ';
		$q .= ($this->za_pobraniem ? AMOrder::ID_OSDEFAULT_CASHONDELIVERY : AMOrder::ID_OSDEFAULT_BANKWIRE).', "'.$text_now.'")';
		if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inserting new order history');		

		// optional - message?
		if (!empty($tran['post-buy-form-msg-to-seller'])) {
			$q = 'INSERT INTO '._DB_PREFIX_.'message VALUES (NULL, '.$this->cart_id.', '.$this->customer_id.', 0, '.$this->order_id.', ';
			$q .= '"'.pSQL($tran['post-buy-form-msg-to-seller']).'", 0, "'.$text_now.'")';
			
			if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inserting new message');
		}		

		$z = 'Zakupiono: ';
		// Insert buying message
		foreach ($assoc_array as $elem) {
			list($name, $qty) = $elem;
			$z .= $name.' sztuk '.$qty.', ';
		}

		$q = 'INSERT INTO '._DB_PREFIX_.'message VALUES (NULL, '.$this->cart_id.', '.$this->customer_id.', 0, '.$this->order_id.', ';
		$q .= '"'.pSQL($z).'", 0, "'.$text_now.'")';

		if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inserting new automessage');

	}
	
	/**
	 * @param $vector array(opis, czy_jest_darmowa, czy_przy_odbiorze)
	 */
	public function createCarrier($vector) {
		list($name, $isfree, $przyodbiorze) = $vector;
		
		$isfree = $isfree ? 1 : 0;
		$przyodbiorze = $przyodbiorze ? 1 : 0;
		
		$this->za_pobraniem = $przyodbiorze;
		
		$is_module = 0;
		$extmodname = '';
		
		$q = 'INSERT INTO '._DB_PREFIX_.'carrier VALUES (NULL, 0, "'.pSQL($name).'", "", 1, 0, 1, 0, '.$is_module.', ';
		$q .= $isfree.', 0, 0, "'.pSQL($extmodname).'", 0)';
		
		if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inserting new carrier');
		$this->carrier_id = Db::getInstance()->Insert_ID();
	}
	
	/**
	 * Tworzy obiekt adresu
	 */
	public function createAddress($firstname, $lastname, $company, $address1, $address2, $postcode, $city, $other, $phone,
										 $nip) {
		$text_now = date('Y-m-d H:i:s', time());
		
		$q = 'INSERT INTO '._DB_PREFIX_.'address VALUES (NULL, '.AMOrder::ID_ACOUNTRY.', '.AMOrder::ID_ASTATE.', '.$this->customer_id.', 0, 0, ';
		$q .= '"adres", "'.pSQL($company).'", "'.pSQL($lastname).'", "'.pSQL($firstname).'", "'.pSQL($address1).'", "'.pSQL($address2).'", ';
		$q .= '"'.pSQL($postcode).'", "'.pSQL($city).'", NULL, "'.pSQL($phone).'", NULL, NULL, "'.pSQL($nip).'", ';
		$q .= '"'.$text_now.'", "'.$text_now.'", 1, 0)';
		
		if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inseritng new address');
		return Db::getInstance()->Insert_ID();
	}
	
	public function createAddressFromAllegroStruct($struct, $msg_to_seller) {
		$street = $struct['post-buy-form-adr-street'];
		$postcode = $struct['post-buy-form-adr-postcode'];
		$city = $struct['post-buy-form-adr-city'];
		$company = $struct['post-buy-form-adr-company'];
		
		// Attempt automatic detection of name
		$fullname = $struct['post-buy-form-adr-full-name'];
		if (!strpos($fullname, ' ')) {
			// Single part name
			$firstname = $fullname;
			$lastname = '';
		} else {
			list($firstname, $lastname) = explode(' ', $fullname, 2);
		}

		$nip = $struct['post-buy-form-adr-nip'];
		$phone = $struct['post-buy-form-adr-phone'];
		
		return $this->createAddress($firstname, $lastname, $company, $street, '', $postcode, $city, $msg_to_seller, $phone, $nip);		
	}
	
	/**
	 * Tworzy pusty cart z podanych danych
	 * @param $delivery_address Adres dostarczenia
	 * @param $invoice_address Adres faktury
	 */
	public function createCart($delivery_address, $invoice_address) {
		$securekey = md5(uniqid(rand(), true));
		$text_now = date('Y-m-d H:i:s', time());
		
		$q = 'INSERT INTO '._DB_PREFIX_.'cart VALUES (NULL, '.AMOrder::ID_CCARRIER.', '.AMOrder::ID_CLANG.', '.$delivery_address.', ';
		$q .= $invoice_address.', '.AMOrder::ID_CCURRENCY.', '.$this->customer_id.', '.AMOrder::ID_CGUEST.', ';
		// start from secure key
		$q .= '"'.$securekey.'", 1, 0, "", "'.$text_now.'", "'.$text_now.'")';

		if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inserting new cart');
		$this->cart_id = Db::getInstance()->Insert_ID();
	}

}

?>