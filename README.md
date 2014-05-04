ebay2hermes
===========

Simple php script to convert an eBay exported CSV to a myHermes compatible import CSV. 

BONUS: At the time of writing, myHermes has a really weird bug concerning importing 'ghost' sales from previous imports. I've told them months ago with detailed reports. I know what causes it, they don't seem to care. This script fixes it.

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
