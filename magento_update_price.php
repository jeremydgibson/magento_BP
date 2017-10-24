<?php

//TODO: put this in a utilities file
//http://us2.php.net/manual/en/function.str-getcsv.php author: Jay Williams
function csv_to_array($filename='', $delimiter=',')
{
    if(!file_exists($filename) || !is_readable($filename))
        return FALSE;
    $header = NULL;
    $data = array();
    if (($handle = fopen($filename, 'r')) !== FALSE)
    {

        while (($row = fgetcsv($handle, 1000, $delimiter)) !== FALSE)
        {

            if(!$header)
                $header = $row;
            else
                $data[] = array_combine($header, $row);
        }

        fclose($handle);
    }

    return $data;
}

if (count($argv)>=2){

	$csv_file_name = $argv[1];

}else{

	echo "\nYou didn't include a file argument.\nThe correct format for calling this program is:\nphp name_of_program name_of_valid_file.csv";

	exit;
}

try {

	//turn this into an associative array:
	$product_info = csv_to_array($csv_file_name, ',');

} catch (Exception $e) {

	echo "There was a problem with the file argument supplied.\nThe correct format for calling this program is:\nphp name_of_program name_of_valid_file.csv";
	fclose($handle);
	exit;

}

//authentication, probably hide this in a separate file
$client = new SoapClient('http://growershouse.com/api/soap/?wsdl');//MAGENTOHOST==yoursiteurl
$fp = fopen("php://stdin", "r");
$apiUser = '';
$apiKey = '';
$count = 0;

while(true) {

	$count++;

    echo "\napiUser:";
    $apiUser=trim(fgets($fp));

    echo "\napiKey:";
    $apiKey=trim(fgets($fp));//TODO: there must be a way to enter password without showing it.
    try{

        $session = $client->login($apiUser, $apiKey);
        fclose($fp);
   		break;

	} catch (Exception $e) {

		//art by cfbd@southern.co.nz (Colin Douthwaite), http://www.ascii-art.de/ascii/jkl/kilroy.txt
		echo "\n\n..................................................\n";
		echo ":                                                :\n";
		echo ":                    ......                      :\n";
		echo ":                 .:||||||||:.                   :\n";
		echo ":                /            \                  :\n";
		echo ":               (   o      o   )                 :\n";
		echo ":-------@@@@----------:  :----------@@@@---------:\n";
		echo ":                     `--'                       :\n";
		echo ":                                                :\n";
		echo ":         F O O   W A S   H E R E   (  and  )    :\n";
		echo ":     THE CREDENTIALS YOU GAVE DID NOT WORK      :\n";
		echo ":                                                :\n";
		echo ":................................................:\n\n";

    	if ($count>=3){

			fclose($fp);
    		exit;
    	}
	}
}

$SKUs_not_added = Array();

//TODO: test and try/catch for SKU, price, and any other keys in the CSV.
foreach ($product_info as $indiv_product) {
  try{
	$result = $client->call($session, 'catalog_product.update', array($indiv_product['SKU'], array(
    	'price' => $indiv_product['price']
	)));

} catch (Exception $e) {
  echo "\n".$result."\n";
echo "begin error dump:\n";
var_dump($e);

  if ($result==101){

    $SKUs_not_added[$indiv_product['SKU']]="101";

  } else if ($result==102){

    $SKUs_not_added[$indiv_product['SKU']]="102";
  } else {

    $SKUs_not_added[$indiv_product['SKU']]="some other problem";
  }

  continue;
}


	var_dump ($result);

	$tier_quantities = explode(",", $indiv_product['tierQuantities']);
	$tier_prices = explode(",", $indiv_product['tierPrices']);

	//$tier_quantities and $tier_prices should be the same length, but incase they aren't:
	$count = min(count($tier_quantities), count($tier_prices));

	if ($count>0) {

		$tierPrices = array();

		//build up the $tierPrices
		for($i = 0; $i < $count; $i++) {

			$tierPrices[] = array('customer_group_id' => 'all', 'website' => 'all', 'qty' => trim($tier_quantities[$i]), 'price' => trim($tier_prices[$i]));
		}


//get the original tiers on the product.
    $originalTierPrices = $client->call($session, 'product_tier_price.info', $indiv_product['SKU']);



      foreach ($originalTierPrices as $originalTierPrice) {

        if (array_key_exists ('qty' , $originalTierPrice)){

          $originalTierQuantity = $originalTierPrice['qty']; //what is the right way to do this?

          //$tier_quantities is an array or arrays, we need to know if $originalTierQuantity is in any of those arrays.
          $tierPresent = FALSE;
          foreach ($tierPrices as $tier) {

            //using $tier_quantities would be better (could avoid this loop, TODO: look into map in php), but it has strings that might need to be stripped to create a match.
            if (array_key_exists('qty',$tier) and $tier['qty']==$originalTierQuantity){

                $tierPresent = TRUE;
                break;
            }
          }

          //if the original tier wasn't accounted for in the new data, add the original info back in.
          if (!$tierPresent){
            $tierPrices[] = $originalTierPrice;
          }

        }
      }



//see what this is:
//var_dump($originalTierPrices);

		//update the tier prices for $indiv_product:
		$client->call(
			$session,
			'product_attribute_tier_price.update',
			array(
				$indiv_product['SKU'],
				$tierPrices
			)
		);

		var_dump($result); //TODO: log if there was a problem with tier pricing

	}



	//TODO: log the success SKUs and especially the failure SKUs
}

if (count($SKUs_not_added)>0){

  echo "SKUs not added(101==badSKU, 102==syntaxError):\n";

  foreach($SKUs_not_added as $sku=>$errorType){

    echo $sku.' '.$errorType."\n";
  }

}else{

  echo "all SKUs updated";
}

$client->endSession($session);

?>
