<?php
	/* Main */

	/* Defaults parcel contents category */
	// TODO: make this user-specified?
	$contents = "Home & Garden";

	$inputFile = "ebay_sanitised.csv";
	$outputFile = "hermes_" . date("y_m_d") . ".csv";

	/* Initial ebay csv data */
	$ebayArray = array();

	/* Re-arranged myHermes csv data */
	$hermesArray = array();



	/** 'MAIN' **/
	$ebayArray = loadEbayCSV($inputFile);
	$ebayArray = normalizeEbayMultiOrders($ebayArray);
	$ebayArray = promptForDuplicates($ebayArray);
	$specifyWeight = getUserWeightPref();		// whether or not to manually specify weights for each order
	$hermesArray = convertEbayToHermes($ebayArray, $contents, $specifyWeight);
	outputHermes($outputFile, $hermesArray);



	/** Process functions **/

	/** Populate an array with Ebay CSV values and return it
	 *  @param string $filePath filesystem location of CSV file to load
	 *  @return array Multidimensional, incrementing array of orders
	 */
	function loadEbayCSV($filePath) {

		try {
			$file = new \SplFileObject($filePath, 'r');
		} catch(\RuntimeException $e) {
			echo 'CSV could not be opened - please check file path' . PHP_EOL;
			die();
		}
		$file->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);


		$CSVArray = Array();
		while (!$file->eof()) {
			$lineArray = $file->fgetcsv();

			$orderNo = $lineArray[0];

			// TODO: Correctly handle odd trailing line - look at flags :/
			if (!strlen($orderNo)) {
				continue;
			}

			if (!array_key_exists($orderNo, $CSVArray)) {
				$CSVArray[$orderNo] = array();
			}

			$name = explode(" ", $lineArray[2]);

			// multi-purchase order headers have no product ID associated with them
			$multiPurchase = false;
			if(empty($lineArray[11])) {
				$multiPurchase = true;
			}

			$CSVArray[$orderNo][] = array(
				'multiPurchaseHeader' => $multiPurchase,

				// customer name (split into [all first names] [last name])
				'lastname' => array_pop($name),
				'firstnames' => implode(" ", $name),

				// customer address
				'address1' => $lineArray[5],
				'address2' => $lineArray[6],
				'address3' => $lineArray[7],
				'address4' => $lineArray[8],
				'postcode' => $lineArray[9],

				// customer email
				'email' => $lineArray[4],

				// customer phone number
				'phone' => $lineArray[3],

				// reference - use order number (product name surprisingly useless with print character limit)
				'reference' => $lineArray[0],

				// product cost
				'value' => $lineArray[15]
			);
		}

		return $CSVArray;
	}

	/** Copies customer details from multi-order headers into the individual orders
	 *  and removes the header from the array. This is so multi-orders can be packaged
	 *  in separate parcels if required and will be treated like any other.
	 *  format:
	 *  (Header)	id		username	name	phone	email	addr1	addr2	addr3	addr4	postcode	country		empty		empty
	 *	(order)		id		username	empty	empty	empty	empty	empty	empty	empty	empty		empty		auctionId	product
	 *	(order)		id		username	empty	empty	empty	empty	empty	empty	empty	empty		empty		auctionId	product
	 *
	 *	@param array $CSVArray ebay formatted array
	 *  @return array ebay formatted array sans headers with customer details copied into individual orders
	 */
	function normalizeEbayMultiOrders($CSVArray) {

		// remove multi-purchase headers and copy name / address into individual orders
		$cleanCSVArray = array();
		foreach($CSVArray as $orderNo => $orderList) {

			if (count($orderList) > 1) {

				$headerRow = array();   // Stop IDE nag for uninitialized variable
				foreach ($orderList as $order) {

					// Header is always the first row
					if ($order['multiPurchaseHeader']) {
						$headerRow = $order;

					// Subsequent rows
					} else {
						// restore original references (different in CSV to sales page!)
						$orderNo--;

						$order['firstnames']	= $headerRow['firstnames'];
						$order['lastname']		= $headerRow['lastname'];
						$order['address1']		= $headerRow['address1'];
						$order['address2']		= $headerRow['address2'];
						$order['address3']		= $headerRow['address3'];
						$order['address4']		= $headerRow['address4'];
						$order['postcode']		= $headerRow['postcode'];
						$order['email']			= $headerRow['email'];
						$order['phone']			= $headerRow['phone'];
						$order['reference']		= $orderNo;

						$cleanCSVArray[]		= $order;
					}
				}

			} else {
				$cleanCSVArray[] = $orderList[0];
			}
		}

		return $cleanCSVArray;
	}

	/** Prompts the user as to whether or not to combine ALL (>1) orders with the same name.
	  * Also combines references so it's clear to the user which orders are to go in one parcel.
	  * @param array $ebayArray clear array before after multi-purchase headers have been removed
	  * @return array ebayArray with any duplicates removed
	  */
	function promptForDuplicates($ebayArray) {
		$ignoreList = array();

		foreach($ebayArray as $key => $order) {

			$duplicates = getDuplicates($order, $ebayArray, $ignoreList, $references);
			if (!empty($duplicates)) {

				echo("\n\n" . colorize($order['firstnames'] . " " . $order['lastname'] . " has placed multiple orders. (". $references . ")", "NOTE"));
				echo("\nDo you wish to combine these orders and send in one parcel? (y/n):  ");

				if(getUserYesNo()) {
					// if yes, trim others and replace reference of first order with combined
					$ebayArray[$key]['reference'] = $references;
					foreach($duplicates as $mergeKey => $merge) {
						if($key == $mergeKey) {
							continue;
						}
						unset($ebayArray[$mergeKey]);
					}

				}
				else {
					// if no, continue as normal but don't ask again.
					$ignoreList = array_merge($ignoreList, $duplicates);
				}
			}
		}
		return $ebayArray;
	}

	/** Requests user input and constructs a hermes array ready for outputting
	  *	@param $ebayArray a complete, de-duplicated clean ebay order array
	  * @param $contents the default contents string for myHermes
	  * @param $specifyWeight whether or not to request input on weights
	  * @return completed myHermes array ready for outputting to CSV.
	  */
	function convertEbayToHermes($ebayArray, $contents, $specifyWeight) {
		$ignoreList = array();

		foreach($ebayArray as $key => $order) {

			$hermesArray[$key] = $order;

			// contents (currently static)
			$hermesArray[$key]['contents'] = $contents;

			// weight (user-specified or default to 0.5)
			if($specifyWeight) {
				echo ("\nPlease enter weight (Kg) for eBay order " . $order['reference'] . " (" . $order['firstnames'] . " " . $order['lastname'] . "):  ");
				$hermesArray[$key]['weight'] = getUserWeight();
			}
			else {
				$hermesArray[$key]['weight'] = 0.5;
			}


			complete();
		}
		echo("\nImported " . count($hermesArray) . " orders successfully. \n\n");
		return $hermesArray;
	}

	/** Returns an array of all duplicate orders with the keys identical to the original array.
	  * @param $currentOrder the order array to look up
	  * @param $orders the full ebayArray with no multi-headers
	  * @param $ignoreList an array of orders to skip (if a user already said not to combine)
	  * @param &$refs fills a string by reference of all the combined order references
	  * @return array of duplicate orders
	  */
	function getDuplicates($currentOrder, $orders, $ignoreList, &$refs) {
		$duplicates = array();
		$references = array();
		foreach ($orders as $key => $order) {
			if (array_search($currentOrder, $ignoreList)) {
				continue;
			}
			if (array_search($currentOrder['firstnames'], $order) && array_search($currentOrder['lastname'], $order)) {
				// maintain consistent keys across arrays
				$duplicates[$key] = $order;
				array_push($references, $order['reference']);
			}
		}
		$refs = implode(', ', $references);

		// if only one match, clear array (but we want to include the original if there is!)
		if(count($duplicates) == 1) {
			$duplicates = array();
		}
		return $duplicates;
	}

	/** Exports a myHermes compatible CSV file.
	  * @param $outputFile the filesystem location to output to
	  * @param $hermesArray a converted array to output
	  */
	function outputHermes($outputFile, $hermesArray) {
		// hermes csv header

		echo("Exporting header to hermes_" . date("y_m_d") . ".csv ...\n");
		$header = "Address_line_1,Address_line_2,Address_line_3,Address_line_4,Postcode,First_name,Last_name,Email,Weight(Kg),Compensation(£),Signature(y/n),Reference,Contents,Parcel_value(£),Delivery_phone,Delivery_safe_place,Delivery_instructions\n";
		file_put_contents($outputFile, $header);

		// records
		$recordNum = 0;
		foreach ($hermesArray as $record) {
			$recordNum ++;
			echo("Exporting record " . $recordNum . ": " . $record['firstnames'] . " " . $record['lastname'] . "... ");
			$line  = "\"" . $record['address1'] . "\",";
			$line .= "\"" . $record['address2'] . "\",";
			$line .= "\"" . $record['address3'] . "\",";
			$line .= "\"" . $record['address4'] . "\",";
			$line .= "\"" . $record['postcode'] . "\",";
			$line .= "\"" . $record['firstnames'] . "\",";
			$line .= "\"" . $record['lastname'] . "\",";
			$line .= "\"" . $record['email'] . "\",";
			$line .= "\"" . $record['weight'] . "\",";
			$line .= "\"\",";		// compensation
			$line .= "\"\",";		// signature
			$line .= "\"" . $record['reference'] . "\",";
			$line .= "\"" . $record['contents'] . "\",";
			$line .= "\"" . $record['value'] . "\",";
			$line .= "\"" . $record['phone'] . "\",";
			$line .= "\"\",";		// safe place
			$line .= "\"\"\n";		// delivery instructions

			file_put_contents($outputFile, $line, FILE_APPEND);
			complete();
		}

		usleep(500000);
		echo("\nExported " . $recordNum . " records to hermes_" . date("y_m_d") . ".csv successfully!\n\n");
		usleep(250000);
	}



	/** HELPER FUNCTIONS **/

	/** Asks the user whether or not they wish to manually specify weights with parcels
	 *  @return boolean yes/no response
	 */
	function getUserWeightPref() {
		echo ("\nWeights of each order default to <1Kg.\nWould you prefer to specify weights for each order? (y/n)\n");
		if(getUserYesNo()) {
			echo ("\n" . colorize("Please keep in mind that weights will be reduced slightly to drop below cost threshold.", "NOTE") . "\n\n");
			return true;
		}
		return false;
	}


	/** Get user input for weight of an order
	 *  Reduces the weight by 0.01% in order to drop below threshold for parcel weights
	 *  Maximum weight input of 15Kg
	 *  @return float weight value
	 */
	function getUserWeight() {
		$handle = fopen ("php://stdin","r");
		$line = fgets($handle);
		$line = trim($line, "\r\n");
		if(is_numeric($line)){
			if($line > 15) {
				echo(colorize("This is more than myHermes allows! Please try again:", "FAILURE") . "  ");
				return getUserWeight();
			}
			if($line == 0) {
				echo(colorize("There is no such thing is weightless. Sorry. Try again:", "FAILURE") . "  ");
				return getUserWeight();
			}
			if($line < 0) {
				echo(colorize("Inverse weight is a fantasy. Stop it. Try again:", "FAILURE") . "  ");
				return getUserWeight();
			}
			return $line * 0.99;	// reduce slightly to drop below parcel cost threshold (10Kg = 9.9 Kg = 5-10Kg parcel)
		}
		else {
			// if the user inputs an invalid weight, default and notify again at the end!
			echo(colorize("Invalid weight, please try again:", "FAILURE") . "  ");
			return getUserWeight();
		}
	}


	/** Simple get true or false based on user input of "y/yes" or "n/no"
	 *  Basic error handling included
	 *  @return boolean yes/no answer
	 */
	function getUserYesNo() {
		$handle = fopen ("php://stdin","r");
		$line = fgets($handle);
		$line = trim($line, "\r\n");
		if($line == "y" || $line == "yes") {
			return true;
		}
		else if ($line != "n" && $line != "no") {
			echo(colorize("Invalid input, please try again (y/n):", "FAILURE") . "  ");
			return getUserYesNo();
		}
		return false;
	}


	/** Completion of a task with small usability pause
	  * Simply outputs "Done" in green text and waits 125 mSec
	  * (Prevents user from seeing an instant giant wall of text!)
	  */
	function complete() {
		usleep(125000);
		echo(colorize("Done!", "SUCCESS") . "\n");
	}


	/** Handy coloring helper function
	 *  @param $text input text to be colored
	 *  @param $status determines color:
	 *		'SUCCESS' 	= green
	 *		'FAILURE' 	= red
	 *		'WARNING' 	= yellow
	 *		'NOTE'		= blue
	 *  @return colored text string
	 */
	function colorize($text, $status) {
	$out = "";
	switch($status) {
		case "SUCCESS":
			$out = "[42m"; //Green background
			break;
		case "FAILURE":
			$out = "[41m"; //Red background
			break;
		case "WARNING":
			$out = "[43m"; //Yellow background
			break;
		case "NOTE":
			$out = "[44m"; //Blue background
			break;
		default:
			throw new Exception("Invalid status: " . $status);
	}
	return chr(27) . "$out" . "$text" . chr(27) . "[0m";
}

?>
