# Commerce Shipping BoxPacker

This module implements [dvdoug/BoxPacker](https://github.com/dvdoug/BoxPacker) as a Packer service for
[Commerce Shipping](https://www.drupal.org/project/commerce_shipping).

dvdoug/BoxPacker is an implementation of the "4D" bin packing/knapsack problem,
i.e., given a list of items, how many boxes do you need to fit them all in
taking into account physical dimensions and weights.

This module converts dimensions to millimeters and weights to grams, because
that is what the library uses when calculating the packing.


## Requirements

This module requires the following modules:

- The [dvdoug/BoxPacker](https://github.com/dvdoug/BoxPacker) library
- The [Commerce Shipping](https://www.drupal.org/project/commerce_shipping) module


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).
Once this module is installed, it just works.


## Configuration

1. Enable the module at Administration > Extend
2. Add the boxes, etc. you use to ship with at Commerce > Configuration >
   Package types (Package types is in the Shipping fieldset)
3. Make sure all your products have dimensions and weights (they should already)


## Maintainers

- Wayne Eaker - [zengenuity](https://www.drupal.org/u/zengenuity)
