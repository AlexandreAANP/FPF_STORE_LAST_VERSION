<?php

namespace App\Controller\Checkout;

use App\Controller\Customer\CustomerController;
use App\Controller\Forms\FormsController;
use App\Controller\SiteCacheController;
use App\Controller\Product\ProductController;
use App\Functions\Currency;
use App\Functions\MoneyParser;
use App\Functions\Validation;
use App\Service\Geo\GeoCountryService;
use App\Service\Geo\GeoPtDistrictService;
use App\Service\Payment\PaypalService;
use App\Service\Payment\StripeService;
use App\Service\SettingsService;
use App\Service\Payment\VivaWalletService;
use App\Template\Layout;
use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

class CheckoutController extends SiteCacheController
{
    public $apiUrl = '';
    public $siteUrl = '';

    public $params = null;
    public $request = null;
    public $requestStack = null;
    public $session = null;
    public $dispenserId = null;
    public $paymentEnviroment = null;

    public function __construct(EntityManagerInterface $em, ContainerBagInterface $params, RequestStack $requestStack, SessionInterface $session, SettingsService $objSettingsService)
    {
        parent::__construct($em, $params, $requestStack, $session, $objSettingsService);

        $this->params = $params;
        $this->requestStack = $requestStack;
        $this->request = $requestStack->getCurrentRequest();
        $this->session = $session;

        $this->apiUrl = $requestStack->getCurrentRequest()->server->get('API_URL');
        $this->siteUrl = $this->requestStack->getCurrentRequest()->server->get('SITE_URL');
        $this->dispenserId = $requestStack->getCurrentRequest()->server->get('CUSTOMER_ID_DISPENSER');

        $this->paymentEnviroment = $requestStack->getCurrentRequest()->server->get('APP_PAYMENT_ENV');

    }

    /**
     * @Route("/checkout-test", name="frontoffice_checkout_test", methods={"GET","POST"})
     */
    public function test(Request $request)
    {
        $customerId = $this->session->get('customerId');
        if (!$customerId) {
            return $this->redirectToRoute('frontoffice_checkout_auth');
        }

        dd($_POST);
        die;

        $arData = [
            'product'           => $arProduct,
            'customerId'        => $customerId,
            'customerAddressId' => $customerAddressId,
            'paymentMethod'     => $paymentMethod,
            'deliveryMethod'    => $deliveryMethod,
            'domainName'        => 'frontoffice.test',
            'orderStatusReferenceKey' => 'order-status-awaiting-payment',
        ];

        $data = setAPIData('https://{host}/api/addOrder',$arData);
        $objData = json_decode($data);
        echo $objData->orderInfoId;

    }

