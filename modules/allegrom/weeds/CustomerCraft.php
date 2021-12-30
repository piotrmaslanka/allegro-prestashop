<?php
/**
 * Moduł tworzenia klienta w bazie Presty
 * @author Piotr Maślanka <piotr.maslanka@henrietta.com.pl>
 * @copyright Copyright (c) by Piotr Maślanka 2013
 *            All rights reserved
 *
 */
/**
 * Importuje użytkownika z Allegro do naszej bazy. Zwraca jego ID.
 */
function allegrom_craft_customer($allegro_user_id, $transaction, $contactarray, $allegroapi) {
	
	// fetch users contact data. They are bound to be in contactarray
	$contactarray_entity = null;
	foreach ($contactarray as $ent) {
		if ($ent['user-data']['user-id'] == $allegro_user_id) {
			$contactarray_entity = $ent;
			break;
		}
	}

	if ($contactarray_entity == null) throw new Exception('Contact data for Allegro user '.$allegro_user_id.' not found');
	
	// It would be good if there was a matching user in our DB. We wouldn't have to create another one.
	// try to locate it
	$q = Db::getInstance()->ExecuteS('SELECT id_customer FROM '._DB_PREFIX_.'customer WHERE email="'.pSQL($contactarray_entity['user-data']['user-email']).'")');
	if (!empty($q)) {
		// Well good! User located! Associate it in the database.
		$customer_id = $q[0][0];
		Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'allegrom_client VALUES ('.pSQL($allegro_user_id).', '.$customer_id.');');
		return $customer_id;
	}
	
	// No ball. Craft a new user...
	$firstname = $contactarray_entity['user-data']['user-first-name'];
	$lastname = $contactarray_entity['user-data']['user-last-name'];
	$email = $contactarray_entity['user-data']['user-email'];
	
	$plain_pwd = Tools::passwdGen(6);
	
	$password = Tools::encrypt($plain_pwd);
	$gender = (strtolower(substr($firstname, -1)) == 'a') ? 2 : 1;
	
	$text_now = date('Y-m-d H:i:s', time());
	$securekey = md5(uniqid(rand(), true));
	
	$q = 'INSERT INTO '._DB_PREFIX_.'customer VALUES (NULL, '.$gender.', 1, "'.pSQL($firstname).'", "'.pSQL($lastname).'", "'.pSQL($email).'", ';
	$q .= '"'.$password.'", "'.$text_now.'", "'.$text_now.'", 1, NULL, NULL, 1, "'.$securekey.'", NULL, 1, 0, 0, "'.$text_now.'", "'.$text_now.'")';

	if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: inserting new customer');
	$q = Db::getInstance()->ExecuteS('SELECT id_customer FROM '._DB_PREFIX_.'customer WHERE email="'.pSQL($email).'"');

	Mail::Send(Language::getIdByIso('pl'), 
			   'allegrom_newuser', 'Potwierdzenie założenia konta',
			array('{email}' => $email,
    			  '{lastname}' => $lastname,
				  '{firstname}' => $firstname,
				  '{passwd}' => $plain_pwd
			),
			$email, $firstname.' '.$lastname);
	
	$customer_id = $q[0]['id_customer'];
	
	
	// Add to group
	$q = 'INSERT INTO '._DB_PREFIX_.'customer_group VALUES ('.$customer_id.', 1)';
	if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: registering customer in group');
	
	// Associate it with Allegro account
	
	$q = 'INSERT INTO '._DB_PREFIX_.'allegrom_client VALUES ("'.pSQL($allegro_user_id).'", '.$customer_id.')';
	
	if (!Db::getInstance()->Execute($q)) throw new Exception('SQL failure: registering new customer in allegrom');
	
	return $customer_id;
}

?>