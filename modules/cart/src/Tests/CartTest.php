<?php

/**
 * @file
 * Contains \Drupal\commerce_cart\Tests\CartTest.
 */

namespace Drupal\commerce_cart\Tests;

use Drupal\commerce_order\Tests\OrderTestBase;

/**
 * Tests the cart page.
 *
 * @group commerce_cart
 */
class CartTest extends OrderTestBase {

  /**
   * The cart order to test against.
   *
   * @var \Drupal\commerce_order\Entity\Order
   */
  protected $cart;

  /**
   * The cart manager for test cart operations.
   *
   * @var \Drupal\commerce_cart\CartManager
   */
  protected $cartManager;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'commerce_order',
    'commerce_cart',
    'node',
    'views',
    'views_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer products',
      'access content',
      'administer views',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->cart = \Drupal::service('commerce_cart.cart_provider')->createCart('default', $this->store);
    $this->cartManager = \Drupal::service('commerce_cart.cart_manager');
    $this->cartManager->addEntity($this->cart, $this->variation);
  }

  /**
   * Test the cart page.
   */
  public function testCartPage() {
    $a = \Drupal::service('plugin.manager.views.field');
    $a->clearCachedDefinitions();
    $b = $a->createInstance('commerce_edit_quantity');
    $c  = get_class($b);
    print $c;
   // $a = $this->drupalGet('admin/structure/views/view/commerce_cart_form');
   // print $a;
    $a = $this->drupalGet('cart');
   // print $a;
    // Confirm the presence and functioning of the Quantity field.
    $this->assertFieldByXPath("//input[starts-with(@id, 'edit-edit-quantity')]", NULL, 'Quantity field present.');
    $this->assertFieldByXPath("//input[starts-with(@id, 'edit-edit-quantity')]", 1, 'Quantity field has correct number of items.');
    $this->assertField("edit-submit", 'Update cart button is present.');
    $values = [
      'edit_quantity[0]' => 2,
    ];
    $this->drupalPostForm(NULL, $values, t('Update cart'));
    $this->assertFieldByXPath("//input[starts-with(@id, 'edit-edit-quantity')]", 2, 'Cart updated with new quantity.');

    // Confirm the presence and functioning of the Remove button.
    $this->assertFieldByXPath("//input[starts-with(@id, 'edit-remove-button')]", NULL, 'Remove button is present.');
    $this->drupalPostForm(NULL, array(), t('Remove'));
    $this->assertText(t('Your shopping cart is empty.'), 'Product removed, cart empty.');
  }

}
