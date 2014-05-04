ebay2hermes
===========

Simple php script to convert an eBay exported CSV to a myHermes compatible import CSV.

This script: 
  * Prompts the user to combine multiple orders into a single parcel
  * Prompts the user for weights for each parcel
  * Handles the weird eBay export of multi-purchase orders (if the user orders >1 item in a single basket)
  * Imports the correct email (for tracking)
  * Uses eBay order reference number(s) to be printed on the label. 
  * Processes orders in the same order as the eBay sales page or printed manifest (no more ordering by postcode!)
  * Handles splitting of names into forename(s) / surname.
  
To use:
  * Download your sales data from eBay
  * Place file in same folder as this script
  * Rename the file "ebay_sanitised.csv" (we have to sanitise our files first as eBay does not escape " characters correctly - you will likely have no problem with this unless you have " characters in any of your auction titles)
  * run 'php ebay2hermes.php'
