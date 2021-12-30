<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$curtime = time(); 
echo "Starting\n";
// Prep-up
require_once('../../config/config.inc.php');
require_once('../../init.php');
require_once('weeds/AllegroAPI.php');
require_once('weeds/OrderCraft.php');
require_once('weeds/TableWeed.php');
require_once('../../classes/Order.php');
require_once('weeds/CustomerCraft.php');

// Check sanity
if (!is_numeric(Configuration::get('allegrom_carrier_tax_rate'))) {
	echo "Fatal error: Default tax rate is not set. SET IT IN MODULE PANEL!\n";
	echo "Finishing. Took ".(time()-$curtime)." seconds\n";
	die();
}
if (!is_numeric(Configuration::get('allegrom_product_id'))) {
	echo "Fatal error: Default product ID is not set. SET IT IN MODULE PANEL!\n";
	echo "Finishing. Took ".(time()-$curtime)." seconds\n";
	die();
}


$tw = new TableWeed();
$aa = new AllegroAPI();
try {
	$aa->login(Configuration::get('allegrom_user'), Configuration::get('allegrom_password'));
} catch (SoapFault $f) {
	echo "SOAP login error: ".$f->getMessage()."\n";
	echo "Finishing. Took ".(time()-$curtime)." seconds\n";
	die();
}

// Get all 'sold' offers

$offers = $aa->getSold();

// Get shipment methods
$shipment_methods = $aa->getShipmentMethods();

// Filter out those that exist in our system

$new_offers = array();
foreach ($offers as $offer => $vector) {
	list($was_closed, $start_timestamp) = $vector;
	
	if ($tw->offer_exists($offer))
		$new_offers[$offer] = $was_closed;
	else
		// Offer does not exist in our table. Perform manual timestamp check.
		if ($start_timestamp >= (int)Configuration::get('allegrom_autoload_since'))
			$new_offers[$offer] = $was_closed;
}

$offers = $new_offers;

// Get transaction and contact data


$transactions = array();
$contact_data = array();

foreach ($offers as $offer => $was_closed) {
    $tran_nfo = $aa->getTransactionsInfo($offer);

    $found_something = false;
    foreach ($tran_nfo as $tran) {
        if (!$tw->is_transacted($tran['post-buy-form-id'])) {
            $found_something = true;
            $transactions[] = $tran;
        }
    }
    
    if ($found_something)
        $contact_data = array_merge($contact_data, $aa->getContactData($offer));
}

function analyze_transaction($tran, $contact_data, $shipment_methods, $aa) {
	$tw = new TableWeed();

	// Is the guy our client? If not, transport him to this database
	if ($tw->is_our_client($tran['post-buy-form-buyer-id'])) {
		$customer_id = $tw->get_customer($tran['post-buy-form-buyer-id']);
	} else {
		$customer_id = allegrom_craft_customer($tran['post-buy-form-buyer-id'], $tran, $contact_data, $aa);
	} 		
	
	// What did the guy actually buy? Construct a array($product_id => $quantity)
	$what_he_bought = array();
	foreach ($tran['post-buy-form-items'] as $item) {
		$product_id = $tw->product_id_by_offer($item['post-buy-form-it-id']);
		$what_he_bought[$product_id] = $item['post-buy-form-it-quantity'];
	}	

	$msg_to_seller = $tran['post-buy-form-msg-to-seller'];
	
	$order = new AMOrder($customer_id, $shipment_methods);	
	// Ok, addresses may need to be constructed.
	// Shipment address is OK for sure
	$shipping_address = $order->createAddressFromAllegroStruct($tran['post-buy-form-shipment-address'], $msg_to_seller);
	// Is creating new invoice necessary?
	if (empty($tran['post-buy-form-invoice-data']['street']))
		$invoice_address = $shipping_address;		// No
	else
		$invoice_address = $order->createAddressFromAllegroStruct($tran['post-buy-form-invoice-data'], $msg_to_seller);	// Yes
	
	// Create a cart and fill it in
	
	$order->createCart($shipping_address, $invoice_address);	
	$order->generateCartProducts($what_he_bought);

	// Allocate a carrier
	$order->createCarrier($shipment_methods[$tran['post-buy-form-shipment-id']]);

	// Cart filled, transform it into an order
	$order->createOrder($tran['post-buy-form-postage-amount'], $shipping_address, $invoice_address, $tran);
	$tw->corellate_order_id_with_allegro($order->order_id, $tran['post-buy-form-id'], $tran);
	
	return $order->order_id;
}

$tran_comitted = 0;
$tran_rollbacked = 0;


foreach ($transactions as $tran) {
	Db::getInstance()->execute('BEGIN');
	
	try {
		if (!$tw->is_transacted($tran['post-buy-form-id'])) {
			echo 'Analyzing transaction '.$tran['post-buy-form-id']."\n";
			$order_id = analyze_transaction($tran, $contact_data, $shipment_methods, $aa);
			echo 'Loaded '.$tran['post-buy-form-id'].' as order no '.$order_id."\n";			
		} else { 
			echo 'Transaction '.$tran['post-buy-form-id']." already processed\n";
		}		
	} catch (Exception $e) {
		echo("Exception: ".$e->getMessage()."\n");
		Db::getInstance()->Execute('ROLLBACK');
		$tran_rollbacked += 1;
		echo("Rolled back\n");
		continue;
	}
	
	Db::getInstance()->Execute('COMMIT');
	$tran_comitted += 1;
}

echo "Import finished. ".$tran_comitted." transactions comitted, ".$tran_rollbacked." rolled back. Took ".(time()-$curtime)." seconds\n";
?>