    /**
     * @Route("/checkout", name="frontoffice_checkout_index", methods={"GET","POST"})
     */
    public function index(Request $request)
    {
        $customerId = $this->session->get('customerId');
        if (!$customerId) {
            return $this->redirectToRoute('frontoffice_checkout_auth');
        }

        $customerName = $this->session->get('customerName');

        $objProduct = new ProductController($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);
        $return = 'array';
        $arCart = $objProduct->getCart($request, $return);

        if ($arCart['return'] === "error") {
            echo 'Error at productCart()';
            exit();
        }

        $enableAddressOnCheckout = false;
        $colCustomerAddress = [];
        if ($data = $this->getAPIData($this->apiUrl . '/api/getOrderCustomerAddress/' . $customerId)) {
            if ($objCustomerAddress = json_decode($data, JSON_UNESCAPED_UNICODE)) {
                if (array_key_exists('colCustomerAddress', $objCustomerAddress)) {
                    $colCustomerAddress = $objCustomerAddress['colCustomerAddress'];
                }

                if (array_key_exists('enableAddressOnCheckout', $objCustomerAddress)) {
                    $enableAddressOnCheckout = $objCustomerAddress['enableAddressOnCheckout'];
                }
            }
        }

        $objGeoCountryService = new GeoCountryService();
        $colGeoCountry = $objGeoCountryService->listAll();

        $objGeoPtDistrictService = new GeoPtDistrictService();
        $colGeoPtDistrict = $objGeoPtDistrictService->listAll();

        $colGeoPtCouncil = [];
        if ($data = $this->getAPIData($this->apiUrl . '/api/getGeoPtCouncil')) {
            $objData = json_decode($data, JSON_UNESCAPED_UNICODE);
            if (array_key_exists('colGeoPtCouncil', $objData)) {
                $colGeoPtCouncil = $objData['colGeoPtCouncil'];
            }
        }

        $enableInvoiceOnCheckout = false;
        $colCustomerInvoice = [];
        $url = $this->apiUrl . '/api/getOrderCustomerInvoice/' . $customerId;
        if ($data = $this->getAPIData($url)) {
            $objCustomerInvoice = json_decode($data, JSON_UNESCAPED_UNICODE);
            if (array_key_exists('colCustomerInvoice', $objCustomerInvoice)) {
                $colCustomerInvoice = $objCustomerInvoice['colCustomerInvoice'];
            }

            if (array_key_exists('enableInvoiceOnCheckout', $objCustomerInvoice)) {
                $enableInvoiceOnCheckout = $objCustomerInvoice['enableInvoiceOnCheckout'];
            }
        }

        $listPaymentGateway = $this->getAPIData($this->apiUrl . '/api/getPaymentGateway');
        if ($listPaymentGateway) {
            $listPaymentGateway = json_decode($listPaymentGateway, JSON_UNESCAPED_UNICODE);
        }

        $arPaymentGatewayRoute = [
            'payment_gateway_none'                  => '/checkout/none',
            'payment_gateway_check_availability'    => '/checkout/check-availability',
            'payment_gateway_stripe'                => '/checkout/stripe',
            'payment_gateway_amazon_pay'            => '/checkout/amazon',
            'payment_gateway_apple_pay'             => '/checkout/apple',
            'payment_gateway_paypal'                => '/checkout/paypal',
            'payment_gateway_multibanco'            => '/checkout/multibanco',
            'payment_gateway_viva_wallet'           => '/checkout/viva-wallet'
        ];

        $arPaymentGateway = [];
        foreach ($listPaymentGateway['colPaymentGateways'] as $key => $val) {
            $name = $val['name'];
            $referenceKey = $val['referenceKey'];

            $icon = substr($referenceKey, 16);
            $icon = str_replace('_', '-', $icon);

            if (array_key_exists($referenceKey, $arPaymentGatewayRoute)) {
                $link = $arPaymentGatewayRoute[$referenceKey];
                if ($name == "") {
                    $name = 'OTHER';
                }
                if ($icon == 'none') {
                    $icon = 'money';
                }
                $arPaymentGateway[$key] = [
                    'referenceKey' => $referenceKey,
                    'name' => $name,
                    'icon' => 'fa-' . $icon,
                    'link' => $link,
                ];
            }
        }

        $this->setCacheSaveBlock(); // Do not save cache for Checkout

        $colCustomerAddress = $this->parseToCommonCase($colCustomerAddress);
        $colCustomerInvoice = $this->parseToCommonCase($colCustomerInvoice);

        if (array_key_exists('listProduct', $arCart)) {
            $objForms = new FormsController();
            foreach ($arCart['listProduct'] AS $key => $listProduct) {
                $colProductAdditionalFields = [];
                if (array_key_exists('productAdditionalFields', $listProduct)) {
                    $colProductAdditionalFields = $objForms->getFields($listProduct['productAdditionalFields'], [
                        'colProductCategory' => $listProduct['colProductCategory']
                    ]);
                }
                $arCart['listProduct'][$key]['productAdditionalFields'] = $colProductAdditionalFields;
            }
        }

        return $this->renderSite('checkout/index.html.twig', [
            'product'                 => $arCart,
            'customerName'            => $customerName,
            'enableAddressOnCheckout' => $enableAddressOnCheckout,
            'enableInvoiceOnCheckout' => $enableInvoiceOnCheckout,
            'customerAddress'         => $colCustomerAddress,
            'customerInvoice'         => $colCustomerInvoice,
            'colGeoCountry'           => $colGeoCountry,
            'colGeoPtDistrict'        => $colGeoPtDistrict ?? [],
            'colGeoPtCouncil'         => $colGeoPtCouncil,
            'listPaymentGateway'      => $arPaymentGateway,
        ]);
    }

    function changeUnderscoreToUpperCase($key) {
        $arLetter = explode('_', $key);

        $newKey = '';
        foreach ($arLetter AS $i => $val) {
            if ($i === 0) {
                $newKey .= strtolower($val);
            } else {
                $newKey .= ucfirst($val);
            }
        }

        return $newKey;
    }

    function parseToCommonCase($ar) {
        $newAr = [];

        foreach ($ar AS $key => $val) {
            if (is_array($val)) {
                $newAr[$key] = $this->parseToCommonCase($val);
            } else {
                if ($key === 'name_to_invoice') { // TODO: Trocar esse nome na tabela para não precisar fazer essa verificação aqui
                    $key = 'name';
                } else if (is_integer(strpos($key, '_'))) {
                    $key = $this->changeUnderscoreToUpperCase($key);
                }
                $newAr[$key] = $val;
            }
        }

        return $newAr;
    }

    /**
     * @Route("/checkout/auth", name="frontoffice_checkout_auth", methods={"GET"})
     */
    public function auth(Request $request)
    {

        $objProduct = new ProductController($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);
        $return = 'array';
        $arCart = $objProduct->getCart($request, $return);

        if ($arCart['return'] === "error") {
            echo 'Error at productCart()';
            exit();
        }

        $this->setCacheSaveBlock(); // Do not save cache for Checkout

        $productItemsInCart = ($this->session->get('product') && $this->session->get('product') != "") ? count($this->session->get('product')) : 0;

        return $this->renderSite('checkout/auth.html.twig', [
            'product' => $arCart,
            'productItemsInCart' => $productItemsInCart,
        ]);
    }

    /**
     * @Route("/checkout/success", name="frontoffice_checkout_success", methods={"GET"})
     */
    public function checkoutSuccess(Request $request)
    {
        self::cartClear();
        return $this->renderSite('checkout/success.html.twig', []);
    }

    /**
     * @Route("/checkout/error/{orderInfoId?}", name="frontoffice_checkout_error", methods={"GET"})
     */
    public function checkoutError(Request $request, $orderInfoId)
    {
        return $this->renderSite('checkout/error.html.twig', [
            'orderInfoId' => $orderInfoId
        ]);
    }

