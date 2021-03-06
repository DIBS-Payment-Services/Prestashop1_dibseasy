<?php
/**
 * 2016 - 2017 Invertus, UAB
 *
 * NOTICE OF LICENSE
 *
 * This file is proprietary and can not be copied and/or distributed
 * without the express permission of INVERTUS, UAB
 *
 * @author    INVERTUS, UAB www.invertus.eu <support@invertus.eu>
 * @copyright Copyright (c) permanent, INVERTUS, UAB
 * @license   Addons PrestaShop license limitation
 *
 * International Registered Trademark & Property of INVERTUS, UAB
 */

/**
 * Class DibsEasy
 */
class DibsEasy extends PaymentModule
{

    const FILENAME = 'validation';

    /**
     * @var \Symfony\Component\DependencyInjection\ContainerBuilder
     */
    private $container;

    private $errors;

    /**
     * Dibs constructor.
     */
    public function __construct()
    {
        $this->name = 'dibseasy';
        $this->author = 'Invertus';
        $this->tab = 'payments_gateways';
        $this->version = '1.2.1';
        $this->controllers = array('validation', 'checkout');
        $this->compatibility = array('min' => '1.5.6.0', 'max' => '1.6.1.99');
        $this->module_key = '7aa447652d62fa94766ded6234e74266';

        parent::__construct();

        $this->autoload();
        $this->compile();

        $this->displayName = $this->l('DIBS Easy Checkout');
        $this->description = $this->l('Accept payments via DIBS Easy Checkout.');
    }

    /**
     * Redirect to configuration page
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminDibsConfiguration'));
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (PHP_VERSION_ID < 50309) {
            $this->context->controller->errors[] = sprintf(
                $this->l('Minimum PHP version required for %s module is %s'),
                $this->displayName,
                '5.3.9'
            );
            return false;
        }

        /** @var \Invertus\DibsEasy\Install\Installer $installer */
        $installer = $this->get('dibs.installer');

        return parent::install() && $installer->install();
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        /** @var \Invertus\DibsEasy\Install\Installer $installer */
        $installer = $this->get('dibs.installer');

