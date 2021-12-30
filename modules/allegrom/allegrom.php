<?php
require_once('weeds/AllegroAPI.php');
/**
 * Moduł do migracji zamówień z Allegro do PrestaShop
 * @author Piotr Maślanka <piotr.maslanka@henrietta.com.pl>
 * @copyright Copyright (c) by Piotr Maślanka 2013
 *            All rights reserved
 */

class Allegrom extends Module {
	function __construct() {
		$this->name = 'allegrom';
		$this->tab = 'other';
		$this->version = '1.0';
		$this->author = 'Piotr Maślanka';
		parent::__construct();
		$this->displayName = 'AllegroMigrate';
		$this->description = $this->l('Migracja zamówień z Allegro');		
		
		$this->ALLEGRO_USER = Configuration::get('allegrom_user');
		$this->ALLEGRO_PASSWORD = Configuration::get('allegrom_password');
	}
	
	public function install() {
		parent::install();
		
		Configuration::updateValue('allegrom_user', 'UNDEFINED');
		Configuration::updateValue('allegrom_password', 'UNDEFINED');
		Configuration::updateValue('allegrom_carrier_tax_rate', '23');
		Configuration::updateValue('allegrom_product_id', 'UNDEFINED');
		Configuration::updateValue('allegrom_autoload_since', (string)time());
		
		// tablica z aukcjami sledzonymi przez system
		if(!Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.'allegrom_auction
		(`id_allegrom_auction` VARCHAR(30) NOT NULL,
		 `id_product` INT(10),
		PRIMARY KEY(id_allegrom_auction))
		ENGINE=InnoDB DEFAULT CHARSET=utf8;'))
			return false;

		// tablica z klientami wessanymi z Allegro
		if(!Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.'allegrom_client
		(`id_f_allegro_id` VARCHAR(30) NOT NULL,
		 `id_customer` INT(10) UNSIGNED NOT NULL,
		PRIMARY KEY(id_f_allegro_id),
		CONSTRAINT FOREIGN KEY (`id_customer`) REFERENCES `'._DB_PREFIX_.'customer` (`id_customer`))
		ENGINE=InnoDB DEFAULT CHARSET=utf8;'))
			return false;
				
		// tablica z transakcjami rozegranymi w ramach systemu
		if(!Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.'allegrom_trans
		(`id_allegrom_trans` VARCHAR(30) NOT NULL,
		 `id_order` INT(10) UNSIGNED NOT NULL,
		 `when` DATETIME NOT NULL,	
	     `debuginfo` TEXT,
		PRIMARY KEY(id_allegrom_trans))
		ENGINE=InnoDB DEFAULT CHARSET=utf8;'))
			return false;		
		
		return true;
	}
	
	public function uninstall()
	{
		Configuration::deleteByName('allegrom_user');
		Configuration::deleteByName('allegrom_password');
		Configuration::deleteByName('allegrom_carrier_tax_rate');
		Configuration::deleteByName('allegrom_product_id');
		Configuration::deleteByName('allegrom_autoload_since');
		
		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'allegrom_auction`');
		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'allegrom_client`');
		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'allegrom_trans`');
		
		parent::uninstall();
		return true;
	}	
	