    function updateOrder($request, $orderInfoId, $orderStatusReferenceKey, $paymentLog = "")
    {
        $arData = [
            'orderInfoId' => $orderInfoId,
            'paymentLog' => json_encode($paymentLog),
            'orderStatusReferenceKey' => $orderStatusReferenceKey
        ];

        if ($data = $this->setAPIData($this->apiUrl . '/api/updateOrder', $arData)) {
            $arData = json_decode($data, JSON_UNESCAPED_UNICODE);
            if ($arData && array_key_exists('return', $arData) && $arData['return'] == 'success') {
                $this->cartClear();
                return $arData['orderInfoId'];
            }
        }

        return false;
    }

    function addOrder($request, $customerAddressId, $customerInvoiceId, $paymentMethod, $deliveryMethodId, $orderStatusReferenceKey, $options = [])
    {
        $defaultLanguage = $request->getLocale();

        $customerId = $this->session->get('customerId');
        $dispenserId = $_ENV['CUSTOMER_ID_DISPENSER'];

        if (!$customerId) {
            return $this->redirectToRoute('frontoffice_checkout_auth');
        }

        $paymentType = 'money';
        if (array_key_exists('paymentType', $options)) {
            $paymentType = $options['paymentType'];
        }

        $objProduct = new ProductController($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);
        $return = 'array';
        $arCart = $objProduct->getCart($request, $return);

        if ($arCart['return'] === "error") {
            echo 'Error at productCart()';
            exit();
        }

        if ($arCart['productQuantity'] === null) {
            echo 'Cart is empty!';
            exit();
        }

        /*
        Ao adicionar o produto no carrinho, criamos uma sessao product_item_stock, assim como temos product_id e quantity.
        product_item_stock_id é o ID da tabela product_item_stock
        Na tabela product_item_stock o stock e o preço do item
        */
        $arProduct = [];
        $line = 0;

        foreach ($arCart['productQuantity'] as $productItemStockId => $val) {
            $arProduct[$line] = [
                'productId' => $arCart['product'][$productItemStockId],
                'productItemStockId' => $productItemStockId,
                'price' => $arCart['productPrice'][$productItemStockId],
                'quantity' => $val,
                'calendar' => ($arCart['productCalendar'] ?? null) ? $arCart['productCalendar'][$productItemStockId] : ''
            ];
            $line++;
        }

        $points = 0;
        $orderTotalPrice = $arCart['totalPrice'];
        if($paymentType == 'points') {
            $points = $arCart['totalPoints'];
            $orderTotalPrice = 0;
        } else if($paymentType == 'money_and_points')  {
            $points = $arCart['totalPointsByPointsPercentage'];
            $orderTotalPrice = $arCart['totalPriceByPointsPercentage'];
        }

        $arData = [
            'orderDeliveryPrice' => $this->session->get('order_delivery_method_price'),
            'paymentType' => $paymentType,
            'points' => $points,
            'orderTotalPrice' => $orderTotalPrice,
            'colInvoice' => [
                [
                    'colProduct' => $arProduct,
                    'customerAddressId' => $customerAddressId,
                    'customerInvoiceId' => $customerInvoiceId,
                    'paymentMethod' => $paymentMethod
                ]
            ],
            'customerId' => $customerId,
            'dispenserId' => $dispenserId,
            'deliveryMethodId' => $deliveryMethodId,
            'orderStatusReferenceKey' => $orderStatusReferenceKey,
            'domainName' => $_ENV['DOMAIN_NAME'],
            'language' => $defaultLanguage
        ];

        if ($data = $this->setAPIData($this->apiUrl . '/api/addOrder', $arData)) {
            $objData = json_decode($data, JSON_UNESCAPED_UNICODE);

            if ($objData && array_key_exists('return', $objData) && $objData['return'] == 'success') {
                if ($paymentMethod == 'none'){
                    $this->cartClear();
                }
                return $objData['orderInfoId'];
            }
        }

        return false;
    }

    public function cartClear(){
        $this->session->remove('product');
        $this->session->remove('product_quantity');
        $this->session->remove('product_calendar');
        $this->session->remove('product_item_stock');
        $this->session->remove('product_max_quantity');
        $this->session->remove('product_price');
        $this->session->remove('order_delivery_method_price');
    }

    /**
     * @Route("/checkout/none", name="frontoffice_checkout_none", methods={"POST"})
     */
    public function checkoutNone(Request $request)
    {
        $paymentType = $request->request->get('payment_type');

        $customerId = $this->session->get('customerId');
        if (!$customerId) {
            return $this->redirectToRoute('frontoffice_checkout_auth');
        }

        $customerAddressId = $request->request->get('customer_address_id') ?? 0;

        $customerInvoiceId = $request->request->get('customer_invoice_id') ?? 0;

        $paymentMethod = 'none';
        $deliveryMethodReferenceKey = '';//'delivery-method-ctt';
        $orderStatusReferenceKey = 'order-status-completed';
        $options = ['paymentType' => $paymentType];
        $orderInfoId = $this->addOrder($request, $customerAddressId, $customerInvoiceId, $paymentMethod, $deliveryMethodReferenceKey, $orderStatusReferenceKey, $options);

        if ($orderInfoId) {
            return $this->redirect('/checkout/none/complete/' . $orderInfoId);
        }

        return $this->redirect('/checkout/error');
    }

