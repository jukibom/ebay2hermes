<?php

	// Get parameter list
	$params = getopt('f:');

	if (!count($params)) {
		$pathInfo = pathinfo(__FILE__);
		echo 'Please provide a file by calling this script like "php ' . $pathInfo['basename'] . ' -f yourfile.csv"' . PHP_EOL;
		die();
	}


	// Configuration options
	$defaultCategory = 'Home & Garden';		// Seems to cover 90% of items for now
	$defaultWeight	= 0.5;					// User may override this
	$uxWait	= 125000;						// Number of microsecond to delay processing by (oddly more user-friendly)

	$inputFile = $params['f'];
	$outputFile = 'hermes_' . date('y_m_d') . '.csv';


	/** 'MAIN' **/
	$ebayArray = loadEbayCSV($inputFile);
	$ebayArray = normalizeEbayMultiOrders($ebayArray);
	$ebayArray = promptForDuplicates($ebayArray);
	$specifyWeight = getUserWeightPref();		// whether or not to manually specify weights for each order
	$hermesArray = convertEbayToHermes($ebayArray, $defaultCategory, $specifyWeight, $defaultWeight);
	outputHermes($outputFile, $hermesArray, $uxWait);



	/** Process functions **/

	/**
	 * Populate an array with Ebay CSV values and return it
	 *
	 * @param string $filePath filesystem location of CSV file to load
	 *
	 * @return array Multidimensional array of orders with ebay sales ID as the key.
	 */
	function loadEbayCSV($filePath) {

		// open file for reading without empty lines
		try {
			$file = new \SplFileObject($filePath, 'r');
		} catch(\RuntimeException $e) {
			echo 'CSV could not be opened - please check file path' . PHP_EOL;
			die();
		}
		$file->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);


		$CSVArray = Array();
		$lineNo = 0;
		$skippedCount = 0;
		$skippedList = array();
		while (!$file->eof()) {
			$lineNo++;
			$lineArray = $file->fgetcsv();

			// Silently skip the header line
			if (1 == $lineNo) {
				continue;
			}

			// Silently skip entirely empty lines
			if (0 == count($lineArray)) {
				continue;
			}

			// Skip invalid lines (trailing poop at end of csv)
			// Note: this will break if ebay begins emitting a different csv with fewer than 37 rows
			// But at least it will warn us this way!
			if (count($lineArray) < 37) {
				$skippedCount++;
				$skippedList[] = $lineArray;
				continue;
			}

			// use the ebay Order number as our key
			// multi-orders will have the same key and contain nSales + Header
			$orderNo = $lineArray[0];
			if (!array_key_exists($orderNo, $CSVArray)) {
				$CSVArray[$orderNo] = array();
			}

			// multi-purchase order headers have no product ID associated with them
			$multiPurchase = false;
			if (empty($lineArray[11])) {
				$multiPurchase = true;
			}

			// cut out any extra white space
			$lineArray = array_map('trim', $lineArray);

			// remove £ symbols
			try {
				$value = normalizeValue($lineArray[15]);
			} catch (Exception $e) {
				echo 'Unable to parse price for order #' . $lineArray[0] . PHP_EOL;
			}
			
			// name split into [all first names] [last name]
			$name = explode(' ', $lineArray[2]);
			$lastname 	= array_pop($name);
			$firstnames = implode(' ', $name);

			// store line details in new array
			$CSVArray[$orderNo][] = array(
				'multiPurchaseHeader' => $multiPurchase,

				// customer name 
				'lastname' => normalizeCSVString($lastname),
				'firstnames' => normalizeCSVString($firstnames),

				// customer address
				'address1' => normalizeCSVString($lineArray[5]),
				'address2' => normalizeCSVString($lineArray[6]),
				'address3' => normalizeCSVString($lineArray[7]),
				'address4' => normalizeCSVString($lineArray[8]),
				'postcode' => strtoupper(str_replace('-', ' ', $lineArray[9])),	// Remove any hypens in postcodes (silly customers!)

				// customer email
				'email' => strtolower($lineArray[4]),

				// customer phone number
				'phone' => $lineArray[3],

				// printed reference - use order number (product name surprisingly useless with print character limit)
				'reference' => $orderNo,

				// product cost
				'value' => $value
			);
		}

		if ($skippedCount == $lineNo) {
			echo colorize('0 lines could be processed, perhaps the CSV format has changed?' . PHP_EOL, 'FAILURE');
			die();
		}

		if ($skippedCount > 2) {
			echo colorize('More than two lines have been skipped, this should never happen. Perhaps the CSV format has changed' . PHP_EOL, 'FAILURE');
			print_r($skippedList);
			die();
		}

		return $CSVArray;
	}


	/**
	 * Copies customer details from multi-order headers into the individual orders
	 * and removes the header from the array. This is so multi-orders can be packaged
	 * in separate parcels if required and will be treated like any other.
	 * format:
	 * (Header)		id		username	name	phone	email	addr1	addr2	addr3	addr4	postcode	country		empty		empty
	 * (order)		id		username	empty	empty	empty	empty	empty	empty	empty	empty		empty		auctionId	product
	 * (order)		id		username	empty	empty	empty	empty	empty	empty	empty	empty		empty		auctionId	product
	 *
	 * Multiorder header and sales have the same id in an exported CSV but an entirely different 
	 * structure on the sales page or manifest.
	 *		(Header) 	id 20
	 *		(Sale)		id 17
	 *		(Sale)		id 18
	 *		(Sale)		id 19
	 * The id behaviour is restored for export.
	 *
	 * @param array $CSVArray ebay formatted array
	 *
	 * @return array ebay formatted array sans headers with customer details copied into individual orders
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

	/**
	 * Prompts the user as to whether or not to combine ALL (>1) orders with the same name.
	 * Also combines references so it's clear to the user which orders are to go in one parcel.
	 *
	 * @param array $ebayArray clear array before after multi-purchase headers have been removed
	 *
	 * @return array ebayArray with any duplicates removed
	 */
	function promptForDuplicates($ebayArray) {
		
		$cleanEbayArray				= array();		// copy each wanted sale into a fresh array
		$aggregateDuplicateArray	= array();		// never match an uncombined order again
		$previouslyProcessedArray	= array();		// never match a combined order again
		foreach ($ebayArray as $key => $order) {

			// Skip previously processed order
			if (array_key_exists($key, $previouslyProcessedArray)) {
				continue;
			}

			list($duplicateArray, $serializedReferences) = getDuplicates($order, $ebayArray, $aggregateDuplicateArray);
			if (count($duplicateArray)) {

				echo PHP_EOL . PHP_EOL . colorize($order['firstnames'] . ' ' . $order['lastname'] . ' has placed multiple orders. ('. $serializedReferences . ')', 'NOTE');
				echo PHP_EOL . 'Do you wish to combine these orders and send in one parcel? (y/n):  ';

				if (promptUserConfirmation()) {
					// if yes, trim others and replace reference of first order with combined
					foreach ($duplicateArray as $mergeKey => $merge) {
						if ($key == $mergeKey) {
							$order['reference']	= $serializedReferences;
							$cleanEbayArray[] = $order;
						}
						$previouslyProcessedArray[$mergeKey] = true;
					}

				} else {
					// if no, continue as normal but don't ask again.
					$aggregateDuplicateArray = array_merge($aggregateDuplicateArray, $duplicateArray);
					$cleanEbayArray[] = $order;
				}
			} else {
				$cleanEbayArray[] = $order;
			}
		}

		return $cleanEbayArray;
	}

	/**
	 * Requests user input and constructs a hermes array ready for outputting
	 *
	 * @param array $ebayArray a complete, de-duplicated clean ebay order array
	 * @param string $contents the default contents string for myHermes
  	 * @param boolean $specifyWeight whether or not to request input on weights
	 * @param float $defaultWeight the default weight to use if not specifying per-package.
	 *
	 * @return array completed myHermes array ready for outputting to CSV.
	 */
	function convertEbayToHermes($ebayArray, $contents, $specifyWeight, $defaultWeight) {

		$hermesArray = array();
		foreach($ebayArray as $key => $order) {

			$hermesArray[$key] = $order;

			// contents (currently static)
			$hermesArray[$key]['contents'] = $contents;

			// weight (user-specified or default to 0.5)
			if ($specifyWeight) {
				echo 'Please enter weight (Kg) for eBay order ' . $order['reference'] . ' (' . $order['firstnames'] . ' ' . $order['lastname'] . '):  ';
				$hermesArray[$key]['weight'] = getUserWeight();
			} else {
				echo 'Default to ' . $defaultWeight . 'Kg (' . $order['firstnames'] . ' ' . $order['lastname'] . '):  ';
				$hermesArray[$key]['weight'] = $defaultWeight;
			}

			complete();
		}
		echo PHP_EOL . 'Imported ' . count($hermesArray) . ' orders successfully.' . PHP_EOL . PHP_EOL;
		return $hermesArray;
	}

	/**
	 * Exports a myHermes compatible CSV file.
	 *
	 * @param string $outputFile the filesystem location to output to
	 * @param string[] $hermesArray a converted array to output
	 * @param int $uxWait Number of microseconds to delay the process by.
	 */
	function outputHermes($outputFile, array $hermesArray, $uxWait) {
		echo 'Exporting header to ' . $outputFile . '...' . PHP_EOL;

		// Set headers, used also in automagically processing heremes array.
		// Keys are identical to hermesArray key structure.
		$headerList = array(
			'address1'				=> 'Address_line_1',
			'address2'				=> 'Address_line_2',
			'address3'				=> 'Address_line_3',
			'address4'				=> 'Address_line_4',
			'postcode'				=> 'Postcode',
			'firstnames'			=> 'First_name',
			'lastname'				=> 'Last_name',
			'email'					=> 'Email',
			'weight'				=> 'Weight(Kg)',
			'compensation'			=> 'Compensation(£)',
			'signature'				=> 'Signature(y/n)',
			'reference'				=> 'Reference',
			'contacts'				=> 'Contents',
			'value'					=> 'Parcel_value(£)',
			'phone'					=> 'Delivery_phone',
			'safe_place'			=> 'Delivery_safe_place',
			'delivery_instructions'	=> 'Delivery_instructions'
		);

		$file = new \SplFileObject($outputFile, 'w');

		$file->fwrite(encodeCsvLine($headerList));

		$recordNum = 0;
		foreach ($hermesArray as $record) {
			$recordNum++;
			echo 'Exporting record ' . $recordNum . ': ' . $record['firstnames'] . ' ' . $record['lastname'] . '...';

			$csvLine = mungeCSVFormat($headerList, $record);

			$file->fwrite(
				encodeCsvLine($csvLine)
			);

			complete();
		}

		$file->fflush();

		usleep($uxWait);
		echo PHP_EOL . 'Exported ' . $recordNum . ' records to ' . $outputFile . '...' . PHP_EOL . PHP_EOL;
		usleep($uxWait);
	}

	
	/**
	 * Returns an array of all duplicate orders with the keys identical to the original array.
	 *
	 * @param array $currentOrder the order array to look up
	 * @param array $orderList the full ebayArray with no multi-headers
	 * @param array $ignoreList an array of orders to skip (if a user already said not to combine)
	 *
	 * @return array [array of duplicate orders][string formatted matched references]
	 */
	function getDuplicates($currentOrder, $orderList, $ignoreList) {
		$duplicates = array();
		$references = array();
		foreach ($orderList as $key => $order) {

			// Skip ignores
			if (false !== array_search($currentOrder, $ignoreList)) {
				continue;
			}

			if ($currentOrder['firstnames'] == $order['firstnames'] && $currentOrder['lastname'] == $order['lastname']) {
				// maintain consistent keys across arrays
				$duplicates[$key]	= $order;
				$references[]		= $order['reference'];
			}
		}
		$refs = implode(', ', $references);

		// if only one match, clear array (but we want to include the original if there is!)
		if (count($duplicates) == 1) {
			$duplicates = array();
		}

		return array($duplicates, $refs);
	}



	

	/** HELPER FUNCTIONS **/


	/**
	 * Converts text string to Upper case starting letters.
	 * Useful for normalizing customer details to be printed. Good readability!
	 * @param string $value
	 *
	 * @return string
	 */
	function normalizeCSVString($value)
	{
		return ucwords(strtolower($value));
	}

	/**
	 * Strips the £ symbol from a value. Matches x.xx, .xx, x., x.x etc. 
	 * @param string $value
	 *
	 * @return string
	 */
	function normalizeValue($value)
	{
		$matches = array();

		$regex = "
		/
			(?:			# decimalised subpattern
				\d*		# 0 or more digits
				\.		# decimal point
				\d*		# 0 or more digits
			)
			|
			\d+			# single digit pattern
		/x";

		if (!preg_match($regex, $value, $matches)) {
			throw new Exception('Could not parse value');
		}

		return $matches[0];
	}


	/**
	 * Performs the dirty job of munging the internal representation of data to one ready for serialization to
	 * myHermes CSV.
	 *
	 * Pivot representation of the data on the array keys from the myHermes header, defaulting to an empty string if
	 * omitted.
	 *
	 * @param string[] $headerList
	 * @param string[] $record
	 *
	 * @return string[]
	 */
	function mungeCSVFormat(array $headerList, array $record)
	{
		$csvLine = array();
		foreach (array_keys($headerList) as $key) {
			$csvLine[$key] = '';
			if (array_key_exists($key, $record)) {
				$csvLine[$key] = $record[$key];
			}
		}
		return $csvLine;
	}


	/**
	 * Encode a CSV line, forcing all values to be quoted.
	 *
	 * @param array $csvLine
	 *
	 * @return string
	 */
	function encodeCsvLine(array $csvLine)
	{
		$quote = function($value) {
			return '"' . str_replace('"', '""', trim($value)) . '"';
		};

		return implode(
			',', array_map($quote, $csvLine)
		) . PHP_EOL;
	}


	/**
	 * Asks the user whether or not they wish to manually specify weights with parcels
	 *
	 * @return boolean yes/no response
	 */
	function getUserWeightPref() {
		echo PHP_EOL . 'Weights of each order default to <1Kg.' . PHP_EOL . 'Would you prefer to specify weights for each order (Kg)? (y/n)' . PHP_EOL;

		if (promptUserConfirmation()) {
			echo PHP_EOL . colorize('Please keep in mind that weights will be reduced slightly to drop below cost threshold.', 'NOTE') . PHP_EOL . PHP_EOL;
			return true;
		}

		return false;
	}


	/**
	 * Get user input for weight of an order
	 * Reduces the weight by 0.01% in order to drop integer values below threshold for parcel weights
	 * Maximum weight input of 15Kg
	 *
	 * @return float weight value
	 */
	function getUserWeight() {
		$handle = fopen('php://stdin', 'r');

		$weight = 0;

		while (0 == $weight) {
			$line = fgets($handle);
			$line = trim($line, "\r\n");

			// Validate user-provided function, defaulting to the value from getUserWeight() when invalid.
			switch (true) {
				case !is_numeric($line):
					echo colorize('Invalid weight, please try again:', 'FAILURE') . '  ';
					break;

				case $line > 15:
					echo colorize('This is more than myHermes allows! Please try again:', 'FAILURE') . '  ';
					break;

				case $line == 0:
					echo colorize('There is no such thing is weightless. Sorry. Try again:', 'FAILURE') . '  ';
					break;

				case $line < 0:
					echo colorize('Inverse weight is a fantasy. Stop it. Try again:', 'FAILURE') . '  ';
					break;

				default:
					// Reduce slightly to drop below parcel weight threshold (10Kg = 9.9 Kg = 5-10Kg parcel)
					$weight = $line * 0.99;	
					break;
			}
		}

		return $weight;
	}


	/**
	 * Simple get true or false based on user input of "y/yes" or "n/no"
	 *
	 * Basic error handling included
	 *
	 * @return boolean True if the user said yes, false if no.
	 */
	function promptUserConfirmation() {
		$handle = fopen ('php://stdin', 'r');

		$selection = null;

		while (is_null($selection)) {
			$line = fgets($handle);
			$line = trim($line, "\r\n");

			switch (true) {
				case $line == 'y':
				case $line == 'yes':
					$selection = 'yes';
					break;

				case $line == 'n':
				case $line == 'no':
					$selection = 'no';
					break;

				default:
					echo colorize('Invalid input, please try again (y/n):', 'FAILURE') . '  ';
					break;
			}
		}

		return 'yes' == $selection;
	}


	/**
	 * Completion of a task with small usability pause
	 * Simply outputs "Done" in green text and waits 125 mSec
	 * (Prevents user from seeing an instant giant wall of text!)
	 */
	function complete() {
		global $uxWait;	// Sue me, don't want to pass this in everywhere.
		usleep($uxWait);
		echo colorize('Done!', 'SUCCESS') . PHP_EOL;
	}


	/**
	 * Handy coloring helper function
	 *
	 * @throws Exception If an invalid status is passed in.
	 *
	 * @param string $text input text to be colored
	 * @param string $status determines color:
	 *		'SUCCESS' 	= green
	 *		'FAILURE' 	= red
	 *		'WARNING' 	= yellow
	 *		'NOTE'		= blue
	 *
	 * @return string colored text string
	 */
	function colorize($text, $status) {
		
		// colors do nothing on windows, return blank string
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			return $text;
		}

		switch ($status) {
			case 'SUCCESS':
				$out = '[42m'; //Green background
				break;
			case 'FAILURE':
				$out = '[41m'; //Red background
				break;
			case 'WARNING':
				$out = '[43m'; //Yellow background
				break;
			case 'NOTE':
				$out = '[44m'; //Blue background
				break;
			default:
				throw new Exception('Invalid status: ' . $status);
		}
		return chr(27) . $out . $text . chr(27) . '[0m';
	}
