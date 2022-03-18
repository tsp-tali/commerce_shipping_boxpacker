<?php

namespace Drupal\commerce_shipping_boxpacker\Packer;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Packer\PackerInterface;
use Drupal\commerce_shipping\ProposedShipment;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\physical\Calculator;
use Drupal\physical\Weight;
use Drupal\physical\WeightUnit;
use Drupal\profile\Entity\ProfileInterface;
use DVDoug\BoxPacker\Test\TestBox;
use DVDoug\BoxPacker\Test\TestItem;

class Packer implements PackerInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
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

  public function pack(OrderInterface $order, ProfileInterface $shipping_profile) {
    $items = [];
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order->id());

    foreach ($order->getItems() as $order_item) {
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

  protected function buildBoxes(array $items, $order, $shipping_profile) {
    // Stash the order items by order item ID
    /** @var OrderItemInterface[] $order_items */
    $order_items = [];
    foreach ($items as $item) {
      $order_items[$item['order_item']->id()] = $item['order_item'];
    }
    $package_type_storage = $this->entityTypeManager->getStorage('commerce_package_type');
    /** @var \Drupal\commerce_shipping\Entity\PackageTypeInterface[] $package_types */
    $package_types = $package_type_storage->loadByProperties();
    $boxPacker = new \DVDoug\BoxPacker\Packer();

    foreach ($package_types as $package_type) {
      $dimensions = $package_type->getDimensions();
      $weight = $package_type->getWeight();
      $boxPacker->addBox(
        new TestBox(
          $package_type->id(),
          $dimensions['length'],
          $dimensions['width'],
          $dimensions['height'],
          $weight['number'],
          $dimensions['length'],
          $dimensions['width'],
          $dimensions['height'],
          10000  // @todo: Make box max weight configurable
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
      $weight = $item['weight']->getNumber() / $item['quantity'];
      $boxPacker->addItem(
        new TestItem(
          $item['order_item']->id(),
          $dimensions['width'],
          $dimensions['length'],
          $dimensions['height'],
          $weight,
          FALSE
        ),
        $item['quantity']
      );
    }

    $packedBoxes = $boxPacker->pack();

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

      /** @var \Drupal\commerce_shipping\Entity\PackageTypeInterface $package_type */
      $package_type_machine_name = $box->getBox()->getReference();
      $package_type = $package_type_storage->load($package_type_machine_name);
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

  protected function getWeightForOrderItem(OrderItemInterface $order_item) : Weight {
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
    $order_type_storage = $this->entityTypeManager->getStorage('commerce_order_type');
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $order_type_storage->load($order->bundle());

    return $order_type->getThirdPartySetting('commerce_shipping', 'shipment_type');
  }
}