    /**
     * @Route("/checkout/check-availability", name="frontoffice_checkout_check_availability", methods={"POST"})
     */
    public function checkoutCheckAvailability(Request $request)
    {
        $paymentType = $request->request->get('payment_type');

        $customerId = $this->session->get('customerId');
        if (!$customerId) {
            return $this->redirectToRoute('frontoffice_checkout_auth');
        }

        $customerAddress = $request->request->get('customer_address');
        if (is_numeric($customerAddress)) {
            $customerAddressId = $customerAddress;

        } else if ($customerAddress === 'new') {
            $objCustomerController = new CustomerController($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);

            $line1 = $request->request->get('new_address_line1');
            $line2 = $request->request->get('new_address_line2');
            $city = $request->request->get('new_address_city');
            $state = $request->request->get('new_address_state');
            $country = $request->request->get('new_address_country');
            $postalCode = $request->request->get('new_address_postal_code');
            $newAddressSave = $request->request->get('new_address_save');

            $dontSave = 1;
            if ($newAddressSave === 'on') {
                $dontSave = 0;
            }

            $arData = [
                'customerId' => $customerId,
                'line1' => $line1,
                'line2' => $line2,
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'postalCode' => $postalCode,
                'dontSave' => $dontSave,
            ];
            $addCustomerAddress = $objCustomerController->addCustomerAddress($arData);
            $customerAddressId = $addCustomerAddress['id'];

        } else { // Entra aqui quando o enableAddress não está ligado, passa e não insere order_address para esse pedido
            $customerAddressId = 0;
        }

        $customerInvoiceId = $request->request->get('customer_invoice_id');

        $paymentMethod = 'check-availability';
        $deliveryMethodReferenceKey = '';//'delivery-method-ctt';
        $orderStatusReferenceKey = 'order-status-check-availability';
        $options = ['paymentType' => $paymentType];
        $orderInfoId = $this->addOrder($request, $customerAddressId, $customerInvoiceId, $paymentMethod, $deliveryMethodReferenceKey, $orderStatusReferenceKey, $options);

        if ($orderInfoId) {
            return $this->redirect('/checkout/complete/' . $orderInfoId);
        }

        return $this->redirect('/checkout/error');
    }

    /**
     * @Route("/checkout/delivery-methods", name="frontoffice_checkout_delivery-methods", methods={"POST"})
     */
    public function checkoutDeliveryMethods(Request $request)
    {
        $deliveryMethodId = $request->request->get('deliveryMethodId');
        $postalCode = $request->request->get('postalCode');

        if ($countryId = $request->request->get('countryId')) {
            $objGeoCountryService = new GeoCountryService();
            if ($countryCode = $objGeoCountryService->getById($countryId)) {
                $countryCode = $countryCode['iso2'];
            }
        }

        $objProduct = new ProductController($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);
        $return = 'array';
        $colCartAll = $objProduct->getCart($request, $return);
        $colCart = $colCartAll['listProductItemStock'];

        $arData = [
            'colProduct'        => $colCart,
            'deliveryMethodId'  => $deliveryMethodId,
            'postalCodes'        => $postalCode,
            'countryCode'       => $countryCode ?? null,
        ];

        $colDeliveryMethods = [];
        if ($data = $this->setAPIData($this->apiUrl . '/api/order/delivery-methods/price', $arData)) {
            if ($objData = json_decode($data, JSON_UNESCAPED_UNICODE)) {
                $colDeliveryMethods = $objData;
            }
        }

        $this->session->set('order_delivery_method_price', ($colDeliveryMethods['orderDeliveryPrice'] ?? 0));
        return $this->json($colDeliveryMethods);
    }

    /**
     * @Route("/checkout/none/complete/{orderInfoId}", name="frontoffice_checkout_none_complete", methods={"GET"})
     */
    public function checkoutNoneComplete(Request $request, $orderInfoId)
    {
        $orderStatusReferenceKey = "order-status-completed";
        if (is_numeric($orderInfoId) && intval($orderInfoId) > 0) {
            if ($this->updateOrder($request, $orderInfoId, $orderStatusReferenceKey)) {
                if ($orderInfoId) {
                    return $this->redirect('/checkout/complete/' . $orderInfoId);
                }
            }
            return $this->redirect('/checkout/error');
        } else {
            return $this->redirect('/checkout/error');
        }
    }

    /**
     * @Route("/checkout/stripe/complete", name="frontoffice_checkout_stripe_complete", methods={"GET"})
     */
    public function checkoutStripeComplete(Request $request, StripeService $stripeService)
    {
        $orderInfoId = $request->get('orderInfoId');
        $stripeCheckoutSessionId = $request->get('stripeCheckoutSessionId');
        $stripeSessionId = $this->session->get('stripe_session_id');

        if($stripeCheckoutSessionId != $stripeSessionId){
            return $this->redirect('/checkout/error');
        }

        $orderStatusReferenceKey = 'order-status-completed';
        if (is_numeric($orderInfoId) && intval($orderInfoId) > 0) {
            $paymentLog = '';
            if ($stripeCheckoutSessionId) {
                $stripeSecretKey =  $this->session->get('stripe_secret_key');
                $paymentLog = $stripeService->retrievePaymentItent($stripeSecretKey, $stripeCheckoutSessionId);

            }
            if ($this->updateOrder($request, $orderInfoId, $orderStatusReferenceKey, $paymentLog)) {
                if ($orderInfoId) {
                    return $this->redirect('/checkout/complete/' . $orderInfoId);
                }
            }
            return $this->redirect('/checkout/error');
        } else {
            return $this->redirect('/checkout/error');
        }
    }