	public function getContent() {
		$this->errcode = '';
		
		if (Tools::isSubmit('store_pwd')) {
			$user = Tools::getValue('user');
			$pass = Tools::getValue('password');
			$ctr = Tools::getValue('carrier_tax_rate');
			$pid = Tools::getValue('allegrom_product_id');
			$autoload = Tools::getValue('autoload');
			Configuration::updateValue('allegrom_user', $user);
			Configuration::updateValue('allegrom_password', $pass);
			Configuration::updateValue('allegrom_carrier_tax_rate', $ctr);
			Configuration::updateValue('allegrom_product_id', $pid);
			Configuration::updateValue('allegrom_autoload_since', (string)strtotime($autoload));
			$this->ALLEGRO_USER = Configuration::get('allegrom_user');
			$this->ALLEGRO_PASSWORD = Configuration::get('allegrom_password');
		}

		if (Tools::isSubmit('submitted')) {
			// Russian Counter-SQLInjection
			$auction_id = Tools::getValue('auction_id') + 0;
			$product_id = Tools::getValue('product_id') + 0;
			
			// No need to verify product ID
			if ($product_id == 0) $product_id = 'NULL'; // none, will use default			
			// Attempt to verify auction ID
			try {
				$aa = new AllegroAPI();
				$aa->login($this->ALLEGRO_USER, $this->ALLEGRO_PASSWORD, AllegroAPI::ALLEGRO_APIKEY);
				if ($aa->verifyAuction($auction_id)) {
					// Verified. Append to DB
					$q = 'INSERT INTO '._DB_PREFIX_.'allegrom_auction VALUES ("'.$auction_id.'", '.$product_id.')';
					if (!Db::getInstance()->Execute($q))
						throw new Exception('Failed to insert: allegrom_auction');						
				} else {
					$this->errcode = 'Nie ma takiej aukcji';
				}
			} catch (SoapFault $e) {
				$this->errcode = 'Błąd interfejsu SOAP Allegro';
			}				
		}
		$this->_generateForm();
		return $this->_html;
		
	}
	
	private function _generateForm() {
		// Errcode display part
		if (!empty($this->errcode))	$this->html .= $this->errcode.'<br><br>';
		// Login/pass part
		$this->_html .= '<form action="'.$_SERVER['REQUEST_URI'].'" method="POST">';
		$this->_html .= '<div class="margin-form">';
		$this->_html .= 'Login Allegro: <input type="text" name="user" value="'.$this->ALLEGRO_USER.'"><br>';
		$this->_html .= 'Hasło Allegro: <input type="text" name="password" value="'.$this->ALLEGRO_PASSWORD.'"><br>';
		$this->_html .= 'Stawka podatkowa dostawy [%]: <input type="text" name="carrier_tax_rate" value="'.Configuration::get('allegrom_carrier_tax_rate').'"><br>';
		$this->_html .= 'ID Presta "zamówienia Allegro": <input type="text" name="allegrom_product_id" value="'.Configuration::get('allegrom_product_id').'"><br>';
		$this->_html .= 'Migruj aukcje od: <input type="text" name="autoload" value="'.date('Y-m-d H:i', (int)Configuration::get('allegrom_autoload_since')).'"><br>';
		$this->_html .= '<input type="submit" name="store_pwd" value="Zapisz"></div></form>';
		$this->_html .= '<br>';
		
		// Auction listing and registering part
		$table = '<div class="margin-form" style="font-size: 1.4em;"><table><tr><th>ID allegro</th><th>ID produktu</th></tr>';
		$q = Db::getInstance()->ExecuteS('SELECT id_allegrom_auction, id_product FROM '._DB_PREFIX_.'allegrom_auction');
		foreach ($q as $r)
			$table .= '<tr><td><a target="_blank" href="http://allegro.pl/show_item.php?item='.$r['id_allegrom_auction'].'">'.$r['id_allegrom_auction'].'</a></td><td>'.$r['id_product'].'</td></tr>';
		$table .= '</table></div>';
		
		$this->_html .= $table;
		
		$this->_html .= '<form action="'.$_SERVER['REQUEST_URI'].'" method="post">';
		$this->_html .= '<div class="margin-form">';
		$this->_html .= 'Nr aukcji Allegro: <input type="text" name="auction_id"><br>';
		$this->_html .= 'Nr produktu Presta: <input type="text" name="product_id"><br>';
		$this->_html .= '(zostaw puste jeśli produktu nie ma w bazie)<br>';
		$this->_html .= '<input type="submit" name="submitted" value="Dodaj"></div></form>';
	}
}
?>