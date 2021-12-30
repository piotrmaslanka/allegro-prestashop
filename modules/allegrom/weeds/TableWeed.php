<?php
/**
 * Moduł wspierający odczyt z bazy danych
 * @author Piotr Maślanka <piotr.maslanka@henrietta.com.pl>
 * @copyright Copyright (c) by Piotr Maślanka 2013
 *            All rights reserved
 */

include_once('../../classes/TaxRulesGroup.php');

class TableWeed {
	
	/** Czy importować mimo tego że aukcji nie ma w systemie migracji Allegro */
	const DO_IMPORT_IF_NOT_FOUND_IN_TABLE = true;
	
	// Domyslne wartosci dla pobierania podatkow
	const ID_COUNTY = 0;
	const ID_STATE = 0;
	const ID_COUNTRY = 14;
	
	// Domyslne wartosci dla jezyka
	const ID_LANG = 6;
	
	/**
	 * Sprawdza czy dana transakcja została już wprowadzona do Presty
	 * @param $transaction_id ID transakcji Allegro
	 * @return bool Czy dana transakcja została już przetworzona
	 */
	public function is_transacted($transaction_id) {
		$q = Db::getInstance()->ExecuteS('SELECT * FROM '._DB_PREFIX_.'allegrom_trans WHERE id_allegrom_trans='.$transaction_id);
		return (!empty($q));
	}
	
	/**
	 * Wiąże dany ID ordera z ID transakcji Allegro
	 */
	public function corellate_order_id_with_allegro($order_id, $transaction_id, $debuginfo) {
		$q = Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'allegrom_trans VALUES ('.$transaction_id.', '.$order_id.', NOW(), "'.pSQL(json_encode($debuginfo)).'")');
		if (!$q) throw new Exception('SQL failure: inserting corellation failed');
	}	
	
	/**
	 * Zwraca ID produktu Presty po podanej ofercie Allegro
	 * Zwraca ID produktu 'zamówienie Allegro' jeśli nie wypełniono ID produktu
	 * Zwraca ID produktu 'zamówienie Allegro' jeśli brak takiej oferty i DO_IMPORT_IF_NOT_FOUND_IN_TABLE
	 * @throws Exception jeśli brak takiej oferty Allegro i nie DO_IMPORT_IF_NOT_FOUND_IN_TABLE
	 */
	public function product_id_by_offer($offer) {
		$q = Db::getInstance()->ExecuteS('SELECT id_product FROM '._DB_PREFIX_.'allegrom_auction WHERE id_allegrom_auction="'.$offer.'"');
		if (empty($q))
			if (TableWeed::DO_IMPORT_IF_NOT_FOUND_IN_TABLE)
				return (int)Configuration::get('allegrom_product_id');
			else
				throw new Exception('product_id_by_offer(): not found');
		$product_id = $q[0]['id_product'];
		if ($product_id == null) return (int)Configuration::get('allegrom_product_id');
		else return $product_id;
	}

	/**
	 * Zwraca nazwę produktu po jego ID
	 * @param unknown $product_id
	 * @throw Exception jeśli nie znaleziono
	 */
	public function product_name_by_id($product_id) {
		$q = Db::getInstance()->ExecuteS('SELECT name FROM '._DB_PREFIX_.'product_lang WHERE (id_product='.$product_id.') AND (id_lang='.TableWeed::ID_LANG.')');
		if (empty($q)) throw new Exception("product_name_by_id(): not found");
		return $q[0]['name'];
	}

	/**
	 * Zwraca tax rate po ID produktu
	 * @throw Exception jeśli nie znaleziono
	 */
	public function get_tax_rate_by_product_id($product_id) {
		
		if ($product_id == (int)Configuration::get('allegrom_product_id'))
			return '0.23'; 
		
		$q = 'SELECT id_tax_rules_group FROM '._DB_PREFIX_.'product WHERE id_product='.pSQL($product_id);
		$r = Db::getInstance()->ExecuteS($q);
		if (empty($r)) throw new Exception('get_tax_rate_by_product_id(): not found');
		$trg = $r[0]['id_tax_rules_group'];
			
		$tpr = TaxRulesGroup::getTaxesRate($trg, TableWeed::ID_COUNTRY, TableWeed::ID_STATE, TableWeed::ID_COUNTY);
		return $tpr;
	}
	
	/**
	 * Sprawdza czy dana oferta Allegro istnieje w systemie
	 * @return bool czy istnieje
	 */
	public function offer_exists($offer) {
		try {
			$pid = $this->product_id_by_offer($offer);
			if ($pid == (int)Configuration::get('allegrom_product_id'))
				return false;
		} catch (Exception $e) {
			return false;
		}
		return true;
	}
	
	/**
	 * Pobiera ID usera z naszej bazy po jego ID z allegro
	 * @param $allegro_user_id ID allegro
	 * @return id w naszej bazie
	 */
	public function get_customer($allegro_user_id) {
		$q = Db::getInstance()->ExecuteS('SELECT id_customer FROM '._DB_PREFIX_.'allegrom_client WHERE id_f_allegro_id='.$allegro_user_id);
		return $q[0]['id_customer'];
	}
	
	/**
	 * Sprawdza czy allegrowicz o zadanym ID jest juz w naszej bazie
	 * @return bool
	 */
	public function is_our_client($allegro_user_id) {
		$q = Db::getInstance()->ExecuteS('SELECT id_customer FROM '._DB_PREFIX_.'allegrom_client WHERE id_f_allegro_id='.$allegro_user_id);
		return (!empty($q));
	}
}

?>