    /**
     * @Route("/checkout/complete/{orderInfoId}", name="frontoffice_checkout_complete", methods={"GET"})
     */
    public function checkoutComplete(Request $request, $orderInfoId)
    {
        $customerId = $this->session->get('customerId');

        return $this->renderSite('checkout/complete.html.twig', [
            'customerId'  => $customerId,
            'orderInfoId' => $orderInfoId
        ]);
    }

    /**
     * @Route("/checkout/stripe/error", name="frontoffice_checkout_stripe_error", methods={"GET"})
     */
    public function checkoutStripeError(Request $request)
    {
        $orderInfoId = $request->get('orderInfoId');

        if (is_numeric($orderInfoId) && intval($orderInfoId) > 0) {
            $orderStatusReferenceKey = "order-status-cancelled";
            if ($orderInfoId = $this->updateOrder($request, $orderInfoId, $orderStatusReferenceKey)) {
                return $this->redirect('/checkout/error/' . $orderInfoId);
            }
            return $this->redirect('/checkout/error');
        } else if ($orderInfoId === 'error') {
            return $this->redirect('/checkout/error');
        }
        return $this->redirect('/checkout/error');
    }

    /**
     * @Route("/checkout/stripe", name="frontoffice_checkout_stripe", methods={"GET", "POST"})
     */
    public function checkoutStripe(Request $request, RequestStack $requestStack, MoneyParser $moneyParser, StripeService $stripe)
    {
        $strPaymentCredentialsIds = 'stripe_publishable_key, stripe_secret_key';
        $paymentMethod = 'stripe';
        $checkoutCartData = $this->getCheckoutCartData($paymentMethod, $strPaymentCredentialsIds, $request, $moneyParser);
        $colProducts = $checkoutCartData['colProducts'];
        $colDiscount = $checkoutCartData['colDiscount'];
        $orderInfoId = $checkoutCartData['orderInfoId'];
        $orderDeliveryMethodPrice = $checkoutCartData['orderDeliveryMethodPrice'];
        $stripeApiKey = $checkoutCartData['arPaymentCredentials']['stripe_secret_key'];
        $stripePublishableKey = $checkoutCartData['arPaymentCredentials']['stripe_publishable_key'];

        $lineItems = [];

        $objLayout = new Layout($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);
        $currencyCode = $objLayout->getCurrencyCode();

        if($orderDeliveryMethodPrice) {
            $price = strval($orderDeliveryMethodPrice);
            $moneyObj = $moneyParser->parse($price, $currencyCode);
            $product = [
                'name' => 'Shipping',
                'quantity' => 1,
                'unit_amount' => $moneyObj->getAmount(),
                'currency' =>  $moneyObj->getCurrency()->getCode()
            ];
            $lineItems[] = $stripe->createLineItem($stripeApiKey, $product);
        }

        foreach ($colProducts as $itemKey => $itemValue) {
            $moneyObj = $moneyParser->parse($itemValue['price'], $currencyCode);

            $product = [
                'name' => $itemValue['name'],
                'quantity' => $itemValue['quantity'],
                'unit_amount' => $moneyObj->getAmount(),
                'currency' =>  $moneyObj->getCurrency()->getCode(),
            ];
            $lineItems[] = $stripe->createLineItem($stripeApiKey, $product);
        }

        $couponId = null;
        $currency = null;
        if(!empty($colDiscount)) {
            $couponName = '';
            $couponValue = null;
            $colDiscountChangedLength = count($colDiscount) - 1;
            foreach ($colDiscount as $itemKey => $itemValue) {
                $couponName = $couponName . $itemValue['name'];
                if($colDiscountChangedLength != $itemKey) {
                    $couponName .= ' |';
                }

                $moneyObj = $moneyParser->parse($itemValue['price'], $currencyCode);
                $couponValue = $couponValue + $moneyObj->getAmount();

                if(!$currency){
                    $currency = $moneyObj->getCurrency()->getCode();
                }

            }
            $coupon = [
                'name' => $couponName,
                'value' => $couponValue,
                'type' => 'amount_off',     //A coupon has either a percent_off or an amount_off and currency.
                'currency' => $currency,    //Three-letter ISO code for the currency of the amount_off parameter (required if amount_off is passed).
                'duration' => 'once',       //Specifies how long the discount will be in effect. Can be forever, once, or repeating.
            ];
            $couponId = $stripe->createCoupon($stripeApiKey, $coupon);
        }

        $paymentItentDataDescription = 'Order' . $orderInfoId;
        $stripeCustomizedUrls = [
            'success' => $this->siteUrl . '/checkout/stripe/complete?stripeCheckoutSessionId={CHECKOUT_SESSION_ID}&orderInfoId=' . $orderInfoId,
            'cancel' => $this->siteUrl . '/checkout/stripe/error?stripeCheckoutSessionId={CHECKOUT_SESSION_ID}&orderInfoId=' . $orderInfoId,
        ];

        $stripeSession = $stripe->createSession($stripeApiKey, $lineItems, $paymentItentDataDescription, $stripeCustomizedUrls, $couponId);
        $this->session->set('stripe_session_id', $stripeSession->id);
        $this->session->set('stripe_secret_key', $stripeApiKey);

        $this->setCacheSaveBlock(); // Do not save cache for Checkout

        return $this->renderSite('checkout/stripe-checkout.html.twig', [
            'stripePublishableKey' => $stripePublishableKey,
            'stripeCheckoutSessionId' => $stripeSession->id,
        ]);
    }

