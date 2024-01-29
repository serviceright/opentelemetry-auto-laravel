<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\ServeCommand;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\CacheWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ExceptionWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\QueryWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\Watcher;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;
use OpenTelemetry\API\Globals;
use Monolog\Logger;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SemConv\ResourceAttributes;

use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;

use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use Psr\Log\LogLevel;

class LaravelInstrumentation
{
    public const NAME = 'laravel';

    public static function registerWatchers(Application $app, Watcher $watcher)
    {
        $watcher->register($app);
    }

    /**
     * Lets Setup the global tracer
     * @return void
     */
    public static function setup()
    {
        // the endpoint
        if(!isset($_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'])) {
            return;
        }

        // the custom headers
        if(!isset($_ENV['OTEL_EXPORTER_OTLP_HEADERS'])) {
            return;
        }

        $dtMetadata = [];

        $dynatraceFiles = [
            '/var/lib/dynatrace/enrichment/dt_metadata.properties',
            'dt_metadata_e617c525669e072eebe3d0f08212e8f2.properties',
            '/var/lib/dynatrace/enrichment/dt_host_metadata.properties'
        ];

        foreach ($dynatraceFiles as $filePath) {
            if (file_exists($filePath)) {
                $props = str_starts_with($filePath, '/var/') ? parse_ini_file($filePath) : parse_ini_file(trim(file_get_contents($filePath)));
                $dtMetadata = array_merge($dtMetadata, $props);
            }
        }

        // handle any custom headers that we might have
        $lines = explode("\n", $_ENV['OTEL_EXPORTER_OTLP_HEADERS']);
        $customHeaders = [];

        // Process each line
        foreach ($lines as $line) {
            // Split the line into key and value
            list($key, $value) = explode("=", $line, 2);
            // Add the key-value pair to the array
            $customHeaders[$key] = $value;
        }


        // basic resource information
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                $dtMetadata,
                ResourceAttributes::SERVICE_NAME => env('APP_NAME'),
                ResourceAttributes::DEPLOYMENT_ENVIRONMENT => env('APP_ENV'),
            ])));

        $tracesEndpoint = $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'] . '/v1/traces';
        $metricsEndpoint = $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'] . '/v1/metrics';
        $logsEndpoint = $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'] . '/v1/logs';

        // content type
        $contentType = 'application/x-protobuf';


        // ===== TRACING SETUP =====
        $transport = (new OtlpHttpTransportFactory())->create($tracesEndpoint, $contentType, $customHeaders);
        $exporter = new SpanExporter($transport);
        $tracerProvider =  new TracerProvider(new SimpleSpanProcessor($exporter), null, $resource);

        // ===== METRIC SETUP =====
        $metricsTransport = (new OtlpHttpTransportFactory())->create($metricsEndpoint, $contentType, $customHeaders);
        $reader = new ExportingReader(new MetricExporter($metricsTransport));
        $metricsProvider = MeterProvider::builder()->setResource($resource)->addReader($reader)->build();

        // ===== LOG SETUP =====
//        $transport = (new OtlpHttpTransportFactory())->create($logsEndpoint, $contentType, $customHeaders);
//        $exporter = new LogsExporter($transport);

        // ===== REGISTRATION =====
        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setMeterProvider($metricsProvider)
//            ->setLoggerProvider($loggerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

    }

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.laravel');

        hook(
            Application::class,
            '__construct',
            post: static function (Application $application, array $params, mixed $returnValue, ?Throwable $exception) use ($instrumentation) {
                self::registerWatchers($application, new CacheWatcher());
                self::registerWatchers($application, new ClientRequestWatcher($instrumentation));
                self::registerWatchers($application, new ExceptionWatcher());
                self::registerWatchers($application, new LogWatcher());
                self::registerWatchers($application, new QueryWatcher($instrumentation));
            },
        );

        ConsoleInstrumentation::register($instrumentation);
        HttpInstrumentation::register($instrumentation);

        self::developmentInstrumentation();
    }

    private static function developmentInstrumentation(): void
    {
        // Allow instrumentation when using the local PHP development server.
        if (class_exists(ServeCommand::class) && property_exists(ServeCommand::class, 'passthroughVariables')) {
            hook(
                ServeCommand::class,
                'handle',
                pre: static function (ServeCommand $serveCommand, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                    foreach ($_ENV as $key => $value) {
                        if (str_starts_with($key, 'OTEL_') && !in_array($key, ServeCommand::$passthroughVariables)) {
                            ServeCommand::$passthroughVariables[] = $key;
                        }
                    }
                },
            );
        }
    }


}
