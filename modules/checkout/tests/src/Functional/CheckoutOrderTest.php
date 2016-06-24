<?php

namespace Drupal\Tests\commerce_checkout\Functional;

use Drupal\commerce_store\StoreCreationTrait;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\commerce_order\Entity\LineItem;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\LineItemType;
use Drupal\user\RoleInterface;

/**
 * Tests the checkout of an order.
 *
 * @group commerce
 */
class CheckoutOrderTest extends CommerceBrowserTestBase {

  use StoreCreationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface;
   */
  protected $account;

  /**
   * The product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $product;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['system', 'field', 'user', 'text',
    'entity', 'views', 'address', 'profile', 'commerce', 'inline_entity_form',
    'commerce_price', 'commerce_store', 'commerce_product', 'commerce_cart',
    'commerce_checkout', 'commerce_order',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $store = $this->createStore('Demo', 'demo@example.com', 'default', TRUE);

    $variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => [
        'amount' => 9.99,
        'currency_code' => 'USD',
      ],
    ]);

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $this->product = $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => 'My product',
      'variations' => [$variation],
      'stores' => [$store],
    ]);
  }

  /**
   * Tests order access.
   */
  public function testOrderAccess() {
    $user = $this->drupalCreateUser();
    $user2 = $this->drupalCreateUser();

    LineItemType::create([
      'id' => 'test',
      'label' => 'Test',
      'orderType' => 'default',
    ])->save();
    $profile = Profile::create([
      'type' => 'billing',
      'address' => [
        'country' => 'FR',
        'postal_code' => '75002',
        'locality' => 'Paris',
        'address_line1' => 'A french street',
        'recipient' => 'John LeSmith',
      ],
    ]);
    $profile->save();
    $line_item = LineItem::create([
      'type' => 'test',
    ]);
    $line_item->save();
    $order = Order::create([
      'type' => 'default',
      'state' => 'in_checkout',
      'order_number' => '6',
      'mail' => 'test@example.com',
      'uid' => $user->id(),
      'ip_address' => '127.0.0.1',
      'billing_profile' => $profile,
      'line_items' => [$line_item],
    ]);
    $order->save();

    // Anonymous user with no session.
    $this->drupalLogout();
    $this->drupalGet('/checkout/' . $order->id());
    $this->assertSession()->statusCodeEquals(403);

    // Authenticated order owner.
    $this->drupalLogin($user);
    $this->drupalGet('/checkout/' . $order->id());
    $this->assertSession()->statusCodeEquals(200);

    // Authenticated user who does not own the order.
    $this->drupalLogin($user2);
    $this->drupalGet('/checkout/' . $order->id());
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($user);

    // Order with no line items.
    $order->removeLineItem($line_item)->save();
    $this->drupalGet('/checkout/' . $order->id());
    $this->assertSession()->statusCodeEquals(403);

    // Authenticated order owner without the 'access checkout' permission.
    $order->addLineItem($line_item)->save();
    user_role_revoke_permissions(RoleInterface::AUTHENTICATED_ID, ['access checkout']);
    $this->drupalGet('/checkout/' . $order->id());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests than an order can go through checkout steps.
   */
  public function testGuestOrderCheckout() {
    $this->drupalLogout();
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');
    $this->submitForm([], 'Continue as Guest');
    $this->submitForm([
      'contact_information[email]' => 'guest@example.com',
      'contact_information[email_confirm]' => 'guest@example.com',
      'billing_information[address][0][recipient]' => $this->randomString(),
      'billing_information[address][0][organization]' => $this->randomString(),
      'billing_information[address][0][address_line1]' => $this->randomString(),
      'billing_information[address][0][locality]' => $this->randomString(),
    ], 'Continue to review');
    $this->assertSession()->pageTextContains('Contact information');
    $this->assertSession()->pageTextContains('Billing information');
    $this->submitForm([], 'Pay and complete purchase');
    $this->assertSession()->pageTextContains('Your order number is 1. You can view your order on your account page when logged in.');
  }

  /**
   * Tests that you can register from the checkout pane.
   */
  public function testRegisterOrderCheckout() {
    // First we enable the checkout registration.
    $config = \Drupal::configFactory()->getEditable('commerce_checkout.commerce_checkout_flow.default');
    $config->set('configuration.panes.login.allow_guest_checkout', FALSE);
    $config->set('configuration.panes.login.allow_registration', TRUE);
    $config->save();

    $this->drupalLogout();
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');
    $this->assertSession()->pageTextContains('Create new account');
    $this->submitForm([
      'login[register][mail]' => 'guest@example.com',
      'login[register][pass][pass1]' => 'pass',
      'login[register][pass][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('Registration successful. You can now continue the checkout.');
    $this->assertSession()->pageTextContains('Billing information');

    // Test various validations. We first redo the same as above to emulate a
    // double registration.
    $this->drupalLogout();
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');
    $this->assertSession()->pageTextContains('Create new account');

    // Already used e-mail.
    $this->submitForm([
      'login[register][mail]' => 'guest@example.com',
      'login[register][pass][pass1]' => 'pass',
      'login[register][pass][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('A user is already registered with this email.');

    // Invalid characters.
    $this->submitForm([
      'login[register][mail]' => 'guest@#.com',
      'login[register][pass][pass1]' => 'pass',
      'login[register][pass][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('The email you have used contains bad characters.');

    // Empty e-mail.
    $this->submitForm([
      'login[register][mail]' => '',
      'login[register][pass][pass1]' => 'pass',
      'login[register][pass][pass2]' => 'pass',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('Email is mandatory.');

    // Empty password.
    $this->submitForm([
      'login[register][mail]' => 'valid@example.com',
      'login[register][pass][pass1]' => '',
      'login[register][pass][pass2]' => '',
    ], 'Create account and continue');
    $this->assertSession()->pageTextContains('Password is mandatory.');
  }

}