     /**
    * @Route("/checkout/multibanco", name="frontoffice_checkout_multibanco", methods={"GET","POST"})
    */
    public function checkoutMultibanco(Request $request, MoneyParser $moneyParser, ParameterBagInterface $parameterBagInterface, StripeService $stripe)
    {
        $strPaymentCredentialsIds = 'stripe_secret_key, stripe_email';
        $paymentMethod = 'stripe';
        $checkoutCartData = $this->getCheckoutCartData($paymentMethod, $strPaymentCredentialsIds, $request, $moneyParser);
        $colProducts = $checkoutCartData['colProducts'];
        $colDiscount = $checkoutCartData['colDiscount'];
        $orderInfoId = $checkoutCartData['orderInfoId'];
        $orderDeliveryMethodPrice = $checkoutCartData['orderDeliveryMethodPrice'];
        $stripeApiKey = $checkoutCartData['arPaymentCredentials']['stripe_secret_key'];
        $ownerStripeEmail = $checkoutCartData['arPaymentCredentials']['stripe_email'];

        $customerId = $this->session->get('customerId');
        if (!$customerId) {
            return $this->redirectToRoute('frontoffice_checkout_auth');
        }

        $totalDiscountPrice = 0;
        foreach ($colDiscount as $itemKey => $itemValue) {
            $totalDiscountPrice += $itemValue['price'];
        }

        $productsPriceTotal = null;
        foreach ($colProducts as $key => $value) {
            $productsPriceTotal += $value['price'];
        }

        $objLayout = new Layout($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);
        $currencyCode = $objLayout->getCurrencyCode();

        $cartTotalPrice = $productsPriceTotal + $orderDeliveryMethodPrice - $totalDiscountPrice;
        $moneyObj = $moneyParser->parse(strval($cartTotalPrice), $currencyCode);

        $arPaymentOrder = [
            'price' => $moneyObj->getAmount(),  // int value, 1 euro is 100,
            'currency' => $moneyObj->getCurrency()->getCode(),
            'owner'=> [
                'name' => $_ENV['DOMAIN_NAME'],
                'email' => $ownerStripeEmail,
            ],
            'returnUrl' => $this->apiUrl . '/api/order/multibanco-payment?orderInfoId=' . $orderInfoId,
        ];

        $stripeSource = $stripe->createSource($stripeApiKey, $arPaymentOrder);
        $stripeSourceId = $stripeSource->id;
        $entity = $stripeSource->multibanco->entity;
        $reference = $stripeSource->multibanco->reference;

        return $this->renderSite('checkout/complete.html.twig', [
            'customerId'  => $customerId,
            'orderInfoId' => $orderInfoId,
            'entity' => $entity,
            'reference' => $reference,
            'price' => $cartTotalPrice,
        ]);
        // return $this->renderSite('checkout/stripe-checkout-multibanco.html.twig', [
        //     'entity' => $entity,
        //     'reference' => $reference,
        //     'price' => $cartTotalPrice,
        // ]);

    }

    /**
    * @Route("/checkout/viva-wallet", name="frontoffice_checkout_viva_wallet", methods={"GET","POST"})
    */
    public function checkoutVivaWallet(Request $request, MoneyParser $moneyParser, ParameterBagInterface $parameterBagInterface)
    {
        $strPaymentCredentialsIds = 'viva_wallet_client_id, viva_wallet_client_secret, viva_wallet_merchant_id, viva_wallet_api_key';
        $paymentMethod = 'viva-wallet';
        $checkoutCartData = $this->getCheckoutCartData($paymentMethod, $strPaymentCredentialsIds, $request, $moneyParser);
        $colProducts = $checkoutCartData['colProducts'];
        $colDiscount = $checkoutCartData['colDiscount'];
        $orderInfoId = $checkoutCartData['orderInfoId'];
        $orderDeliveryMethodPrice = $checkoutCartData['orderDeliveryMethodPrice'];

        $totalDiscountPrice = 0;
        foreach ($colDiscount as $itemKey => $itemValue) {
            $totalDiscountPrice += $itemValue['price'];
        }

        $productsPriceTotal = null;
        foreach ($colProducts as $key => $value) {
            $productsPriceTotal += $value['price'];
        }

        $objLayout = new Layout($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);
        $currencyCode = $objLayout->getCurrencyCode();

        $cartTotalPrice = $productsPriceTotal + $orderDeliveryMethodPrice - $totalDiscountPrice;
        $moneyObj = $moneyParser->parse(strval($cartTotalPrice), $currencyCode);

        $arPaymentOrder = [
            //'Email' => 'client@email.com',
            //'Phone' => '+351963963963',
            //'FullName' => 'Client Name',
            //'PaymentTimeOut' => 86400, // Limit the payment period default is 300 (5min)
            //'RequestLang' => 'pt-PT', // The invoice lang that the client sees
            'MaxInstallments' => 0,
            //'AllowRecurring' => true,
            //'IsPreAuth' => false, // false captures the amount, true waits to be captured manually on wallet
            'Amount' => $moneyObj->getAmount(),  // int value, 1 euro is 100,
            'MerchantTrns' => 'Booking:' . $orderInfoId,
            'CustomerTrns' => 'Reserva #' . $orderInfoId
        ];

        $vivaWallet = new VivaWalletService($parameterBagInterface, $checkoutCartData['arPaymentCredentials']);

        return $this->render('checkout/viva_wallet_checkout.html.twig', [
            'redirect_url' => $vivaWallet->setPaymentOrder(json_encode($arPaymentOrder)),
            'payment_url' => $this->paymentEnviroment == 'prod' ? 'https://www.vivapayments.com' : 'https://demo-api.vivapayments.com',
        ]);

    }

