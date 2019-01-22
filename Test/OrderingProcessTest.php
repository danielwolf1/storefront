<?php declare(strict_types=1);

namespace Shopware\Storefront\Test;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Read\ReadCriteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriterInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\StorefrontFunctionalTestBehaviour;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Response;

class OrderingProcessTest extends TestCase
{
    use StorefrontFunctionalTestBehaviour;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var EntityWriterInterface
     */
    private $entityWriter;

    public function setUp()
    {
        $this->connection = $this->getContainer()->get(Connection::class);
        $this->orderRepository = $this->getContainer()->get('order.repository');
        $this->entityWriter = $this->getContainer()->get(EntityWriter::class);
    }

    public function testOrderingProcess(): void
    {
        static::markTestSkipped('Storefront not fully implemented yet.');

        $email = Uuid::uuid4()->toString() . '@shopware.com';
        $customerId = $this->createCustomer($email, 'test1234');
        static::assertNotEmpty($customerId, 'Customer was not created.');

        $this->loginUser($email, 'test1234');

        $product1 = $this->createProduct('Shopware stickers', 10, 11.9, 19);
        $product2 = $this->createProduct('Shopware t-shirt', 20, 23.8, 19);
        $product3 = $this->createProduct('Shopware cup', 5, 5.95, 19);

        $this->addProductToCart($product1, 1);
        $this->addProductToCart($product2, 5);
        $this->addProductToCart($product3, 10);

        $this->changeProductQuantity($product3, 3);

        $this->removeProductFromCart($product2);

        $this->changePaymentMethod(Defaults::PAYMENT_METHOD_PAID_IN_ADVANCE);

        $orderId = $this->payOrder();
        self::assertTrue(Uuid::isValid($orderId));

        /** @var OrderEntity $order */
        $order = $this->orderRepository->read(new ReadCriteria([$orderId]), Context::createDefaultContext())
            ->get($orderId);

        self::assertEquals(Defaults::PAYMENT_METHOD_PAID_IN_ADVANCE, $order->getPaymentMethodId());
        self::assertEquals(25, $order->getAmountTotal());
        self::assertEquals($customerId, $order->getOrderCustomer()->getId());
    }

    private function createProduct(
        string $name,
        float $grossPrice,
        float $netPrice,
        float $taxRate
    ): string {
        $id = Uuid::uuid4()->getHex();

        $data = [
            'id' => $id,
            'name' => $name,
            'tax' => ['name' => 'test', 'rate' => $taxRate],
            'manufacturer' => ['name' => 'test'],
            'price' => ['gross' => $grossPrice, 'net' => $netPrice],
        ];

        $this->getClient()->request('POST', '/api/v' . PlatformRequest::API_VERSION . '/product', [], [], [], json_encode($data));
        $response = $this->getClient()->getResponse();

        /* @var Response $response */
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), $response->getContent());

        self::assertNotEmpty($response->headers->get('Location'));
        self::assertStringEndsWith('api/v' . PlatformRequest::API_VERSION . '/product/' . $id, $response->headers->get('Location'));

        return $id;
    }

    private function addProductToCart(string $id, int $quantity): void
    {
        $data = [
            'identifier' => $id,
            'quantity' => $quantity,
        ];

        $this->getStorefrontApiClient()->request('POST', '/cart/addProduct', $data);
        $response = $this->getStorefrontApiClient()->getResponse();

        static::assertEquals(200, $response->getStatusCode(), print_r($response->getContent(), true));

        $content = json_decode($response->getContent(), true);

        self::assertEquals(true, $content['success']);
    }

    private function changeProductQuantity(string $id, int $quantity): void
    {
        $data = [
            'identifier' => $id,
            'quantity' => $quantity,
        ];

        $this->getStorefrontApiClient()->request('POST', '/cart/setLineItemQuantity', $data);
        $response = $this->getStorefrontApiClient()->getResponse();
        $content = json_decode($response->getContent(), true);

        self::assertEquals(true, $content['success']);
    }

    private function removeProductFromCart(string $id): void
    {
        $data = [
            'identifier' => $id,
        ];

        $this->getStorefrontApiClient()->request('POST', '/cart/removeLineItem', $data);
        $response = $this->getStorefrontApiClient()->getResponse();
        $content = json_decode($response->getContent(), true);

        self::assertEquals(true, $content['success']);
    }

    private function createCustomer($email, $password): string
    {
        $customerId = Uuid::uuid4()->getHex();
        $addressId = Uuid::uuid4()->getHex();

        $customer = [
            'id' => $customerId,
            'customerNumber' => '1337',
            'salutation' => 'Herr',
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'email' => $email,
            'password' => $password,
            'defaultPaymentMethodId' => Defaults::PAYMENT_METHOD_INVOICE,
            'groupId' => Defaults::FALLBACK_CUSTOMER_GROUP,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'defaultBillingAddressId' => $addressId,
            'defaultShippingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'customerId' => $customerId,
                    'countryId' => 'ffe61e1c-9915-4f95-9701-4a310ab5482d',
                    'salutation' => 'Herr',
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                ],
            ],
        ];

        $this->entityWriter->upsert(
            CustomerDefinition::class,
            [$customer],
            WriteContext::createFromContext(Context::createDefaultContext())
        );

        return $customerId;
    }

    private function loginUser(string $email, string $password): void
    {
        $data = [
            'email' => $email,
            'password' => $password,
        ];

        $this->getStorefrontApiClient()->request('POST', '/account/login', $data);

        /** @var Response $response */
        $response = $this->getStorefrontApiClient()->getResponse();

        static::assertStringEndsWith('/account', (string) $response->headers->get('Location'), $response->getContent());
    }

    private function changePaymentMethod(string $paymentMethodId): void
    {
        $data = [
            'paymentMethodId' => $paymentMethodId,
        ];

        $this->getStorefrontApiClient()->request('POST', '/checkout/saveShippingPayment', $data);

        /** @var Response $response */
        $response = $this->getStorefrontApiClient()->getResponse();
        static::assertStringEndsWith('/checkout/confirm', $response->headers->get('Location'));
    }

    private function payOrder(): string
    {
        $data = [
            'sAGB' => 'on',
        ];

        $this->getStorefrontApiClient()->request('POST', '/checkout/pay', $data);

        /** @var Response $response */
        $response = $this->getStorefrontApiClient()->getResponse();

        return $this->getOrderIdByResponse($response);
    }

    private function getOrderIdByResponse(Response $response): string
    {
        static::assertTrue($response->headers->has('location'), print_r($response->getContent(), true));
        $location = $response->headers->get('location');
        $query = parse_url($location, PHP_URL_QUERY);
        $parsedQuery = [];
        parse_str($query, $parsedQuery);

        return $parsedQuery['order'];
    }
}
