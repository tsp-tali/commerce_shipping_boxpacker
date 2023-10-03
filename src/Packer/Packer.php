<?php

namespace Drupal\commerce_shipping_boxpacker\Packer;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Packer\PackerInterface;
use Drupal\commerce_shipping\ProposedShipment;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\commerce_shipping_boxpacker\Packer\PackerBox;
use Drupal\commerce_shipping_boxpacker\Packer\PackerItem;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\physical\Calculator;
use Drupal\physical\Weight;
use Drupal\physical\WeightUnit;
use Drupal\profile\Entity\ProfileInterface;

class Packer implements PackerInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Packer constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(OrderInterface $order, ProfileInterface $shipping_profile) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function pack(OrderInterface $order, ProfileInterface $shipping_profile) {
    $items = [];
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order->id());

    foreach ($order->getItems() as $order_item) {
      /** @var \Drupal\physical\Weight $weight */
      $weight = $this->getWeightForOrderItem($order_item);

      $quantity = $order_item->getQuantity();
      if (Calculator::compare($order_item->getQuantity(), '0') == 0) {
        continue;
      }

      $items[] = [
        'order_item' => $order_item,
        'title' => $order_item->getTitle() ?? 'Shipment Item',
        'quantity' => $quantity,
        'weight' => $weight->multiply($quantity),
        'declared_value' => $order_item->getAdjustedTotalPrice(['promotion']),
      ];
    }
    $proposed_shipments = [];
    if (!empty($items)) {
      $proposed_shipments = $this->buildBoxes($items, $order, $shipping_profile);
    }

    return $proposed_shipments;
  }

  /**
   * Separates the items into boxes to ship.
   *
   * @param array $items
   *   The items in the order.
   * @param \Drupal\commerce_order\Entity\Order $order
   *   The order.
   * @param \Drupal\profile\Entity\ProfileInterface $shipping_profile
   *   The shipping profile being used.
   *
   * @return \Drupal\commerce_shipping\ProposedShipment
   *   The boxes to ship.
   */
  protected function buildBoxes(array $items, $order, $shipping_profile) {
    // Stash the order items by order item ID
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface[] $order_items */
    $order_items = [];
    foreach ($items as $item) {
      $order_items[$item['order_item']->id()] = $item['order_item'];
    }
    /** @var \Drupal\Core\Config\Entity\ConfigEntityStorage $package_type_storage */
    $package_type_storage = $this->entityTypeManager->getStorage('commerce_package_type');
    /** @var \Drupal\commerce_shipping\Entity\PackageTypeInterface[] $package_types */
    $package_types = $package_type_storage->loadByProperties();
    $boxPacker = new \DVDoug\BoxPacker\Packer();

    foreach ($package_types as $package_type) {
      $dimensions = $package_type->getDimensions();
      $package_weight = $package_type->getWeight();
      $max_weight = $package_type->getThirdPartySetting('commerce_shipping_boxpacker', 'max_weight');
      if (empty($max_weight)) {
        $max_weight = [
          'number' => 10000,
          'unit' => 'g',
        ];
      }

      // Convert the dimensions to millimeters, so there are no errors in
      // processing.
      $this->convertDimensionsToMillimeters($dimensions);
      // Convert weights to grams, so there are no errors in processing.
      $this->convertWeightToGrams($package_weight);
      $this->convertWeightToGrams($max_weight);

      $boxPacker->addBox(
        new PackerBox(
          $package_type->id(),
          $dimensions['length'],
          $dimensions['width'],
          $dimensions['height'],
          $package_weight['number'],
          $dimensions['length'],
          $dimensions['width'],
          $dimensions['height'],
          $max_weight['number']
        )
      );
    }

    foreach ($items as $item) {
      if (($variation = $item['order_item']->getPurchasedEntity()) && $variation->hasField('dimensions') && !$variation->get('dimensions')->isEmpty()) {
        $dimensions = $variation->get('dimensions')->first()->getValue();
      }
      else {
        $dimensions = [
          'length' => '0',
          'width' => '0',
          'height' => '0',
          'unit' => 'in',
        ];
      }

      $weight = [
        'number' => $item['weight']->getNumber() / $item['quantity'],
        'unit' => $item['weight']->getUnit(),
      ];

      // Convert the dimensions to millimeters, so there are no errors in
      // processing.
      $this->convertDimensionsToMillimeters($dimensions);
      // Convert weights to grams, so there are no errors in processing.
      $this->convertWeightToGrams($weight);

      $boxPacker->addItem(
        new PackerItem(
          $item['order_item']->id(),
          $dimensions['width'],
          $dimensions['length'],
          $dimensions['height'],
          $weight['number'],
          FALSE
        ),
        $item['quantity']
      );
    }

    /** @var \DVDoug\BoxPacker\PackedBoxList $packedBoxes */
    $packedBoxes = $boxPacker->pack();

    /** @var \Drupal\commerce_shipping\ProposedShipment[] $proposed_shipments */
    $proposed_shipments = [];
    foreach ($packedBoxes as $i => $box) {
      $box_items = [];
      foreach ($box->getItems() as $packedItem) {
        $order_item_id = $packedItem->getItem()->getDescription();
        $box_items[$order_item_id][] = [
          'title' => 'Shipment item',
          'quantity' => 1,
          'weight' => $packedItem->getItem()->getWeight(),
          'declared_value' => new Price(0, 'USD'),
        ];
      }

      /** @var \Drupal\commerce_shipping\ShipmentItem[] $proposed_shipment_items */
      $proposed_shipment_items = [];
      foreach ($box_items as $order_item_id => $box_item_details) {
        $order_item = $order_items[$order_item_id];
        $order_item_weight = $this->getWeightForOrderItem($order_item);
        $quantity = count($box_item_details);
        $proposed_shipment_items[] = new ShipmentItem([
          'order_item_id' => $order_item->id(),
          'title' => $order_item->getTitle() ?? 'Shipment item',
          'quantity' => $quantity,
          'weight' => $order_item_weight->multiply($quantity),
          'declared_value' => $order_item->getAdjustedTotalPrice(['promotion'])->multiply((string) ($quantity / $order_item->getQuantity())),
        ]);
      }

      $package_type_machine_name = $box->getBox()->getReference();
      /** @var \Drupal\commerce_shipping\Entity\PackageTypeInterface $package_type */
      $package_type = $package_type_storage->load($package_type_machine_name);
      /** @var \Drupal\commerce_shipping\ProposedShipment[] $proposed_shipments */
      $proposed_shipments[] = new ProposedShipment([
        'type' => $this->getShipmentType($order),
        'order_id' => $order->id(),
        'title' => t('Shipment #@count (@size)', ['@count' => ($i + 1), '@size' => $package_type->label()]),
        'items' => $proposed_shipment_items,
        'shipping_profile' => $shipping_profile,
        'package_type_id' => 'commerce_package_type:' . $package_type->uuid(),
      ]);
    }

    return $proposed_shipments;
  }

  /**
   * Gets the weight for an item in an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *
   * @return \Drupal\physical\Weight
   */
  protected function getWeightForOrderItem(OrderItemInterface $order_item) : Weight {
    /** @var \Drupal\commerce_product\Entity\ProductVariation $purchased_entity */
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity !== NULL && $purchased_entity->hasField('weight') && !$purchased_entity->get('weight')->isEmpty()) {
      /** @var \Drupal\physical\Weight $weight */
      $weight = $purchased_entity->get('weight')->first()->toMeasurement();
    }
    else {
      $weight = new Weight(0, WeightUnit::GRAM);
    }
    return $weight;
  }

  /**
   * Gets the shipment type for the current order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The shipment type.
   */
  protected function getShipmentType(OrderInterface $order) {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityStorage $order_type_storage */
    $order_type_storage = $this->entityTypeManager->getStorage('commerce_order_type');
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $order_type_storage->load($order->bundle());

    return $order_type->getThirdPartySetting('commerce_shipping', 'shipment_type');
  }

  /**
   * Convert dimensions to millimeters.
   *
   * @param array $dimensions
   *   The dimensions of the product.
   */
  protected function convertDimensionsToMillimeters(array &$dimensions) {
    if (empty($dimensions['unit'])) {
      \Drupal::logger('commerce_shipping_boxpacker')->debug('No units were specified for the dimensions, and so they were not modified.');
      return;
    }

    // The default is to not make any changes.
    $value_multiplier = 1;

    switch ($dimensions['unit']) {
      case 'cm':
        $value_multiplier = 10;
        break;

      case 'm':
        $value_multiplier = 100;
        break;

      case 'in':
        $value_multiplier = 25.4;
        break;

      case 'ft':
        $value_multiplier = 304.8;
        break;
    }

    if ($value_multiplier !== 1) {
      $dimensions['length'] = $dimensions['length'] * $value_multiplier;
      $dimensions['width'] = $dimensions['width'] * $value_multiplier;
      $dimensions['height'] = $dimensions['height'] * $value_multiplier;
      $dimensions['unit'] = 'mm';
    }
  }

  /**
   * Converts weight to grams.
   *
   * @param array $weight
   *   The weight of the product or package.
   */
  protected function convertWeightToGrams(&$weight) {
    if (empty($weight['unit'])) {
      \Drupal::logger('commerce_shipping_boxpacker')->debug('No units were specified for the weight, and so it was not modified.');
      return;
    }

    // The default is to not make any changes.
    $value_multiplier = 1;

    switch ($weight['unit']) {
      case 'kg':
        $value_multiplier = 1000;
        break;

      case 'oz':
        $value_multiplier = 28.35;
        break;

      case 'lb':
        $value_multiplier = 453.6;
        break;
    }

    if ($value_multiplier !== 1) {
      $weight['number'] = $weight['number'] * $value_multiplier;
      $weight['unit'] = 'g';
    }
  }

}