    /**
     * @Route("/checkout/viva-wallet/error", name="frontoffice_checkout_viva_wallet_error", methods={"GET"})
     */
    public function checkoutVivaWalletError(Request $request, VivaWalletService $vivaWallet)
    {
        dd('Error');

        $objTransaction = $vivaWallet->getTransaction($request->query->get('t'));

        $orderInfoId = $request->get('orderInfoId');

        if (is_numeric($orderInfoId) && intval($orderInfoId) > 0) {
            $orderStatusReferenceKey = "order-status-cancelled";

            if ($orderInfoId = $this->updateOrder($request, $orderInfoId, $orderStatusReferenceKey, $objTransaction)) {
                return $this->redirect('/checkout/error/' . $orderInfoId);
            }
            return $this->redirect('/checkout/error');
        } else if ($orderInfoId === 'error') {
            return $this->redirect('/checkout/error');
        }

    }

    /**
     * @Route("/checkout/viva-wallet/complete", name="frontoffice_checkout_viva_wallet_complete", methods={"GET"})
     */
    public function checkoutVivaWalletComplete(Request $request, VivaWalletService $vivaWallet)
    {
        dd('complete');

        $objTransaction = $vivaWallet->getTransaction($request->query->get('t'));

        $orderInfoId = $request->get('orderInfoId');

        $orderStatusReferenceKey = "order-status-completed";
        if (is_numeric($orderInfoId) && intval($orderInfoId) > 0) {
            if ($this->updateOrder($request, $orderInfoId, $orderStatusReferenceKey, $objTransaction)) {
                if ($orderInfoId) {
                    return $this->redirect('/checkout/complete/' . $orderInfoId);
                }
            }
            return $this->redirect('/checkout/error');
        } else {
            return $this->redirect('/checkout/error');
        }
    }

    /**
     * @Route("/checkout/amazon", name="frontoffice_checkout_amazon", methods={"GET"})
     */
    public function checkoutAmazon(Request $request)
    {
        echo 'Not yet implemented';
        exit();
    }

    /**
     * @Route("/checkout/apple", name="frontoffice_checkout_apple", methods={"GET"})
     */
    public function checkoutApple(Request $request)
    {
        echo 'Not yet implemented';
        exit();
    }

    /**
     * @Route("/checkout/paypal", name="frontoffice_checkout_paypal", methods={"GET", "POST"})
     */
    public function checkoutPaypal(Request $request, MoneyParser $moneyParser, PaypalService $paypalService)
    {
        $strPaymentCredentialsIds = 'paypal_client_id, paypal_client_secret';
        $paymentMethod = 'paypal';
        $checkoutCartData = $this->getCheckoutCartData($paymentMethod, $strPaymentCredentialsIds, $request, $moneyParser);

        $colProducts = $checkoutCartData['colProducts'];
        $colDiscount = $checkoutCartData['colDiscount'];
        $orderInfoId = $checkoutCartData['orderInfoId'];
        $orderDeliveryMethodPrice = $checkoutCartData['orderDeliveryMethodPrice'];

        $paypalClientId = $checkoutCartData['arPaymentCredentials']['paypal_client_id'];
        $paypalClientSecret = $checkoutCartData['arPaymentCredentials']['paypal_client_secret'];

        $this->session->set('paypal_client_id', $paypalClientId );
        $this->session->set('paypal_client_secret', $paypalClientSecret);

        $productsTotalPrice = null;
        foreach ($colProducts as $itemKey => $itemValue) {
            $productsTotalPrice += $itemValue['price'] * $itemValue['quantity'];
            $currency = $itemValue['currency'];

            $items[] = [
                'name' => $itemValue['name'],
                'unit_amount' => [
                    'value' => $itemValue['price'],
                    'currency_code' => $itemValue['currency']
                ],
                'quantity' => $itemValue['quantity'],
                'sku' => $itemValue['referenceKey']
            ];
        }

        $totalDiscountPrice = 0;
        foreach ($colDiscount as $itemKey => $itemValue) {
            $totalDiscountPrice += $itemValue['price'];
        }

        $cartTotalPrice = $productsTotalPrice + $orderDeliveryMethodPrice - $totalDiscountPrice;
        $paypalData[] = [
            'amount' => [
                'value' => $cartTotalPrice,
                'currency_code' => $currency,
                'breakdown' => [
                    'item_total' => [
                        'value' => $productsTotalPrice,
                        'currency_code' => $currency,
                    ],
                    'shipping' => [
                        'value' => $orderDeliveryMethodPrice,
                        'currency_code' => $currency,
                    ],
                    'discount' => [
                        'value' => $totalDiscountPrice,
                        'currency_code' => $currency,
                    ]
                ]
            ],
            'items' => $items
        ];

        //echo json_encode($paypalData);die;
        // dd($paypalData);
        $customizedUrls = [
            'success' => $this->siteUrl . '/checkout/paypal/complete?orderInfoId=' . $orderInfoId,
            'cancel' => $this->siteUrl . '/checkout/paypal/error?orderInfoId=' . $orderInfoId,
        ];

        $paypalService->ordersCreateRequest($paypalClientId, $paypalClientSecret, $paypalData, $customizedUrls, $this->paymentEnviroment);

        return $this->render('checkout/paypal-checkout.html.twig', [
            'controller_name' => 'PaypalRedirectController',
        ]);
    }

