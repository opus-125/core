<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Opus125\AuditBundle\Model\AuditEntry;
use Opus125\AuditBundle\Opus125AuditBundle;
use Opus125\AuditBundle\Tests\Fixtures\Customer;
use Opus125\AuditBundle\Tests\Fixtures\Invoice;
use Opus125\AuditBundle\Tests\Fixtures\Tag;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Proves the standard, zero-glue integration: a real Symfony kernel with
 * DoctrineBundle + Opus125AuditBundle records an audit entry on flush, with the
 * entity mappings and the onFlush listener wired automatically — no manual
 * EntityManager and no manual listener registration.
 */
final class DoctrineBundleIntegrationTest extends TestCase
{
    private ?AuditKernel $kernel = null;

    /** @var callable|null */
    private $baselineExceptionHandler;
    /** @var callable|null */
    private $baselineErrorHandler;

    protected function setUp(): void
    {
        $this->baselineExceptionHandler = set_exception_handler(null);
        restore_exception_handler();
        $this->baselineErrorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            new Filesystem()->remove($cacheDir);
            $this->kernel = null;
        }

        $this->restoreHandlers();
    }

    public function testAuditEntryIsRecordedThroughTheAutoWiredListener(): void
    {
        $container = $this->boot();

        $em = $container->get('opus125_test.em');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->resetSchema($em);

        $customer = new Customer('Müller GmbH');
        $invoice = new Invoice($customer, 'Müller GmbH');
        $em->persist($customer);
        $em->persist($invoice);
        $em->flush();

        $em->clear();
        $entries = $em->getRepository(AuditEntry::class)->findBy(['stream' => 'rechnung']);

        self::assertCount(1, $entries, 'the auto-registered onFlush listener must have recorded the change');
        self::assertSame('create', $entries[0]->getAction());
    }

    private function boot(): ContainerInterface
    {
        $this->kernel = new AuditKernel('test', false);

        try {
            $this->kernel->boot();
            $em = $this->kernel->getContainer()->get('opus125_test.em');
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL is not available: '.$e->getMessage());
        }

        return $this->kernel->getContainer();
    }

    private function restoreHandlers(): void
    {
        for ($i = 0; $i < 16; ++$i) {
            $current = set_exception_handler(null);
            restore_exception_handler();
            if ($current === $this->baselineExceptionHandler) {
                break;
            }
            restore_exception_handler();
        }

        for ($i = 0; $i < 16; ++$i) {
            $current = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            if ($current === $this->baselineErrorHandler) {
                break;
            }
            restore_error_handler();
        }
    }

    private function resetSchema(EntityManagerInterface $em): void
    {
        $tool = new SchemaTool($em);
        $classes = array_map($em->getClassMetadata(...), [
            AuditEntry::class, Customer::class, Invoice::class, Tag::class,
        ]);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}

/**
 * Minimal kernel wiring FrameworkBundle + DoctrineBundle + Opus125AuditBundle.
 */
final class AuditKernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new Opus125AuditBundle()];
    }

    public function getProjectDir(): string
    {
        return $this->getCacheDir();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/opus125_audit_func_'.spl_object_id($this);
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $dsn = getenv('OPUS125_AUDIT_TEST_DSN') ?: 'postgresql://postgres@127.0.0.1:5432/opus125_audit_test';

        $container->extension('framework', [
            'secret' => 'test-secret',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => false],
            'router' => ['utf8' => true, 'resource' => 'kernel::loadRoutes', 'type' => 'service'],
        ]);

        $container->extension('doctrine', [
            'dbal' => ['url' => $dsn, 'server_version' => '16'],
            'orm' => [
                'mappings' => [
                    'Fixtures' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__).'/Fixtures',
                        'prefix' => 'Opus125\\AuditBundle\\Tests\\Fixtures',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $container->extension('opus125_audit', []);

        $container->services()->alias('opus125_test.em', EntityManagerInterface::class)->public();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
