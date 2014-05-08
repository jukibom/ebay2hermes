ebay2hermes
===========

Simple php script to convert an eBay exported CSV to a myHermes compatible import CSV. 

The reason I created this script is because of an odd 'ghost order' bug in myHermes' eBay import page. They know about the issue but it sounds like it may be a problem on eBay's end. Whatever the reason, this script is a nice workaround and should make entering weights / combining parcels a lot easier / faster too.

This script: 
  * Prompts the user to combine multiple orders into a single parcel
  * Prompts the user for weights for each parcel
  * Handles the weird eBay export of multi-purchase orders (if the user orders >1 item in a single basket)
  * Imports the correct email (for tracking)
  * Uses eBay order reference number(s) to be printed on the label. 
  * Processes orders in the same order as the eBay sales page or printed manifest (no more ordering by postcode!)
  * Handles splitting of names into forename(s) / surname.
  * Handles ebay CSV sanitising
  
To use:
  * Download your sales data from eBay
  * Place file in same folder as this script
  * run 'php ebay2hermes.php -f yourfilename.csv'

Works fine on windows except for artifacts before and after colored terminal text. I'll look into it.