    /**
     * @Route("/checkout/paypal/complete", name="frontoffice_checkout_paypal_complete", methods={"GET"})
     */
    public function checkoutPayPalComplete(Request $request, PaypalService $paypalService)
    {
        $orderInfoId = $request->get('orderInfoId');

        $token = $request->get('token');

        // $payerID = $request->get('PayerID');

        $orderStatusReferenceKey = 'order-status-completed';
        if (is_numeric($orderInfoId) && intval($orderInfoId) > 0) {
            // $paymentLog = $payerID;

            $paypalClientId = $this->session->get('paypal_client_id');
            $paypalClientSecret = $this->session->get('paypal_client_secret');

            $paymentLog = $paypalService->ordersCaptureRequest($paypalClientId, $paypalClientSecret, $this->paymentEnviroment, $token);

            if ($this->updateOrder($request, $orderInfoId, $orderStatusReferenceKey, $paymentLog)) {
                if ($orderInfoId) {
                    return $this->redirect('/checkout/complete/' . $orderInfoId);
                }
            }
            return $this->redirect('/checkout/error');
        } else {
            return $this->redirect('/checkout/error');
        }
    }

    /**
     * @Route("/checkout/paypal/error", name="frontoffice_checkout_paypal_error", methods={"GET"})
     */
    public function checkoutPaypalError(Request $request)
    {
        $orderInfoId = $request->get('orderInfoId');

        if (is_numeric($orderInfoId) && intval($orderInfoId) > 0) {
            $orderStatusReferenceKey = "order-status-cancelled";
            if ($orderInfoId = $this->updateOrder($request, $orderInfoId, $orderStatusReferenceKey)) {
                return $this->redirect('/checkout/error/' . $orderInfoId);
            }
            return $this->redirect('/checkout/error');
        } else if ($orderInfoId === 'error') {
            return $this->redirect('/checkout/error');
        }
    }

    public function getCheckoutCartData($paymentMethod, $strPaymentCredentialsIds, $request) {
        $objLayout = new Layout($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);

        $isFrontofficeCart = $request->request->get('is_frontoffice_cart');
        $paymentType = $request->request->get('payment_type');
        $deliveryMethodId = $request->request->get('delivery_method_id');


        $colDiscount = [];
        $arPaymentCredentials = [];

        $arData = ['paymentCredentialsIds' => $strPaymentCredentialsIds];
        if ($data = $this->setAPIData($this->apiUrl . '/api/dispenser/payment-gateway/' . $this->dispenserId, $arData)) {
            if ($objData = json_decode($data, JSON_UNESCAPED_UNICODE)) {
                $arPaymentCredentials = $objData;
            }
        }

        // O cart pode vir do frontoffice ou do backoffice
        if ($isFrontofficeCart == 'true') {
            $customerAddressId = $request->request->get('customer_address_id');
            if(!$customerAddressId) {
                $customerAddressId = 0;
            }
            $customerInvoiceId = $request->request->get('customer_invoice_id');
            if(!$customerInvoiceId) {
                $customerInvoiceId = 0;
            }

            $orderStatusReferenceKey = 'order-status-awaiting-payment';

            $options = ['paymentType' => $paymentType];
            $orderInfoId = $this->addOrder($request, $customerAddressId, $customerInvoiceId, $paymentMethod, $deliveryMethodId, $orderStatusReferenceKey, $options);

            $objProduct = new ProductController($this->em, $this->params, $this->requestStack, $this->session, $this->objSettingsService);
            $return = 'array';
            $colCartAll = $objProduct->getCart($request, $return);
            $colCart = $colCartAll['listProductItemStock'];

            if ($colCartAll['return'] === "error") {
                echo 'Error at productCart()';
                exit();
            }

        } else {
            $colCart = $this->session->get('col_products');
            $colDiscount = $this->session->get('col_discount');
            $orderInfoId = $this->session->get('order_info_id');
        }

        foreach ($colCart as $itemKey => $itemValue) {
            $price = $itemValue['price'];
            if($paymentType == 'money_and_points') {
                $price = strval($itemValue['priceByPointsPercentage']);
            }

            $name = $itemValue['name'];

            if($itemValue['color']) {
                $name .= ' | ' . $itemValue['color'];
            }

            if($itemValue['size']) {
                $name .= ' | ' . $itemValue['size'];
            }

            $products[] = [
                'referenceKey' => $itemValue['referenceKey'],
                'name' => $name,
                'price' => $price,
                'quantity' => $itemValue['quantity'],
                'currency' =>  $objLayout->getCurrencyCode()
            ];
        }

        $orderDeliveryMethodPrice = $this->session->get('order_delivery_method_price') ?? 0.00;

        return [
            'arPaymentCredentials' => $arPaymentCredentials,
            'colProducts' => $products,
            'colDiscount' => $colDiscount,
            'orderInfoId' => $orderInfoId,
            'orderDeliveryMethodPrice' => $orderDeliveryMethodPrice
        ];
    }



    function search($array, $key, $value)
    {
        $results = array();
        if (is_array($array)) {
            if (isset($array[$key]) && $array[$key] == $value) {
                $results[] = $array;
            }
            foreach ($array as $subarray) {
                $results = array_merge($results, $this->search($subarray, $key, $value));
            }
        }
        return $results;
    }
}