        return $installer->uninstall() && parent::uninstall();
    }

    /**
     * Get service from container
     *
     * @param string $id
     *
     * @return object
     */
    public function get($id)
    {
        return $this->container->get($id);
    }

    /**
     * Get parameter from service container
     *
     * @param string $name
     *
     * @return mixed
     */
    public function getParameter($name)
    {
        return $this->container->getParameter($name);
    }

    /**
     * Add Custom CSS and JS to front controller
     */
    public function hookActionFrontControllerSetMedia()
    {
        $controller = Tools::getValue('controller');

        $globalJsVariables = array(
            'dibsGlobal' => array(
                'checkoutUrl' => $this->context->link->getModuleLink($this->name, 'checkout'),
            ),
        );

        if ($this->isPS16()) {
            Media::addJsDef($globalJsVariables);
        }

        $this->context->controller->addJS(
            $this->getPathUri().'views/js/global.js'
        );

        if (in_array($controller, array('order'))) {
            $this->context->controller->addCSS($this->getPathUri().'views/css/front.css');
        }
    }

    /**
     * Add custom JS & CSS to admin controllers
     */
    public function hookActionAdminControllerSetMedia()
    {
        $controller = Tools::getValue('controller');

        if ('AdminOrders' == $controller) {
            $this->context->controller->addJS($this->getPathUri().'views/js/admin-orders.js');
        }
    }

    /**
     * Display payment option
     *
     * @param array $params
     *
     * @return string
     */
    public function hookPayment(array $params)
    {
        /** @var Cart $cart */
        $cart = $params['cart'];
        /** @var \Invertus\DibsEasy\Adapter\ConfigurationAdapter $configuration */
        $configuration = $this->get('dibs.adapter.configuration');
        $isFriendlyUrlOn = (bool) $configuration->get('PS_REWRITING_SETTINGS');

        if (!$this->active || !$this->checkCurrency($cart) || !$this->isConfigured() || !$isFriendlyUrlOn) {
            return '';
        }

        $this->context->smarty->assign(array(
            'dibs_payment_url' => $this->context->link->getModuleLink($this->name, 'checkout', array(), true),
        ));

        if (!$this->isPS16()) {
            $this->context->smarty->assign('dibs_img', $this->getPathUri().'views/img/dibs.png');
            return $this->context->smarty->fetch($this->getLocalPath().'views/templates/hook/payment15.tpl');
        }

        return $this->context->smarty->fetch($this->getLocalPath().'views/templates/hook/payment.tpl');
    }

    /**
     * Display payment return content
     *
     * @param array $params
     *
     * @return string
     */
    public function hookPaymentReturn(array $params)
    {
        if (!$this->active) {
            return '';
        }

        /** @var Order $order */
        $order = $params['objOrder'];
        $idOrder = $order->id;
        $idLang = $this->context->language->id;
        $currentOrderState = $order->getCurrentOrderState();
        $orderDetailsUrl = $this->context->link->getPageLink('order-detail', 1, $idLang, array('id_order' => $idOrder));

        $this->context->smarty->assign(array(
            'currentOrderState' => $currentOrderState->name[$this->context->language->id],
            'orderDetailsUrl' => $orderDetailsUrl,
        ));

        return $this->context->smarty->fetch($this->getLocalPath().'views/templates/hook/payment_return.tpl');
    }

    /**
     * Display payment actions
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayAdminOrder(array $params)
    {
        $idOrder = $params['id_order'];
        $order = new Order($idOrder);

        if ($this->name != $order->module) {
            return '';
        }

        /** @var \Invertus\DibsEasy\Repository\OrderPaymentRepository $orderPaymentRepository */
        $orderPaymentRepository = $this->get('dibs.repository.order_payment');
        $orderPayment = $orderPaymentRepository->findOrderPaymentByOrderId($idOrder);
        if (!$orderPayment ||
            (!$orderPayment->canBeCanceled() &&
            !$orderPayment->canBeCharged() &&
            !$orderPayment->canBeRefunded())
        ) {
            return '';
        }

        $adminOrderUrl = $this->context->link->getAdminLink('AdminOrders');

        $this->context->smarty->assign(array(
            'dibsPaymentCanBeCanceled' => $orderPayment->canBeCanceled(),
            'dibsPaymentCanBeCharged' => $orderPayment->canBeCharged(),
            'dibsPaymentCanBeRefunded' => $orderPayment->canBeRefunded(),
            'dibsCancelUrl' => $adminOrderUrl.'&action=cancelPayment&id_order='.(int)$idOrder,
            'dibsChargeUrl' => $adminOrderUrl.'&action=chargePayment&id_order='.(int)$idOrder,
            'dibsRefundUrl' => $adminOrderUrl.'&action=refundPayment&id_order='.(int)$idOrder,
        ));

        if (!$this->isPS16()) {
            return $this->context->smarty->fetch($this->getLocalPath().'views/templates/hook/displayAdminOrder15.tpl');
        }

        return $this->context->smarty->fetch($this->getLocalPath().'views/templates/hook/displayAdminOrder.tpl');
    }

    /**
     * Handle partial refund on order slip creation
     *
     * @param array $params
     */
    public function hookActionOrderSlipAdd(array $params)
    {
        /** @var Order $order */
        $order = $params['order'];

        $shippingCostRefund = Tools::getValue('partialRefundShippingCost');

        /** @var \Invertus\DibsEasy\Action\PaymentRefundAction $refundAction */
        $refundAction = $this->get('dibs.action.payment_refund');

        $success = $refundAction->partialRefundPayment($order, $params['productList'], $shippingCostRefund);
        if (!$success) {
            $this->context->controller->errors[] =
                $this->l('Partial refund was successfully created, but failed to partially refund in DIBS Easy');
        }
    }

    /**
     * Add JS variables on PS 1.5
     */
    public function hookHeader()
    {
        if ($this->isPS16()) {
            return;
        }

        $this->context->smarty->assign(array(
            'dibsGlobal' => array(
                'checkoutUrl' => $this->context->link->getModuleLink($this->name, 'checkout'),
            ),
        ));

        return $this->context->smarty->fetch($this->getLocalPath().'views/templates/hook/header.tpl');
    }

    /**
     * Get additional template varibles
     *
     * @param array $params
     */
    public function hookActionGetExtraMailTemplateVars(array &$params)
    {
        $template = $params['template'];
        if ('order_conf' != $template) {
            return;
        }

        /** @var Cart $cart */
        $cart = $params['cart'];
        $idOrder = Order::getOrderByCartId($cart->id);
        $order = new Order($idOrder);

        if ($this->name != $order->module || !Validate::isLoadedObject($order)) {
            $params['extra_template_vars']['{dibs_html_block}'] = '';
            $params['extra_template_vars']['{dibs_txt_block}'] = '';
        }

        $params['extra_template_vars'] =
            $this->getExtraTemplateVars($order->id_cart, $order->id_carrier, $order->getCurrentState());
    }

    /**
     * Associate new address from payment with order/cart
     * @todo: should be moved to separate class
     *
     * @param array $params
     *
     * @return bool
     */
    public function hookActionObjectOrderAddAfter(array $params)
    {
        /** @var Order $order */
        $order = $params['object'];

        if ($order->module != $this->name) {
            return true;
        }

        /** @var \Invertus\DibsEasy\Repository\OrderPaymentRepository $orderPaymentRepository */
        $orderPaymentRepository = $this->get('dibs.repository.order_payment');
        $orderPayment = $orderPaymentRepository->findOrderPaymentByCartId($this->context->cart->id);

        /** @var \Invertus\DibsEasy\Action\PaymentGetAction $paymentGetAction */
        $paymentGetAction = $this->get('dibs.action.payment_get');
        $payment = $paymentGetAction->getPayment($orderPayment->id_payment);

        /** @var \Invertus\DibsEasy\Util\AddressChecksum $addressChecksumUtil */
        $addressChecksumUtil = $this->get('dibs.util.address_checksum');

        $shippingAddress = $payment->getConsumer()->getShippingAddress();
        $person = $payment->getConsumer()->getPrivatePerson();
        $company = $payment->getConsumer()->getCompany();
        $firstName = $person->getFirstName() ?: $company->getFirstName();
        $lastName = $person->getLastName() ?: $company->getLastName();

        if ($person->getPhoneNumber()->getPrefix()) {
            $phone = $person->getPhoneNumber()->getPrefix().$person->getPhoneNumber()->getNumber();
        } else {
            $phone = $company->getPhoneNumber()->getPrefix().$company->getPhoneNumber()->getNumber();
        }

        /** @var \Invertus\DibsEasy\Service\CountryMapper $countryMapper */
        $countryMapper = $this->get('dibs.service.country_mapper');
        $countryIso = $countryMapper->getIso2Code($shippingAddress->getCountry());

        $saveAddress = true;
        $deliveryAddress = new Address($this->context->cart->id_address_delivery);

        $deliveryAddress->alias = $this->l('DIBS EASY Address');
        $deliveryAddress->address1 = $shippingAddress->getAddressLine1();
        $deliveryAddress->address2 = $shippingAddress->getAddressLine2();
        $deliveryAddress->postcode = $shippingAddress->getPostalCode();
        $deliveryAddress->city = $shippingAddress->getCity();
        $deliveryAddress->id_country = Country::getByIso($countryIso);
        $deliveryAddress->firstname = $firstName;
        $deliveryAddress->lastname = $lastName;
        $deliveryAddress->phone = $phone;
        $deliveryAddress->id_customer = $this->context->cart->id_customer;

        $deliveryAddressChecksum = $addressChecksumUtil->generateChecksum($deliveryAddress);

        // If same address already exists then use it, otherwise create new one
        $customerAddresses = new Collection('Address', $this->context->language->id);
        $customerAddresses->where('id_customer', '=', $this->context->cart->id_customer);
        $customerAddresses->where('deleted', '=', 0);

        /** @var Address $address */
        foreach ($customerAddresses as $address) {
            $addressChecksum = $addressChecksumUtil->generateChecksum($address);

            if ($addressChecksum == $deliveryAddressChecksum) {
                $deliveryAddress = $address;
                break;
            }
        }

        if ($saveAddress || !Validate::isLoadedObject($deliveryAddress)) {
            if (!$deliveryAddress->save()) {
                return false;
            }
        }

        $order->id_address_delivery = $deliveryAddress->id;
        $order->id_address_invoice = $deliveryAddress->id;

        $this->context->cart->id_address_delivery = 0;
        $this->context->cart->id_address_invoice = 0;

        return $this->context->cart->save() && $order->save();
    }

    /**
     * Check if module supports cart currency
     *
     * @param Cart $cart
     *
     * @return bool
     */
    public function checkCurrency(Cart $cart)
    {
        $currency = new Currency($cart->id_currency);
        $supportedCurrencies = $this->getParameter('supported_currencies');

        return in_array($currency->iso_code, $supportedCurrencies);
    }

    /**
     * Check if module is configured based on mode
     *
     * @return bool
     */
    public function isConfigured()
    {
        /** @var \Invertus\DibsEasy\Adapter\ConfigurationAdapter $configuration */
        $configuration = $this->get('dibs.adapter.configuration');
        $testingMode = (bool) $configuration->get('DIBS_TEST_MODE');
        $merchantId = $configuration->get('DIBS_MERCHANT_ID');

        switch ($testingMode) {
            case true:
                $secretKey = $configuration->get('DIBS_TEST_SECRET_KEY');
                $checkoutKey = $configuration->get('DIBS_TEST_CHECKOUT_KEY');
                break;
            case false:
                $secretKey = $configuration->get('DIBS_PROD_SECRET_KEY');
                $checkoutKey = $configuration->get('DIBS_PROD_CHECKOUT_KEY');
                break;
        }

        return !empty($merchantId) && !empty($secretKey) && !empty($checkoutKey);
    }

    /**
     * Check if PrestaShop version is >= 1.6
     *
     * @return bool
     */
    public function isPS16()
    {
        return version_compare(_PS_VERSION_, '1.6', '>=');
    }

    public function getExtraTemplateVars($idCart, $idCarrier, $idOrderState)
    {
        /** @var \Invertus\DibsEasy\Adapter\ConfigurationAdapter $configuration */
        $configuration = $this->get('dibs.adapter.configuration');
        /** @var \Invertus\DibsEasy\Repository\OrderPaymentRepository $orderPaymentRepository */
        $orderPaymentRepository = $this->get('dibs.repository.order_payment');
        $orderPayment = $orderPaymentRepository->findOrderPaymentByCartId($idCart);

        /** @var \Invertus\DibsEasy\Action\PaymentGetAction $getPaymentAction */
        $getPaymentAction = $this->get('dibs.action.payment_get');
        $payment = $getPaymentAction->getPayment($orderPayment->id_payment);

        $idLang = $this->context->language->id;
        $carrier = new Carrier($idCarrier);
        $orderState = new OrderState($idOrderState);

        $tplVars = array(
            'dibs_payment_id' => $orderPayment->id_payment,
            'dibs_delay' => $carrier->delay[$idLang],
            'dibs_contact_email' => $configuration->get('PS_SHOP_EMAIL'),
            'dibs_order_state' => $orderState->name[$idLang],
            'dibs_payment_type' => '',
            'dibs_masked_pan' => '',
        );

        if ($payment instanceof \Invertus\DibsEasy\Result\Payment) {
            $paymentDetail = $payment->getPaymentDetail();
            $tplVars['dibs_payment_type'] = $paymentDetail->getPaymentType();
            $tplVars['dibs_masked_pan'] = $paymentDetail->getCardDetails()->getMaskedPan();
        }

        $this->context->smarty->assign($tplVars);

        $params = array();

        $params['{dibs_html_block}'] = $this->context->smarty->fetch(
            $this->getLocalPath().'views/templates/hook/actionGetExtraMailTemplateVars.tpl'
        );

        $params['{dibs_txt_block}'] = $this->context->smarty->fetch(
            $this->getLocalPath().'views/templates/hook/actionGetExtraMailTemplateVars.txt'
        );

        return $params;
    }

    /**
     * Build module service container
     */
    private function compile()
    {
        $this->container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $this->container->set('dibs.module', $this);

        $locator = new \Symfony\Component\Config\FileLocator($this->getLocalPath().'config');
        $loader  = new \Symfony\Component\DependencyInjection\Loader\YamlFileLoader($this->container, $locator);
        $loader->load('config.yml');

        $this->container->compile();
    }

    /**
     * Require autoloader
     */
    private function autoload()
    {
        require_once $this->getLocalPath().'vendor/autoload.php';
    }

    /**
     * Placing order
     */
    public function placeOrder($idCart) {
        $cart = new Cart($idCart);

        // Get payment which is associated with cart
        // It's simple mapping (id_cart - id_order - id_payment (dibs) - id_charge (dibs) - etc.)
        /** @var \Invertus\DibsEasy\Repository\OrderPaymentRepository $orderPaymentRepository */
        $orderPaymentRepository = $this->get('dibs.repository.order_payment');
        $orderPayment = $orderPaymentRepository->findOrderPaymentByCartId($idCart);

        if (!$orderPayment instanceof DibsOrderPayment) {
            $this->cancelCartPayment($idCart);
            throw new Exception( $this->l('Unexpected error occured.', self::FILENAME) );
        }

        // Before creating order let's make some validations
        // First let's check if paid amount and currency is the same as it is in cart
        $payment = $this->validateCartPayment($orderPayment->id_payment);

        if (false === $payment) {
            $this->cancelCartPayment($idCart);
            throw new Exception($this->l('Payment validation has failed. Payment was canceled.', self::FILENAME));
        }

        // Update payment mapping to be reserved
        $orderPayment->is_reserved = 1;
        $orderPayment->update();

        // Then check if payment country is valid
        if (!$this->validatePaymentCountry($payment)) {
            $this->cancelCartPayment($idCart);
            throw new Exception( $this>l('Payment was canceled due to invalid country.', self::FILENAME));
        }

       // If validations passed, let do some processing before creating order
        // First assign customer to cart if it does not exist

        if (!$customerId = $this->processSaveCartCustomer($payment)) {
            $this->cancelCartPayment($idCart);
            throw new Exception(implode('. ', $this->errors));
        }

        $customer = new Customer($customerId);
        // After processing is done, let's create order
        try {
            $idOrderState = (int) Configuration::get('DIBS_ACCEPTED_ORDER_STATE_ID');

            $extraTplVars = array();
            if (!$this->isPS16()) {
                $extraTplVars =
                    $this->getExtraTemplateVars($idCart, $cart->id_carrier, $idOrderState);
            }

            $this->validateOrder(
                $idCart,
                $idOrderState,
                $cart->getOrderTotal(),
                $this->displayName,
                null,
                $extraTplVars,
                $this->context->currency->id,
                false,
                $customer->secure_key
            );

        } catch (Exception $e) {
            /** @var \Invertus\DibsEasy\Action\PaymentCancelAction $paymentCancelAction */
            $paymentCancelAction = $this->get('dibs.action.payment_cancel');
            $paymentCancelAction->cancelCartPayment($cart);
            throw new Exception($this->l('Payment was canceled due to order creation failure.', self::FILENAME));
        }

        $idOrder = Order::getOrderByCartId($cart->id);
        $order = new Order($idOrder);

        // Update payment mappings
        $orderPayment->is_reserved = 1;
        $orderPayment->id_order = $order->id;
        $orderPayment->save();

        return $order;
    }

    /**
     * Validate if cart payment has been reserved.
     *
     * @param string $paymentId
     *
     * @return bool|\Invertus\DibsEasy\Result\Payment
     */
    protected function validateCartPayment($paymentId)
    {
        /** @var \Invertus\DibsEasy\Action\PaymentGetAction $paymentGetAction */
        $paymentGetAction = $this->get('dibs.action.payment_get');
        $payment = $paymentGetAction->getPayment($paymentId);

        if (null == $payment) {
            return false;
        }

        $cartId = $payment->getOrderDetail()->getReference();

        $cart = new Cart($cartId);

        $cartCurrency = new Currency($cart->id_currency);
        $cartAmount = (int) (string) ($cart->getOrderTotal() * 100);

        $summary = $payment->getSummary();
        $orderDetail = $payment->getOrderDetail();

        // check if payment was reserved or charged
        if ($summary->getReservedAmount() != $cartAmount &&
            $summary->getChargedAmount() != $cartAmount ||
            $orderDetail->getCurrency() != $cartCurrency->iso_code
        ) {
            return false;
        }

        return $payment;
    }

    /**
     * Validate if payment country is valid
     *
     * @param \Invertus\DibsEasy\Result\Payment $payment
     *
     * @return bool
     */
    protected function validatePaymentCountry(\Invertus\DibsEasy\Result\Payment $payment)
    {
        $country = $payment->getConsumer()
            ->getShippingAddress()
            ->getCountry();

        $alpha2IsoCode = $this->getAlpha2FromAlpha3CountryIso($country);

        return null !== $alpha2IsoCode;
    }

    /**
     * Get coutnry ISO Alpha2 from Country ISO Alpha3
     *
     * @param string $alpha3Iso
     *
     * @return null|string
     */
    protected function getAlpha2FromAlpha3CountryIso($alpha3Iso)
    {
        /** @var \Invertus\DibsEasy\Service\CountryMapper $countryMapper */
        $countryMapper = $this->get('dibs.service.country_mapper');
        $mappings = $countryMapper->mappings();

        $alpha2Iso = null;

        if (isset($mappings[$alpha3Iso])) {
            $alpha2Iso = $mappings[$alpha3Iso];
        }

        return $alpha2Iso;
    }

    /**
     * @param \Invertus\DibsEasy\Result\Payment $payment
     *
     * @return bool
     */
    protected function processSaveCartCustomer(\Invertus\DibsEasy\Result\Payment $payment)
    {
        $cartId = $payment->getOrderDetail()->getReference();
        $cart = new Cart($cartId);

        // customer has already been assigned to cart
        if($cart->id_customer) {
            return $cart->id_customer;
        } else { // customer is not assigned it is new customer or non logged in customer

            // trying to find the customer if it is already exists

            $person = $payment->getConsumer()->getPrivatePerson();

            $company = $payment->getConsumer()->getCompany();
            $firstName = $person->getFirstName() ?: $company->getFirstName();
            $lastName = $person->getLastName() ?: $company->getLastName();
            $email = $person->getEmail() ?: $company->getEmail();

            $idCustomer = Customer::customerExists($email, true, false);

            // if customer exists assign the cart to that customer
            if($idCustomer) {
                $customer = new Customer($idCustomer);

                // login this customer to show proper success page
                $this->processLogin($customer);

                $cart->id_customer = $customer->id;
                $cart->secure_key = $customer->secure_key;

                if (!$cart->save()) {
                    $this->errors[] = $this->module->l(
                        'Payment was canceled, because customer account could not be saved.',
                        self::FILENAME
                    );

                    return false;
                } else {
                    return $idCustomer;
                }
            }

            // if we couldn't find the customer lets create a new customer
            if (!$idCustomer) {
                $newPassword = Tools::passwdGen();
                $customer = new Customer();
                $customer->firstname = $firstName;
                $customer->lastname = $lastName;
                $customer->email = $email;
                $customer->passwd = Tools::encrypt($newPassword);
                $customer->is_guest = 1;
                $customer->id_default_group = Configuration::get('PS_CUSTOMER_GROUP', null, $this->context->cart->id_shop);
                $customer->newsletter = 0;
                $customer->optin = 0;
                $customer->active = 1;
                $customer->id_gender = 9;

                if ($errors = $customer->validateController()) {
                    $this->errors = array_merge($this->errors, $errors);
                    return false;
                }

                $customer->save();

                $this->sendConfirmationEmail($customer, $newPassword);

                $this->processLogin($customer);

                $cart->id_customer = $customer->id;
                $cart->secure_key = $customer->secure_key;

                if (!$cart->save()) {
                    $this->errors[] = $this->module->l(
                        'Payment was canceled, because customer account could not be saved.',
                        self::FILENAME
                    );

                    return false;
                }
            }
        }

        return $customer->id;
    }

    /**
     * Send welcome email if new customer is created
     *
     * @param Customer $customer
     * @param string $password
     *
     * @return bool|int
     */
    private function sendConfirmationEmail(Customer $customer, $password)
    {
        if (!Configuration::get('PS_CUSTOMER_CREATION_EMAIL')) {
            return true;
        }

        return Mail::Send(
            $this->context->language->id,
            'account',
            Mail::l('Welcome!'),
            array(
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{email}' => $customer->email,
                '{passwd}' => $password
            ),
            $customer->email,
            $customer->firstname.' '.$customer->lastname
        );
    }

    /**
     * Process customer login
     *
     * @param Customer $customer
     */
    public function processLogin(Customer $customer)
    {
        $this->context->cookie->id_compare = isset($this->context->cookie->id_compare) ?
            $this->context->cookie->id_compare :
            CompareProduct::getIdCompareByIdCustomer($customer->id);
        $this->context->cookie->id_customer = (int)($customer->id);
        $this->context->cookie->customer_lastname = $customer->lastname;
        $this->context->cookie->customer_firstname = $customer->firstname;
        $this->context->cookie->logged = 1;
        $customer->logged = 1;
        $this->context->cookie->is_guest = $customer->isGuest();
        $this->context->cookie->passwd = $customer->passwd;
        $this->context->cookie->email = $customer->email;

        // Add customer to the context
        $this->context->customer = $customer;

        $this->context->cookie->write();

        Hook::exec('actionAuthentication', array('customer' => $this->context->customer));

        // Login information have changed, so we check if the cart rules still apply
        CartRule::autoRemoveFromCart($this->context);
        CartRule::autoAddToCart($this->context);
    }

    /**
     * Cancel any payment that has been reserved
     *
     * @return bool
     */
    protected function cancelCartPayment($cartId)
    {
        $cart = new Cart($cartId);

        if (!Validate::isLoadedObject($cartId)) {
            return true;
        }

        /** @var \Invertus\DibsEasy\Action\PaymentCancelAction $paymentCancelAction */
        $paymentCancelAction = $this->module->get('dibs.action.payment_cancel');

        return $paymentCancelAction->cancelCartPayment($cart);
    }
}
